<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Totp;
use App\Services\Auth;
use App\Services\AuthService;

final class AuthController extends Controller
{
    public function loginForm(Request $request): Response
    {
        return $this->view('auth/login', ['title' => 'Sign in']);
    }

    public function login(Request $request): Response
    {
        $user = AuthService::attempt('user', $request->str('login'), (string) $request->input('password', ''), $request->ip(), $request->userAgent());
        if (AuthService::twoFactorSecret($user) !== null) {
            Session::regenerate();
            Session::set('2fa_pending_user', ['id' => (int) $user['id'], 'at' => time()]);
            return $this->redirect('/login/2fa');
        }
        AuthService::completeLogin('user', $user, $request->ip());
        $intended = (string) Session::pull('intended', '/dashboard');
        return $this->redirect(str_starts_with($intended, '/') && !str_starts_with($intended, '//') ? $intended : '/dashboard');
    }

    public function twoFactorForm(Request $request): Response
    {
        if (!$this->pending()) {
            return $this->redirect('/login');
        }
        return $this->view('auth/2fa', ['title' => 'Two-factor authentication', 'action' => url('/login/2fa')]);
    }

    public function twoFactor(Request $request): Response
    {
        $pending = $this->pending();
        if (!$pending) {
            return $this->redirect('/login');
        }
        $user = Database::instance()->fetch('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$pending['id']]);
        $secret = $user ? AuthService::twoFactorSecret($user) : null;
        if (!$user || $user['status'] !== 'active' || $secret === null) {
            Session::forget('2fa_pending_user');
            return $this->redirect('/login');
        }
        if (!Totp::verify($secret, $request->str('code'))) {
            throw new ValidationException('Invalid authentication code.');
        }
        Session::forget('2fa_pending_user');
        AuthService::completeLogin('user', $user, $request->ip());
        return $this->redirect((string) Session::pull('intended', '/dashboard'));
    }

    private function pending(): ?array
    {
        $p = Session::get('2fa_pending_user');
        return is_array($p) && time() - (int) $p['at'] < 300 ? $p : null;
    }

    public function registerForm(Request $request): Response
    {
        if (setting('registration_enabled', '1') !== '1') {
            return $this->view('auth/closed', ['title' => 'Registration closed']);
        }
        if ($ref = $request->str('ref')) {
            Session::set('ref_code', mb_substr($ref, 0, 20));
        }
        return $this->view('auth/register', ['title' => 'Create account', 'ref' => (string) Session::get('ref_code', '')]);
    }

    public function register(Request $request): Response
    {
        $ref = preg_replace('/[^a-z0-9]/', '', strtolower($request->str('ref') ?: (string) Session::get('ref_code', '')));
        $user = AuthService::register($request->post(), $request->ip(), $ref);
        Session::forget('ref_code');
        AuthService::completeLogin('user', $user, $request->ip());
        $this->success('Welcome aboard! Your account is ready.');
        return $this->redirect(setting('email_verification', '0') === '1' ? '/verify-email' : '/dashboard');
    }

    public function referral(Request $request, string $token): Response
    {
        Session::set('ref_code', mb_substr(preg_replace('/[^a-z0-9]/', '', strtolower($token)), 0, 20));
        return $this->redirect('/register');
    }

    public function logout(Request $request): Response
    {
        Auth::logoutUser();
        return $this->redirect('/login');
    }

    public function forgotForm(Request $request): Response
    {
        return $this->view('auth/forgot', ['title' => 'Reset password', 'action' => url('/forgot-password'), 'loginUrl' => url('/login')]);
    }

    public function forgot(Request $request): Response
    {
        AuthService::requestReset('user', $request->str('email'), $request->ip());
        $this->success('If an account exists for that email, a reset link has been sent.');
        return $this->redirect('/login');
    }

    public function resetForm(Request $request, string $token): Response
    {
        if (!AuthService::findResetToken('user', $token)) {
            $this->error('This reset link is invalid or has expired.');
            return $this->redirect('/forgot-password');
        }
        return $this->view('auth/reset', ['title' => 'Choose a new password', 'action' => url('/reset-password/' . $token)]);
    }

    public function reset(Request $request, string $token): Response
    {
        AuthService::resetPassword('user', $token, (string) $request->input('password', ''), (string) $request->input('password_confirmation', ''));
        $this->success('Your password has been reset. You can sign in now.');
        return $this->redirect('/login');
    }

    public function verifyNotice(Request $request): Response
    {
        $user = $this->user();
        if (!empty($user['email_verified_at']) || setting('email_verification', '0') !== '1') {
            return $this->redirect('/dashboard');
        }
        return $this->view('auth/verify', ['title' => 'Verify your email', 'user' => $user]);
    }

    public function resendVerification(Request $request): Response
    {
        AuthService::sendVerification((int) $this->user()['id']);
        $this->success('A new verification link has been sent.');
        return $this->redirect('/verify-email');
    }

    public function verifyEmail(Request $request, string $token): Response
    {
        if (AuthService::verifyEmail($token)) {
            $this->success('Your email address has been verified.');
            return $this->redirect(Auth::user() ? '/dashboard' : '/login');
        }
        $this->error('This verification link is invalid or has expired.');
        return $this->redirect(Auth::user() ? '/verify-email' : '/login');
    }
}
