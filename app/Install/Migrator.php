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
        ];
    }

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
