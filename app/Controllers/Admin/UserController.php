<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Money;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ApiKeyService;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\WalletService;

final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $q = mb_substr($request->str('q'), 0, 100);
        $status = $request->str('status');
        $where = 'WHERE u.deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (u.username LIKE ? OR u.email LIKE ? OR u.id = ? OR u.register_ip = ?)';
            array_push($params, Database::like($q), Database::like($q), ctype_digit($q) ? (int) $q : 0, $q);
        }
        if (in_array($status, ['active', 'suspended', 'banned'], true)) {
            $where .= ' AND u.status = ?';
            $params[] = $status;
        }
        $sort = match ($request->str('sort')) {
            'balance' => 'w.balance DESC',
            'spent' => 'w.total_spent DESC',
            'login' => 'u.last_login_at DESC',
            default => 'u.id DESC',
        };
        $users = Paginator::query(
            'u.id, u.username, u.email, u.status, u.created_at, u.last_login_at, u.custom_discount, w.balance, w.total_spent, pl.name AS level',
            "FROM users u JOIN wallets w ON w.user_id = u.id LEFT JOIN price_levels pl ON pl.id = u.price_level_id {$where}",
            $params,
            $sort,
            $this->pageNum($request),
            30
        );
        return $this->view('admin/users/index', ['title' => 'Users', 'users' => $users, 'q' => $q, 'status' => $status, 'sort' => $request->str('sort')]);
    }

    public function balances(Request $request): Response
    {
        $users = Paginator::query(
            'u.id, u.username, u.email, w.balance, w.referral_balance, w.total_deposits, w.total_spent, w.updated_at',
            'FROM users u JOIN wallets w ON w.user_id = u.id WHERE u.deleted_at IS NULL AND (w.balance <> 0 OR w.referral_balance <> 0)',
            [],
            'w.balance DESC',
            $this->pageNum($request),
            50
        );
        $totals = Database::instance()->fetch('SELECT COALESCE(SUM(balance),0) b, COALESCE(SUM(referral_balance),0) r, COALESCE(SUM(total_deposits),0) d, COALESCE(SUM(total_spent),0) s FROM wallets');
        return $this->view('admin/users/balances', ['title' => 'User balances', 'users' => $users, 'totals' => $totals]);
    }

    public function create(Request $request): Response
    {
        return $this->view('admin/users/create', ['title' => 'Create user', 'levels' => $this->levels()]);
    }

    public function store(Request $request): Response
    {
        $data = Validator::check($request->post(), ['username' => 'required|username', 'email' => 'required|email', 'password' => 'required|min:8|max:128']);
        AuthService::assertStrongPassword($data['password']);
        $db = Database::instance();
        $email = strtolower($data['email']);
        if ($db->fetchColumn('SELECT id FROM users WHERE username = ? OR email = ?', [$data['username'], $email])) {
            throw new ValidationException('Username or email already exists.');
        }
        $id = $db->transaction(function (Database $db) use ($data, $email, $request): int {
            $id = $db->insert('users', [
                'username' => $data['username'], 'email' => $email,
                'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
                'status' => 'active', 'email_verified_at' => now(),
                'price_level_id' => $request->int('price_level_id') ?: null,
                'price_level_manual' => $request->int('price_level_id') ? 1 : 0,
                'referral_code' => strtolower(bin2hex(random_bytes(5))),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            WalletService::createWallet($id);
            \App\Services\PriceLevelService::sync($id);
            return $id;
        });
        AuditService::log('user.create', 'user', $id, ['username' => $data['username']]);
        $this->success('User created.');
        return Response::redirect(admin_url('users/' . $id));
    }

    public function show(Request $request, int $id): Response
    {
        $db = Database::instance();
        $user = $db->fetch('SELECT u.*, w.balance, w.referral_balance, w.total_deposits, w.total_spent, w.allow_negative, r.username AS referrer FROM users u JOIN wallets w ON w.user_id = u.id LEFT JOIN users r ON r.id = u.referred_by WHERE u.id = ? AND u.deleted_at IS NULL', [$id]);
        if (!$user) {
            $this->notFound();
        }
        $tab = in_array($request->str('tab'), ['orders', 'transactions', 'adjustments', 'tickets', 'payments', 'activity'], true) ? $request->str('tab') : 'orders';
        $page = $this->pageNum($request);
        $list = match ($tab) {
            'orders' => Paginator::query('o.id, o.link, o.quantity, o.charge, o.status, o.created_at, s.name AS service', 'FROM orders o JOIN services s ON s.id = o.service_id WHERE o.user_id = ?', [$id], 'o.id DESC', $page, 20),
            'transactions' => Paginator::query('*', 'FROM transactions WHERE user_id = ?', [$id], 'id DESC', $page, 25),
            'adjustments' => Paginator::query('t.*, a.username AS admin_name', 'FROM transactions t LEFT JOIN admins a ON a.id = t.admin_id WHERE t.user_id = ? AND t.admin_id IS NOT NULL', [$id], 't.id DESC', $page, 25),
            'tickets' => Paginator::query('*', 'FROM tickets WHERE user_id = ?', [$id], 'id DESC', $page, 20),
            'payments' => Paginator::query('*', 'FROM payments WHERE user_id = ?', [$id], 'id DESC', $page, 20),
            'activity' => Paginator::query('*', "FROM login_attempts WHERE guard = 'user' AND identifier IN (?, ?)", [strtolower($user['username']), $user['email']], 'id DESC', $page, 25),
        };
        return $this->view('admin/users/show', [
            'title' => $user['username'],
            'user' => $user,
            'tab' => $tab,
            'list' => $list,
            'levels' => $this->levels(),
            'levelInfo' => \App\Services\PriceLevelService::progress($user),
            'adjustKey' => bin2hex(random_bytes(16)),
            'orderStats' => \App\Services\OrderService::userStats($id),
            'apiKey' => ApiKeyService::active($id),
            'referrals' => (int) $db->fetchColumn('SELECT COUNT(*) FROM referrals WHERE referrer_id = ?', [$id]),
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $db = Database::instance();
        $user = $db->fetch('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$user) {
            $this->notFound();
        }
        $data = Validator::check($request->post(), [
            'email' => 'required|email',
            'status' => 'required|in:active,suspended,banned',
            'custom_discount' => 'decimal|min:0|max:100',
        ]);
        $email = strtolower($data['email']);
        if ($db->fetchColumn('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id])) {
            throw new ValidationException('Email already used by another account.');
        }
        $level = $request->int('price_level_id'); // 0 = automatic by deposits
        $changes = [
            'name' => mb_substr($request->str('name'), 0, 100) ?: null,
            'email' => $email,
            'status' => $data['status'],
            'price_level_id' => $level ?: $user['price_level_id'],
            'price_level_manual' => $level ? 1 : 0,
            'custom_discount' => Money::of($data['custom_discount'] ?: '0', 2),
            'api_enabled' => $request->bool('api_enabled') ? 1 : 0,
            'admin_note' => mb_substr($request->str('admin_note'), 0, 2000) ?: null,
            'updated_at' => now(),
        ];
        if ($changes['status'] !== 'active' && $user['status'] === 'active') {
            $db->query('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$id]); // force logout
        }
        $db->update('users', $changes, ['id' => $id]);
        if (!$level) {
            \App\Services\PriceLevelService::sync($id);
        }
        $db->update('wallets', ['allow_negative' => $request->bool('allow_negative') ? 1 : 0], ['user_id' => $id]);
        $diff = [];
        foreach ($changes as $k => $v) {
            if ($k !== 'updated_at' && (string) ($user[$k] ?? '') !== (string) $v) {
                $diff[$k] = ['from' => $user[$k] ?? null, 'to' => $v];
            }
        }
        AuditService::log('user.update', 'user', $id, $diff);
        $this->success('User updated.');
        return Response::redirect(admin_url('users/' . $id));
    }

    public function balance(Request $request, int $id): Response
    {
        $direction = $request->str('direction') === 'subtract' ? '-' : '';
        $amount = $request->str('amount');
        if (!Money::isNumeric($amount) || !Money::isPositive($amount)) {
            throw new ValidationException('Enter a positive amount.');
        }
        if (!Database::instance()->fetchColumn('SELECT id FROM users WHERE id = ? AND deleted_at IS NULL', [$id])) {
            $this->notFound();
        }
        $res = WalletService::adminAdjust($id, $direction . $amount, $request->str('reason'), (int) $this->admin()['id'], $request->str('kind') ?: 'manual_adjustment', $request->str('adjust_key') ?: null);
        $res['duplicate'] ? $this->success('This adjustment was already applied (form submitted twice). Balance: ' . money($res['balance_after'])) : $this->success('Balance updated. New balance: ' . money($res['balance_after']));
        return Response::redirect(admin_url('users/' . $id . '?tab=adjustments'));
    }

    public function security(Request $request, int $id): Response
    {
        $db = Database::instance();
        $user = $db->fetch('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$user) {
            $this->notFound();
        }
        $action = $request->str('action');
        switch ($action) {
            case 'reset_2fa':
                $db->update('users', ['twofa_enabled' => 0, 'twofa_secret' => null], ['id' => $id]);
                $msg = 'Two-factor authentication reset.';
                break;
            case 'revoke_sessions':
                $db->query('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$id]);
                $msg = 'All sessions signed out.';
                break;
            case 'revoke_api':
                ApiKeyService::revoke($id);
                $msg = 'API key revoked.';
                break;
            case 'set_password':
                $pw = (string) $request->input('password', '');
                Validator::check(['password' => $pw], ['password' => 'required|min:8|max:128']);
                AuthService::assertStrongPassword($pw);
                $db->query('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
                $msg = 'Password changed and sessions signed out.';
                break;
            case 'verify_email':
                $db->update('users', ['email_verified_at' => now()], ['id' => $id]);
                $msg = 'Email marked as verified.';
                break;
            default:
                throw new ValidationException('Unknown action.');
        }
        AuditService::log('user.security.' . $action, 'user', $id);
        $this->success($msg);
        return Response::redirect(admin_url('users/' . $id));
    }

    /**
     * Soft delete: the account is disabled and anonymised, but financial
     * records (orders, transactions) are kept for accounting and audit.
     */
    public function delete(Request $request, int $id): Response
    {
        $db = Database::instance();
        $user = $db->fetch('SELECT u.*, w.balance FROM users u JOIN wallets w ON w.user_id = u.id WHERE u.id = ? AND u.deleted_at IS NULL', [$id]);
        if (!$user) {
            $this->notFound();
        }
        if ($request->str('confirm') !== $user['username']) {
            throw new ValidationException('Type the username exactly to confirm deletion.');
        }
        $db->update('users', [
            'status' => 'banned',
            'deleted_at' => now(),
            'username' => 'deleted_' . $id,
            'email' => 'deleted_' . $id . '@deleted.invalid',
            'name' => null,
            'twofa_secret' => null,
            'twofa_enabled' => 0,
            'session_version' => (int) $user['session_version'] + 1,
            'updated_at' => now(),
        ], ['id' => $id]);
        ApiKeyService::revoke($id);
        AuditService::log('user.delete', 'user', $id, ['username' => $user['username'], 'email' => $user['email'], 'balance_at_deletion' => $user['balance']]);
        $this->success('User deleted (financial history retained).');
        return Response::redirect(admin_url('users'));
    }

    private function levels(): array
    {
        return Database::instance()->fetchPairs("SELECT id, CONCAT(name, ' (', discount_percent, '%)') FROM price_levels ORDER BY discount_percent");
    }
}
