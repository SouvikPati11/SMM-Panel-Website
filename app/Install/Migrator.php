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
        ];
    }

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
