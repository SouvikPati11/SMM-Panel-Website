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
use App\Services\GoogleAuthService;
use App\Services\RecaptchaService;

final class AuthController extends Controller
{
    public function loginForm(Request $request): Response
    {
        return $this->withCaptcha($this->view('auth/login', ['title' => 'Sign in', 'google' => GoogleAuthService::enabled(), 'captcha' => RecaptchaService::widget('login')]));
    }

    public function login(Request $request): Response
    {
        // Verified before any credential check, so bots cannot probe passwords or lock accounts.
        RecaptchaService::verify($request->str(RecaptchaService::FIELD), $request->ip(), 'login');
        $user = AuthService::attempt('user', $request->str('login'), (string) $request->input('password', ''), $request->ip(), $request->userAgent());
        return $this->finishLogin($user, $request, $request->bool('remember'));
    }

    /** Shared by password and Google sign-in: 2FA step if enabled, then the intended page. */
    private function finishLogin(array $user, Request $request, bool $remember): Response
    {
        if (AuthService::twoFactorSecret($user) !== null) {
            Session::regenerate();
            Session::set('2fa_pending_user', ['id' => (int) $user['id'], 'at' => time(), 'remember' => $remember]);
            return $this->redirect('/login/2fa');
        }
        AuthService::completeLogin('user', $user, $request->ip(), $remember);
        if (AuthService::needsVerification($user)) {
            return $this->redirect('/verify-email');
        }
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
        AuthService::completeLogin('user', $user, $request->ip(), !empty($pending['remember']));
        $intended = (string) Session::pull('intended', '/dashboard');
        return $this->redirect(str_starts_with($intended, '/') && !str_starts_with($intended, '//') ? $intended : '/dashboard');
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
        return $this->withCaptcha($this->view('auth/register', ['title' => 'Create account', 'ref' => (string) Session::get('ref_code', ''), 'mobileMode' => AuthService::mobileMode(), 'google' => GoogleAuthService::enabled(), 'captcha' => RecaptchaService::widget('register')]));
    }

    /** Allow Google's reCAPTCHA script/frames on the page, only while reCAPTCHA is ON. */
    private function withCaptcha(Response $response): Response
    {
        return RecaptchaService::enabled() ? $response->withHeader('Content-Security-Policy', RecaptchaService::csp()) : $response;
    }

    public function register(Request $request): Response
    {
        RecaptchaService::verify($request->str(RecaptchaService::FIELD), $request->ip(), 'register');
        $ref = preg_replace('/[^a-z0-9]/', '', strtolower($request->str('ref') ?: (string) Session::get('ref_code', '')));
        $user = AuthService::register($request->post(), $request->ip(), $ref);
        Session::forget('ref_code');
        AuthService::completeLogin('user', $user, $request->ip());
        $this->success('Welcome aboard! Your account is ready.');
        return $this->redirect(AuthService::needsVerification($user) ? '/verify-email' : '/dashboard');
    }

    // ---------------------------------------------------------------- Google

    /** GET /auth/google?intent=login|register → Google's consent screen. */
    public function google(Request $request): Response
    {
        if (!GoogleAuthService::enabled()) {
            $this->error('Sign in with Google is not available.');
            return $this->redirect('/login');
        }
        if (Auth::user()) {
            return $this->redirect('/dashboard');
        }
        return Response::redirect(GoogleAuthService::authorizationUrl($request->str('intent') === 'register' ? 'register' : 'login'));
    }

    /** POST /account/google/connect (signed in) → link Google to this account. */
    public function googleConnect(Request $request): Response
    {
        return Response::redirect(GoogleAuthService::authorizationUrl('link', (int) $this->user()['id']));
    }

    public function googleDisconnect(Request $request): Response
    {
        GoogleAuthService::unlink($this->user());
        $this->success('Google has been disconnected from your account.');
        return $this->redirect('/account/security');
    }

    public function googleCallback(Request $request): Response
    {
        try {
            $r = GoogleAuthService::handleCallback(['state' => $request->query('state'), 'code' => $request->query('code'), 'error' => $request->query('error')]);
        } catch (ValidationException $e) {
            $this->error($e->getMessage());
            return $this->redirect(Auth::user() ? '/account/security' : '/login');
        }
        $profile = $r['profile'];

        if ($r['intent'] === 'link') {
            $current = Auth::user();
            if (!$current || (int) $current['id'] !== (int) $r['link_user']) {
                $this->error('Sign in first, then connect Google from Account → Security.');
                return $this->redirect('/login');
            }
            GoogleAuthService::link((int) $current['id'], $profile);
            $this->success('Google is now connected: you can sign in with ' . $profile['email'] . '.');
            return $this->redirect('/account/security');
        }

        $match = GoogleAuthService::resolve($profile);
        if ($match['status'] === 'blocked_unverified') {
            $this->error('An account with ' . $profile['email'] . ' already exists. Sign in with your password, then connect Google in Account → Security.');
            return $this->redirect('/login');
        }
        if ($match['status'] === 'new') {
            if (setting('registration_enabled', '1') !== '1') {
                $this->error('No account uses this Google address, and registration is currently closed.');
                return $this->redirect('/login');
            }
            Session::set('google_signup', $profile + ['at' => time()]);
            return $this->redirect('/auth/google/complete');
        }
        $user = $match['user'];
        if ($user['status'] !== 'active') {
            $this->error('Your account has been ' . $user['status'] . '. Please contact support.');
            return $this->redirect('/login');
        }
        if ($match['status'] === 'link_existing') {
            GoogleAuthService::link((int) $user['id'], $profile); // same verified email on both sides
        }
        GoogleAuthService::touch((int) $user['id']);
        Database::instance()->insert('login_attempts', ['guard' => 'user', 'identifier' => $profile['email'], 'ip' => $request->ip(), 'success' => 1, 'user_agent' => mb_substr($request->userAgent(), 0, 255), 'created_at' => now()]);
        return $this->finishLogin($user, $request, false);
    }

    /** New Google users choose a username (and mobile number / terms, per site settings). */
    public function googleCompleteForm(Request $request): Response
    {
        $p = $this->googleSignup();
        if (!$p) {
            return $this->redirect('/register');
        }
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower(strstr($p['email'], '@', true) ?: 'user')) ?: 'user';
        $suggest = substr(str_pad($base, 3, '0'), 0, 24);
        $db = Database::instance();
        for ($i = 0; $i < 5 && $db->fetchColumn('SELECT id FROM users WHERE username = ?', [$suggest]); $i++) {
            $suggest = substr($base, 0, 24) . random_int(10, 9999);
        }
        return $this->view('auth/google-complete', ['title' => 'Finish creating your account', 'profile' => $p, 'suggest' => $suggest, 'mobileMode' => AuthService::mobileMode()]);
    }

    public function googleComplete(Request $request): Response
    {
        $p = $this->googleSignup();
        if (!$p) {
            $this->error('Your Google sign-up expired. Please try again.');
            return $this->redirect('/register');
        }
        $ref = preg_replace('/[^a-z0-9]/', '', strtolower((string) Session::get('ref_code', '')));
        $user = AuthService::registerWithGoogle($p, $request->post(), $request->ip(), $ref);
        Session::forget('google_signup');
        Session::forget('ref_code');
        AuthService::completeLogin('user', $user, $request->ip());
        $this->success('Welcome aboard! Your account is ready.');
        return $this->redirect('/dashboard');
    }

    private function googleSignup(): ?array
    {
        $p = Session::get('google_signup');
        return is_array($p) && time() - (int) ($p['at'] ?? 0) < 1800 ? $p : null;
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
        if (!AuthService::needsVerification($user)) {
            return $this->redirect('/dashboard');
        }
        return $this->view('auth/verify', ['title' => 'Verify your email', 'user' => $user]);
    }

    public function resendVerification(Request $request): Response
    {
        if (!AuthService::needsVerification($this->user())) {
            return $this->redirect('/dashboard');
        }
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
