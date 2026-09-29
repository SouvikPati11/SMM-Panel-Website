<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Totp;
use App\Services\Auth;
use App\Services\AuthService;

/** Administrator authentication — fully separate from customer accounts. */
final class AuthController extends Controller
{
    public function loginForm(Request $request): Response
    {
        return $this->view('admin/auth/login', ['title' => 'Admin sign in']);
    }

    public function login(Request $request): Response
    {
        $admin = AuthService::attempt('admin', $request->str('login'), (string) $request->input('password', ''), $request->ip(), $request->userAgent());
        if (AuthService::twoFactorSecret($admin) !== null) {
            Session::regenerate();
            Session::set('2fa_pending_admin', ['id' => (int) $admin['id'], 'at' => time()]);
            return Response::redirect(admin_url('login/2fa'));
        }
        AuthService::completeLogin('admin', $admin, $request->ip());
        return $this->toIntended();
    }

    private function toIntended(): Response
    {
        $to = (string) Session::pull('admin_intended', '');
        return Response::redirect($to !== '' && str_starts_with($to, '/' . admin_path()) ? url($to) : admin_url());
    }

    public function twoFactorForm(Request $request): Response
    {
        $p = Session::get('2fa_pending_admin');
        if (!is_array($p) || time() - (int) $p['at'] > 300) {
            return Response::redirect(admin_url('login'));
        }
        return $this->view('auth/2fa', ['title' => 'Admin verification', 'action' => admin_url('login/2fa')]);
    }

    public function twoFactor(Request $request): Response
    {
        $p = Session::get('2fa_pending_admin');
        if (!is_array($p) || time() - (int) $p['at'] > 300) {
            return Response::redirect(admin_url('login'));
        }
        $admin = Database::instance()->fetch('SELECT * FROM admins WHERE id = ?', [(int) $p['id']]);
        $secret = $admin ? AuthService::twoFactorSecret($admin) : null;
        if (!$admin || $admin['status'] !== 'active' || $secret === null) {
            Session::forget('2fa_pending_admin');
            return Response::redirect(admin_url('login'));
        }
        if (!Totp::verify($secret, $request->str('code'))) {
            throw new ValidationException('Invalid authentication code.');
        }
        Session::forget('2fa_pending_admin');
        AuthService::completeLogin('admin', $admin, $request->ip());
        return $this->toIntended();
    }

    public function logout(Request $request): Response
    {
        Auth::logoutAdmin();
        return Response::redirect(admin_url('login'));
    }

    public function forgotForm(Request $request): Response
    {
        return $this->view('auth/forgot', ['title' => 'Admin password reset', 'action' => admin_url('forgot-password'), 'loginUrl' => admin_url('login')]);
    }

    public function forgot(Request $request): Response
    {
        AuthService::requestReset('admin', $request->str('email'), $request->ip());
        $this->success('If that email belongs to an administrator, a reset link has been sent.');
        return Response::redirect(admin_url('login'));
    }

    public function resetForm(Request $request, string $token): Response
    {
        if (!AuthService::findResetToken('admin', $token)) {
            $this->error('This reset link is invalid or has expired.');
            return Response::redirect(admin_url('forgot-password'));
        }
        return $this->view('auth/reset', ['title' => 'New admin password', 'action' => admin_url('reset-password/' . $token)]);
    }

    public function reset(Request $request, string $token): Response
    {
        AuthService::resetPassword('admin', $token, (string) $request->input('password', ''), (string) $request->input('password_confirmation', ''));
        $this->success('Password updated. Please sign in.');
        return Response::redirect(admin_url('login'));
    }

    public function account(Request $request): Response
    {
        $admin = $this->admin();
        $secret = null;
        if ((int) $admin['twofa_enabled'] !== 1) {
            $secret = Session::get('admin_2fa_setup') ?: Totp::generateSecret();
            Session::set('admin_2fa_setup', $secret);
        }
        return $this->view('admin/account', [
            'title' => 'My account',
            'admin' => $admin,
            'secret' => $secret,
            'otpUri' => $secret ? Totp::uri($secret, $admin['email'], site_name() . ' Admin') : null,
            'roles' => Database::instance()->fetchAll('SELECT r.name FROM roles r JOIN admin_roles ar ON ar.role_id = r.id WHERE ar.admin_id = ?', [$admin['id']]),
        ]);
    }

    public function password(Request $request): Response
    {
        AuthService::changePassword('admin', $this->admin(), (string) $request->input('current_password', ''), (string) $request->input('password', ''), (string) $request->input('password_confirmation', ''));
        $this->success('Password changed.');
        return Response::redirect(admin_url('account'));
    }

    public function enable2fa(Request $request): Response
    {
        $secret = (string) Session::get('admin_2fa_setup', '');
        if ($secret === '') {
            throw new ValidationException('Setup expired, please reload.');
        }
        AuthService::enableTwoFactor('admin', (int) $this->admin()['id'], $secret, $request->str('code'));
        Session::forget('admin_2fa_setup');
        $this->success('Two-factor authentication enabled.');
        return Response::redirect(admin_url('account'));
    }

    public function disable2fa(Request $request): Response
    {
        AuthService::disableTwoFactor('admin', $this->admin(), (string) $request->input('password', ''));
        $this->success('Two-factor authentication disabled.');
        return Response::redirect(admin_url('account'));
    }
}
