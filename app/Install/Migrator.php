<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Database;

/**
 * Idempotent schema upgrades for installations created from an older
 * database/schema.sql. Each step checks information_schema before altering,
 * so it works on MySQL 5.7/8.x and MariaDB and can safely be re-run.
 *
 * Run with:  php database/migrate.php   (or Admin → System health → Run migrations)
 */
final class Migrator
{
    /** @return array<string, callable(Database):void> version => step */
    private static function steps(): array
    {
        return [
            '2026_10_01_p2gateway_payments' => static function (Database $db): void {
                $cols = [
                    'merchant_order_id' => 'ADD COLUMN merchant_order_id VARCHAR(64) NULL AFTER gateway_ref',
                    'verified_amount' => 'ADD COLUMN verified_amount DECIMAL(18,4) NULL AFTER bonus_amount',
                    'utr' => 'ADD COLUMN utr VARCHAR(100) NULL AFTER verified_amount',
                    'needs_review' => 'ADD COLUMN needs_review TINYINT(1) NOT NULL DEFAULT 0 AFTER utr',
                ];
                foreach ($cols as $col => $ddl) {
                    if (!self::columnExists($db, 'payments', $col)) {
                        $db->pdo()->exec('ALTER TABLE payments ' . $ddl);
                    }
                }
                if (!self::indexExists($db, 'payments', 'uq_pay_merchant_order')) {
                    $db->pdo()->exec('ALTER TABLE payments ADD UNIQUE KEY uq_pay_merchant_order (merchant_order_id)');
                }
                // The placeholder text shipped for P2Gateway is no longer accurate.
                $db->query(
                    "UPDATE payment_methods SET name = 'UPI (P2Gateway)', instructions = ? WHERE gateway = 'p2gateway' AND instructions LIKE 'Placeholder%'",
                    [Seeder::P2GATEWAY_INSTRUCTIONS]
                );
            },
            '2026_10_05_subscriptions_currency_levels' => static function (Database $db): void {
                // --- Auto-subscriptions (recurring orders placed by cron through the normal order engine)
                if (!self::columnExists($db, 'services', 'subscription_enabled')) {
                    $db->pdo()->exec('ALTER TABLE services ADD COLUMN subscription_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER dripfeed');
                }
                $db->pdo()->exec(self::SUBSCRIPTIONS_DDL);
                $db->pdo()->exec(self::SUBSCRIPTION_LOGS_DDL);
                if (!self::columnExists($db, 'orders', 'subscription_id')) {
                    $db->pdo()->exec('ALTER TABLE orders ADD COLUMN subscription_id BIGINT UNSIGNED NULL AFTER idempotency_key, ADD COLUMN subscription_cycle SMALLINT UNSIGNED NULL AFTER subscription_id, ADD KEY idx_order_sub (subscription_id, subscription_cycle)');
                }
                $type = (string) $db->fetchColumn("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'source'");
                if (!str_contains($type, "'subscription'")) {
                    $db->pdo()->exec("ALTER TABLE orders MODIFY source ENUM('web','mass','api','admin','subscription') NOT NULL DEFAULT 'web'");
                }

                // --- Registration: optional mobile number; per-user display currency
                if (!self::columnExists($db, 'users', 'mobile')) {
                    $db->pdo()->exec('ALTER TABLE users ADD COLUMN mobile VARCHAR(20) NULL AFTER email');
                }
                if (!self::columnExists($db, 'users', 'email_changed_at')) {
                    $db->pdo()->exec('ALTER TABLE users ADD COLUMN email_changed_at DATETIME NULL AFTER email_verified_at');
                }
                if (!self::columnExists($db, 'users', 'currency')) {
                    $db->pdo()->exec('ALTER TABLE users ADD COLUMN currency CHAR(3) NULL AFTER timezone');
                }

                // --- Price levels by lifetime credited deposits. Levels an admin already
                // assigned by hand are kept (marked manual); everyone else becomes automatic.
                if (!self::columnExists($db, 'users', 'price_level_manual')) {
                    $db->pdo()->exec('ALTER TABLE users ADD COLUMN price_level_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER price_level_id');
                    $db->query('UPDATE users SET price_level_manual = 1 WHERE price_level_id IS NOT NULL');
                }
                if (!self::columnExists($db, 'price_levels', 'description')) {
                    $db->pdo()->exec('ALTER TABLE price_levels ADD COLUMN description VARCHAR(500) NULL AFTER name');
                }
                // NULL threshold = manual-only level. Existing levels stay manual-only until
                // the admin sets a threshold, so nobody is auto-promoted by the upgrade.
                if (!self::columnExists($db, 'price_levels', 'min_deposit')) {
                    $db->pdo()->exec('ALTER TABLE price_levels ADD COLUMN min_deposit DECIMAL(18,4) NULL DEFAULT NULL AFTER discount_percent');
                }
                // wallets.total_deposits is the qualifying amount: re-derive it from the
                // ledger (credited deposits only) so thresholds start from exact values.
                $db->query("UPDATE wallets w SET total_deposits = (SELECT COALESCE(SUM(t.amount), 0) FROM transactions t WHERE t.user_id = w.user_id AND t.type = 'deposit' AND t.wallet = 'main')");

                // --- Additional display currencies. The site currency (settings) stays the
                // base/accounting currency and is never stored here, so it cannot drift.
                $db->pdo()->exec(self::CURRENCIES_DDL);

                // --- New settings with safe defaults (existing values are never overwritten)
                foreach (['registration_mobile' => '0', 'registration_mobile_required' => '0', 'subscriptions_enabled' => '1', 'subscription_max_cycles' => '100', 'currency_switch_enabled' => '1'] as $k => $v) {
                    $db->query('INSERT IGNORE INTO settings (`key`, `value`, is_secret, updated_at) VALUES (?, ?, 0, ?)', [$k, $v, now()]);
                }
            },
            '2026_10_12_subscription_type_oauth_gateway_limits' => static function (Database $db): void {
                // --- "Subscriptions" service type (panel-side recurring deliveries) + per-service settings
                $type = (string) $db->fetchColumn("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'services' AND column_name = 'type'");
                if (!str_contains($type, "'subscription'")) {
                    $db->pdo()->exec("ALTER TABLE services MODIFY type ENUM('default','package','custom_comments','custom_comments_package','mentions_custom_list','comment_likes','poll','keywords','subscription') NOT NULL DEFAULT 'default'");
                }
                $cols = [
                    'subscription_intervals' => 'ADD COLUMN subscription_intervals VARCHAR(100) NULL AFTER subscription_enabled',
                    'subscription_min_cycles' => 'ADD COLUMN subscription_min_cycles SMALLINT UNSIGNED NULL AFTER subscription_intervals',
                    'subscription_max_cycles' => 'ADD COLUMN subscription_max_cycles SMALLINT UNSIGNED NULL AFTER subscription_min_cycles',
                ];
                foreach ($cols as $col => $ddl) {
                    if (!self::columnExists($db, 'services', $col)) {
                        $db->pdo()->exec('ALTER TABLE services ' . $ddl);
                    }
                }

                // --- Providers: "syncing" state for catalog/price sync
                if (!self::columnExists($db, 'providers', 'syncing_since')) {
                    $db->pdo()->exec('ALTER TABLE providers ADD COLUMN syncing_since DATETIME NULL AFTER last_synced_at');
                }

                // --- Sign in with Google + remember me
                if (!self::columnExists($db, 'users', 'password_set')) {
                    // Existing accounts all chose a password; Google-created accounts start without one.
                    $db->pdo()->exec('ALTER TABLE users ADD COLUMN password_set TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash');
                }
                $db->pdo()->exec(self::SOCIAL_ACCOUNTS_DDL);
                $db->pdo()->exec(self::REMEMBER_TOKENS_DDL);

                // --- Registration: "Mobile number ON" now means required unless "optional" is ticked.
                // Installations that already showed the field as optional keep that behaviour.
                $mobileOn = $db->fetchColumn("SELECT `value` FROM settings WHERE `key` = 'registration_mobile'") === '1';
                $wasRequired = $db->fetchColumn("SELECT `value` FROM settings WHERE `key` = 'registration_mobile_required'") === '1';
                $db->query("INSERT IGNORE INTO settings (`key`, `value`, is_secret, updated_at) VALUES ('registration_mobile_optional', ?, 0, ?)", [$mobileOn && !$wasRequired ? '1' : '0', now()]);
                foreach (['google_login_enabled' => '0', 'google_client_id' => ''] as $k => $v) {
                    $db->query('INSERT IGNORE INTO settings (`key`, `value`, is_secret, updated_at) VALUES (?, ?, 0, ?)', [$k, $v, now()]);
                }

                // --- Deposit limits move from the global setting to each payment gateway.
                // Each gateway keeps the limit that was effectively enforced: max(global min, gateway min)
                // and min(global max, gateway max). Runs once (guarded by a settings flag).
                if ($db->fetchColumn("SELECT `value` FROM settings WHERE `key` = 'deposit_limits_per_gateway'") !== '1') {
                    // Fallbacks mirror what PaymentService used when the settings rows were absent.
                    $gMin = (string) ($db->fetchColumn("SELECT `value` FROM settings WHERE `key` = 'min_deposit'") ?: '1');
                    $gMax = (string) ($db->fetchColumn("SELECT `value` FROM settings WHERE `key` = 'max_deposit'") ?: '100000');
                    if (\App\Core\Money::isNumeric($gMin)) {
                        $db->query('UPDATE payment_methods SET min_amount = GREATEST(min_amount, ?)', [$gMin]);
                    }
                    if (\App\Core\Money::isNumeric($gMax) && \App\Core\Money::isPositive($gMax)) {
                        $db->query('UPDATE payment_methods SET max_amount = LEAST(max_amount, ?)', [$gMax]);
                        $db->query('UPDATE payment_methods SET max_amount = min_amount WHERE max_amount < min_amount');
                    }
                    $db->query("INSERT INTO settings (`key`, `value`, is_secret, updated_at) VALUES ('deposit_limits_per_gateway', '1', 0, ?) ON DUPLICATE KEY UPDATE `value` = '1', updated_at = VALUES(updated_at)", [now()]);
                }
            },
            '2026_10_20_post_subscriptions_price_protection_gateway_bonus' => static function (Database $db): void {
                $add = static function (string $table, array $cols) use ($db): void {
                    foreach ($cols as $col => $ddl) {
                        if (!self::columnExists($db, $table, $col)) {
                            $db->pdo()->exec("ALTER TABLE {$table} ADD COLUMN {$ddl}");
                        }
                    }
                };
                // --- Post-based subscriptions (username / new & old posts / min-max / delay / expiry)
                $add('services', [
                    'subscription_mode' => "subscription_mode ENUM('scheduled','posts') NOT NULL DEFAULT 'scheduled' AFTER subscription_enabled",
                    'subscription_delays' => 'subscription_delays VARCHAR(200) NULL AFTER subscription_max_cycles',
                    'subscription_max_expiry_days' => 'subscription_max_expiry_days SMALLINT UNSIGNED NULL AFTER subscription_delays',
                    'subscription_old_posts_max' => 'subscription_old_posts_max SMALLINT UNSIGNED NULL AFTER subscription_max_expiry_days',
                    // Provider price protection flags (set by ProviderSyncService, cleared when safe again)
                    'price_blocked' => 'price_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_sync',
                    'price_disabled' => 'price_disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER price_blocked',
                ]);
                $add('subscriptions', [
                    'mode' => "mode ENUM('scheduled','posts') NOT NULL DEFAULT 'scheduled' AFTER service_id",
                    'qty_min' => 'qty_min INT UNSIGNED NULL AFTER quantity',
                    'old_posts' => 'old_posts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER qty_min',
                    'delay_minutes' => 'delay_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER old_posts',
                    'expires_at' => 'expires_at DATETIME NULL AFTER delay_minutes',
                    'rate' => 'rate DECIMAL(18,6) NULL AFTER expires_at',
                    'cost_rate' => 'cost_rate DECIMAL(18,6) NULL AFTER rate',
                    'prepaid' => 'prepaid DECIMAL(18,6) NULL AFTER cost_rate',
                    'final_charge' => 'final_charge DECIMAL(18,6) NULL AFTER prepaid',
                    'refunded' => 'refunded DECIMAL(18,6) NOT NULL DEFAULT 0.000000 AFTER final_charge',
                    'provider_status' => 'provider_status VARCHAR(40) NULL AFTER refunded',
                ]);
                $type = (string) $db->fetchColumn("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'subscriptions' AND column_name = 'status'");
                if (!str_contains($type, "'expired'")) {
                    $db->pdo()->exec("ALTER TABLE subscriptions MODIFY status ENUM('active','paused','completed','cancelled','suspended','expired','failed') NOT NULL DEFAULT 'active'");
                }
                // --- Provider price protection history
                $db->pdo()->exec(self::PRICE_EVENTS_DDL);
                // --- Deposit bonus per payment gateway (snapshotted on each payment / manual request)
                $add('payment_methods', [
                    'bonus_percent' => 'bonus_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER fee_percent',
                    'bonus_fixed' => 'bonus_fixed DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER bonus_percent',
                    'bonus_min_amount' => 'bonus_min_amount DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER bonus_fixed',
                ]);
                foreach (['payments' => 'bonus_amount', 'manual_payment_requests' => 'coupon_id'] as $table => $after) {
                    $add($table, [
                        'gw_bonus_percent' => "gw_bonus_percent DECIMAL(5,2) NULL AFTER {$after}",
                        'gw_bonus_fixed' => 'gw_bonus_fixed DECIMAL(18,4) NULL AFTER gw_bonus_percent',
                        'gw_bonus_min' => 'gw_bonus_min DECIMAL(18,4) NULL AFTER gw_bonus_fixed',
                    ]);
                }
                $add('payments', ['gw_bonus_amount' => 'gw_bonus_amount DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER gw_bonus_min']);
                // --- Category platform (order-page shortcuts), filled once from the category name
                $add('categories', ['platform' => 'platform VARCHAR(20) NULL AFTER slug']);
                foreach ($db->fetchAll('SELECT id, name FROM categories WHERE platform IS NULL') as $c) {
                    $db->update('categories', ['platform' => \App\Helpers\Platforms::detect((string) $c['name'])], ['id' => $c['id']]);
                }
                foreach ([
                    'price_protection_mode' => 'protect', 'price_protection_margin' => '0',
                    'recaptcha_enabled' => '0', 'recaptcha_version' => 'v2', 'recaptcha_site_key' => '', 'recaptcha_min_score' => '0.5',
                    'social_tiktok' => '',
                ] as $k => $v) {
                    $db->query('INSERT IGNORE INTO settings (`key`, `value`, is_secret, updated_at) VALUES (?, ?, 0, ?)', [$k, $v, now()]);
                }
            },
            '2026_10_27_platforms_coupon_gateways_blog_seo' => static function (Database $db): void {
                $add = static function (string $table, array $cols) use ($db): void {
                    foreach ($cols as $col => $ddl) {
                        if (!self::columnExists($db, $table, $col)) {
                            $db->pdo()->exec("ALTER TABLE {$table} ADD COLUMN {$ddl}");
                        }
                    }
                };
                // --- Platform management (Admin → Platforms): every known platform ON, default shortcuts
                $db->pdo()->exec(self::PLATFORMS_DDL);
                Seeder::seedPlatforms($db);
                // --- Promo codes per payment gateway. Existing codes keep working on every online gateway.
                $add('coupons', ['all_gateways' => 'all_gateways TINYINT(1) NOT NULL DEFAULT 1 AFTER status']);
                $db->pdo()->exec(self::COUPON_GATEWAYS_DDL);
                // --- Blog SEO: focus keyword, canonical, OG image, robots; old slugs keep redirecting
                $add('blog_posts', [
                    'seo_keyword' => 'seo_keyword VARCHAR(100) NULL AFTER seo_description',
                    'canonical_url' => 'canonical_url VARCHAR(500) NULL AFTER seo_keyword',
                    'og_image' => 'og_image VARCHAR(255) NULL AFTER canonical_url',
                    'robots_index' => 'robots_index TINYINT(1) NOT NULL DEFAULT 1 AFTER og_image',
                    'robots_follow' => 'robots_follow TINYINT(1) NOT NULL DEFAULT 1 AFTER robots_index',
                ]);
                $db->pdo()->exec(self::BLOG_REDIRECTS_DDL);
            },
        ];
    }

    public const PLATFORMS_DDL = "CREATE TABLE IF NOT EXISTS platforms (
  `key`       VARCHAR(20) NOT NULL,
  name        VARCHAR(60) NOT NULL,
  status      ENUM('active','disabled') NOT NULL DEFAULT 'active',
  shortcut    TINYINT(1) NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    public const COUPON_GATEWAYS_DDL = "CREATE TABLE IF NOT EXISTS coupon_payment_methods (
  coupon_id          INT UNSIGNED NOT NULL,
  payment_method_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (coupon_id, payment_method_id),
  KEY idx_cpm_method (payment_method_id),
  CONSTRAINT fk_cpm_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
  CONSTRAINT fk_cpm_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    public const BLOG_REDIRECTS_DDL = "CREATE TABLE IF NOT EXISTS blog_slug_redirects (
  old_slug    VARCHAR(240) NOT NULL,
  post_id     INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (old_slug),
  KEY idx_bsr_post (post_id),
  CONSTRAINT fk_bsr_post FOREIGN KEY (post_id) REFERENCES blog_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    public const PRICE_EVENTS_DDL = "CREATE TABLE IF NOT EXISTS service_price_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id   INT UNSIGNED NOT NULL,
  provider_id  INT UNSIGNED NULL,
  old_cost     DECIMAL(18,6) NULL,
  new_cost     DECIMAL(18,6) NULL,
  old_rate     DECIMAL(18,6) NULL,
  new_rate     DECIMAL(18,6) NULL,
  safe_rate    DECIMAL(18,6) NULL,
  action       VARCHAR(20) NOT NULL,
  mode         VARCHAR(20) NOT NULL,
  message      VARCHAR(500) NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_spe_service (service_id, id),
  KEY idx_spe_created (created_at),
  CONSTRAINT fk_spe_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    public const SOCIAL_ACCOUNTS_DDL = "CREATE TABLE IF NOT EXISTS user_social_accounts (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED NOT NULL,
  provider          VARCHAR(20) NOT NULL,
  provider_user_id  VARCHAR(191) NOT NULL,
  email             VARCHAR(190) NULL,
  created_at        DATETIME NOT NULL,
  last_login_at     DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_social_provider_uid (provider, provider_user_id),
  UNIQUE KEY uq_social_user_provider (user_id, provider),
  CONSTRAINT fk_social_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    public const REMEMBER_TOKENS_DDL = "CREATE TABLE IF NOT EXISTS remember_tokens (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,
  selector         CHAR(24) NOT NULL,
  token_hash       CHAR(64) NOT NULL,
  session_version  INT UNSIGNED NOT NULL,
  user_agent       VARCHAR(255) NULL,
  expires_at       DATETIME NOT NULL,
  created_at       DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_remember_selector (selector),
  KEY idx_remember_user (user_id),
  KEY idx_remember_expires (expires_at),
  CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    public const SUBSCRIPTIONS_DDL = "CREATE TABLE IF NOT EXISTS subscriptions (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED NOT NULL,
  service_id        INT UNSIGNED NOT NULL,
  link              VARCHAR(1000) NOT NULL,
  quantity          INT UNSIGNED NOT NULL,
  extra             TEXT NULL,
  interval_hours    SMALLINT UNSIGNED NOT NULL,
  total_cycles      SMALLINT UNSIGNED NOT NULL,
  completed_cycles  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status            ENUM('active','paused','completed','cancelled','suspended') NOT NULL DEFAULT 'active',
  next_run_at       DATETIME NULL,
  locked_until      DATETIME NULL,
  attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error        VARCHAR(500) NULL,
  last_order_id     BIGINT UNSIGNED NULL,
  last_run_at       DATETIME NULL,
  idempotency_key   VARCHAR(64) NULL,
  cancelled_at      DATETIME NULL,
  completed_at      DATETIME NULL,
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sub_idem (user_id, idempotency_key),
  KEY idx_sub_due (status, next_run_at),
  KEY idx_sub_user (user_id, created_at),
  KEY idx_sub_service (service_id),
  CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    public const SUBSCRIPTION_LOGS_DDL = "CREATE TABLE IF NOT EXISTS subscription_logs (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subscription_id  BIGINT UNSIGNED NOT NULL,
  event            VARCHAR(40) NOT NULL,
  cycle            SMALLINT UNSIGNED NULL,
  order_id         BIGINT UNSIGNED NULL,
  message          VARCHAR(500) NULL,
  actor            VARCHAR(40) NOT NULL DEFAULT 'system',
  created_at       DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sublog_sub (subscription_id, id),
  CONSTRAINT fk_sublog_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    public const CURRENCIES_DDL = "CREATE TABLE IF NOT EXISTS currencies (
  code        CHAR(3) NOT NULL,
  name        VARCHAR(60) NOT NULL,
  symbol      VARCHAR(8) NOT NULL,
  position    ENUM('before','after') NOT NULL DEFAULT 'before',
  decimals    TINYINT UNSIGNED NOT NULL DEFAULT 2,
  rate        DECIMAL(20,8) NOT NULL DEFAULT 1.00000000,
  enabled     TINYINT(1) NOT NULL DEFAULT 1,
  sort_order  INT NOT NULL DEFAULT 0,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    public static function ensureTable(Database $db): void
    {
        $db->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) NOT NULL, applied_at DATETIME NOT NULL, PRIMARY KEY (version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** @return list<string> versions not yet applied */
    public static function pending(): array
    {
        $db = Database::instance();
        self::ensureTable($db);
        $done = array_column($db->fetchAll('SELECT version FROM schema_migrations'), 'version');
        return array_values(array_diff(array_keys(self::steps()), $done));
    }

    /** @return list<string> versions applied now */
    public static function run(): array
    {
        $db = Database::instance();
        self::ensureTable($db);
        $applied = [];
        foreach (self::steps() as $version => $step) {
            if ($db->fetchColumn('SELECT version FROM schema_migrations WHERE version = ?', [$version])) {
                continue;
            }
            $step($db); // DDL auto-commits in MySQL; each step is idempotent instead of transactional
            $db->insert('schema_migrations', ['version' => $version, 'applied_at' => now()]);
            $applied[] = $version;
        }
        return $applied;
    }

    /** Fresh installs already have the latest schema: record all steps as applied. */
    public static function markAllApplied(): void
    {
        $db = Database::instance();
        self::ensureTable($db);
        foreach (array_keys(self::steps()) as $version) {
            $db->query('INSERT IGNORE INTO schema_migrations (version, applied_at) VALUES (?, ?)', [$version, now()]);
        }
    }

    private static function columnExists(Database $db, string $table, string $column): bool
    {
        return (bool) $db->fetchColumn('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $column]);
    }

    private static function indexExists(Database $db, string $table, string $index): bool
    {
        return (bool) $db->fetchColumn('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$table, $index]);
    }
}
