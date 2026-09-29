<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Session;

/**
 * Session guards. Users and administrators are completely separate accounts
 * with separate session keys; being logged in as one never grants the other.
 * A per-account session_version lets us invalidate all sessions (password
 * change, "log out everywhere", suspension).
 */
final class Auth
{
    private static array|false|null $user = null;
    private static array|false|null $admin = null;
    private static ?array $permissions = null;

    public static function user(): ?array
    {
        if (self::$user === null) {
            self::$user = false;
            $id = (int) ($_SESSION['user_id'] ?? 0);
            if ($id > 0) {
                $row = Database::instance()->fetch(
                    'SELECT u.*, w.balance, w.referral_balance, w.total_deposits, w.total_spent FROM users u JOIN wallets w ON w.user_id = u.id WHERE u.id = ? AND u.deleted_at IS NULL',
                    [$id]
                );
                if ($row && $row['status'] === 'active' && (int) $row['session_version'] === (int) ($_SESSION['user_sv'] ?? -1)) {
                    self::$user = $row;
                } else {
                    unset($_SESSION['user_id'], $_SESSION['user_sv']);
                }
            }
        }
        return self::$user ?: null;
    }

    public static function id(): ?int
    {
        return isset(self::user()['id']) ? (int) self::user()['id'] : null;
    }

    public static function loginUser(array $user): void
    {
        Session::regenerate();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_sv'] = (int) $user['session_version'];
        self::$user = null;
    }

    public static function logoutUser(): void
    {
        unset($_SESSION['user_id'], $_SESSION['user_sv'], $_SESSION['2fa_pending_user']);
        Session::regenerate();
        self::$user = null;
    }

    public static function refreshUser(): void
    {
        self::$user = null;
    }

    public static function admin(): ?array
    {
        if (self::$admin === null) {
            self::$admin = false;
            $id = (int) ($_SESSION['admin_id'] ?? 0);
            if ($id > 0) {
                $row = Database::instance()->fetch('SELECT * FROM admins WHERE id = ?', [$id]);
                if ($row && $row['status'] === 'active' && (int) $row['session_version'] === (int) ($_SESSION['admin_sv'] ?? -1)) {
                    self::$admin = $row;
                } else {
                    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
                }
            }
        }
        return self::$admin ?: null;
    }

    public static function adminId(): ?int
    {
        return isset(self::admin()['id']) ? (int) self::admin()['id'] : null;
    }

    public static function loginAdmin(array $admin): void
    {
        Session::regenerate();
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_sv'] = (int) $admin['session_version'];
        self::$admin = null;
        self::$permissions = null;
    }

    public static function logoutAdmin(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_sv'], $_SESSION['2fa_pending_admin']);
        Session::regenerate();
        self::$admin = null;
        self::$permissions = null;
    }

    /** @return list<string> */
    public static function adminPermissions(): array
    {
        $admin = self::admin();
        if (!$admin) {
            return [];
        }
        if (self::$permissions === null) {
            self::$permissions = array_column(Database::instance()->fetchAll(
                'SELECT DISTINCT p.name FROM admin_roles ar JOIN role_permissions rp ON rp.role_id = ar.role_id JOIN permissions p ON p.id = rp.permission_id WHERE ar.admin_id = ?',
                [(int) $admin['id']]
            ), 'name');
        }
        return self::$permissions;
    }

    public static function adminCan(string $permission): bool
    {
        $admin = self::admin();
        if (!$admin) {
            return false;
        }
        if ((int) $admin['is_super'] === 1) {
            return true;
        }
        return in_array($permission, self::adminPermissions(), true);
    }

    /** Reset per-request caches (used by tests and CLI). */
    public static function reset(): void
    {
        self::$user = null;
        self::$admin = null;
        self::$permissions = null;
    }
}
