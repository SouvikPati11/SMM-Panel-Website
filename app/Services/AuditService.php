<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;
use App\Core\Logger;

/** Append-only audit trail of administrative and security-relevant actions. */
final class AuditService
{
    public static function log(string $action, ?string $targetType = null, int|string|null $targetId = null, array $details = [], ?string $actorType = null, ?int $actorId = null): void
    {
        if ($actorType === null) {
            if (Auth::admin()) {
                $actorType = 'admin';
                $actorId = Auth::adminId();
            } elseif (Auth::user()) {
                $actorType = 'user';
                $actorId = Auth::id();
            } else {
                $actorType = 'system';
            }
        }
        try {
            Database::instance()->insert('audit_logs', [
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'action' => substr($action, 0, 80),
                'target_type' => $targetType,
                'target_id' => $targetId !== null ? (string) $targetId : null,
                'details' => $details ? json_encode(Logger::redact($details), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'ip' => App::request()?->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Audit log write failed: ' . $e->getMessage());
        }
    }
}
