<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Reseller API keys. Only a SHA-256 hash is stored; the plain key is shown to
 * the user once when generated (like modern SaaS platforms).
 */
final class ApiKeyService
{
    public static function generate(int $userId): string
    {
        $db = Database::instance();
        $key = bin2hex(random_bytes(20)); // 40 hex chars, familiar format for SMM API clients
        $db->transaction(static function (Database $db) use ($userId, $key): void {
            $db->query("UPDATE api_keys SET status = 'revoked' WHERE user_id = ? AND status = 'active'", [$userId]);
            $db->insert('api_keys', [
                'user_id' => $userId,
                'key_hash' => hash('sha256', $key),
                'key_prefix' => substr($key, 0, 8),
                'status' => 'active',
                'created_at' => now(),
            ]);
        });
        AuditService::log('api_key.generated', 'user', $userId);
        return $key;
    }

    public static function active(int $userId): ?array
    {
        return Database::instance()->fetch("SELECT * FROM api_keys WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$userId]);
    }

    public static function revoke(int $userId): void
    {
        Database::instance()->query("UPDATE api_keys SET status = 'revoked' WHERE user_id = ? AND status = 'active'", [$userId]);
        AuditService::log('api_key.revoked', 'user', $userId);
    }

    /** Resolve an API key to an active user, or null. */
    public static function authenticate(string $key, string $ip): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $key)) {
            return null;
        }
        $db = Database::instance();
        $row = $db->fetch(
            "SELECT k.id AS key_id, u.* FROM api_keys k JOIN users u ON u.id = k.user_id
             WHERE k.key_hash = ? AND k.status = 'active' AND u.status = 'active' AND u.deleted_at IS NULL AND u.api_enabled = 1",
            [hash('sha256', $key)]
        );
        if ($row) {
            $db->update('api_keys', ['last_used_at' => now(), 'last_used_ip' => $ip], ['id' => $row['key_id']]);
        }
        return $row;
    }
}
