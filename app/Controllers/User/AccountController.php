<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Totp;
use App\Services\ApiKeyService;
use App\Services\AuditService;
use App\Services\Auth;
use App\Services\AuthService;
use App\Services\NotificationService;
use App\Services\ReferralService;

final class AccountController extends Controller
{
    public function profile(Request $request): Response
    {
        $user = $this->user();
        return $this->view('user/profile', [
            'title' => 'Profile',
            'user' => $user,
            'timezones' => \DateTimeZone::listIdentifiers(),
            'mobileMode' => AuthService::mobileMode(),
            'currencies' => \App\Services\CurrencyService::switchEnabled() ? \App\Services\CurrencyService::all() : [],
            'level' => \App\Services\PriceLevelService::progress($user),
        ]);
    }

    /** Quick display-currency switch (topbar). Display only — balances are never converted. */
    public function currency(Request $request): Response
    {
        \App\Services\CurrencyService::setUserCurrency((int) $this->user()['id'], $request->str('currency'));
        return $this->back($request, '/dashboard');
    }

    public function updateProfile(Request $request): Response
    {
        $user = $this->user();
        $name = mb_substr($request->str('name'), 0, 100);
        $tz = $request->str('timezone');
        if ($tz !== '' && !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new ValidationException('Select a valid timezone.');
        }
        $update = ['name' => $name ?: null, 'timezone' => $tz ?: null, 'updated_at' => now()];
        $mode = AuthService::mobileMode();
        if ($mode !== 'off') {
            $update['mobile'] = AuthService::validateMobile($request->str('mobile'), $mode);
        }
        $currency = $request->str('currency');
        if ($currency !== '' && strtoupper($currency) !== strtoupper((string) ($user['currency'] ?: \App\Services\CurrencyService::base()['code']))) {
            \App\Services\CurrencyService::setUserCurrency((int) $user['id'], $currency); // display only: nothing stored is converted
        }

        $email = strtolower($request->str('email'));
        if ($email !== $user['email']) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ValidationException('Enter a valid email address.');
            }
            if (!password_verify((string) $request->input('current_password', ''), $user['password_hash'])) {
                throw new ValidationException('Enter your current password to change your email address.');
            }
            if (Database::instance()->fetchColumn('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $user['id']])) {
                throw new ValidationException('That email is already in use.');
            }
            $update['email'] = $email;
            $update['email_verified_at'] = null;
            $update['email_changed_at'] = now(); // must be re-verified even if the account predates verification
            AuditService::log('user.email_changed', 'user', (int) $user['id'], ['from' => $user['email'], 'to' => $email]);
        }
        Database::instance()->update('users', $update, ['id' => $user['id']]);
        if (isset($update['email']) && setting('email_verification', '0') === '1') {
            AuthService::sendVerification((int) $user['id']);
        }
        $this->success('Profile updated.');
        return $this->redirect('/account');
    }

    public function security(Request $request): Response
    {
        $user = $this->user();
        $secret = null;
        if ((int) $user['twofa_enabled'] !== 1) {
            $secret = Session::get('2fa_setup_secret') ?: Totp::generateSecret();
            Session::set('2fa_setup_secret', $secret);
        }
        $logins = Database::instance()->fetchAll("SELECT ip, user_agent, success, created_at FROM login_attempts WHERE guard = 'user' AND identifier IN (?, ?) ORDER BY id DESC LIMIT 8", [strtolower($user['username']), $user['email']]);
        return $this->view('user/security', [
            'title' => 'Security',
            'user' => $user,
            'secret' => $secret,
            'googleEnabled' => \App\Services\GoogleAuthService::enabled(),
            'googleAccount' => \App\Services\GoogleAuthService::linkedAccount((int) $user['id']),
            'otpUri' => $secret ? Totp::uri($secret, $user['email'], site_name()) : null,
            'logins' => $logins,
        ]);
    }

    public function password(Request $request): Response
    {
        $firstPassword = (int) ($this->user()['password_set'] ?? 1) === 0;
        AuthService::changePassword('user', $this->user(), (string) $request->input('current_password', ''), (string) $request->input('password', ''), (string) $request->input('password_confirmation', ''));
        $this->success($firstPassword ? 'Password set. You can now also sign in with your email and password.' : 'Password changed. Other sessions have been signed out.');
        return $this->redirect('/account/security');
    }

    public function enable2fa(Request $request): Response
    {
        $secret = (string) Session::get('2fa_setup_secret', '');
        if ($secret === '') {
            throw new ValidationException('Setup expired, please try again.');
        }
        AuthService::enableTwoFactor('user', (int) $this->user()['id'], $secret, $request->str('code'));
        Session::forget('2fa_setup_secret');
        $this->success('Two-factor authentication is now enabled.');
        return $this->redirect('/account/security');
    }

    public function disable2fa(Request $request): Response
    {
        AuthService::disableTwoFactor('user', $this->user(), (string) $request->input('password', ''));
        $this->success('Two-factor authentication disabled.');
        return $this->redirect('/account/security');
    }

    public function logoutOthers(Request $request): Response
    {
        $user = $this->user();
        if (!password_verify((string) $request->input('password', ''), $user['password_hash'])) {
            throw new ValidationException('Your password is incorrect.');
        }
        $db = Database::instance();
        $db->query('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$user['id']]);
        Auth::loginUser($db->fetch('SELECT * FROM users WHERE id = ?', [$user['id']]));
        AuditService::log('user.sessions_revoked', 'user', (int) $user['id']);
        $this->success('All other sessions have been signed out.');
        return $this->redirect('/account/security');
    }

    public function api(Request $request): Response
    {
        if (setting('api_enabled', '1') !== '1') {
            $this->notFound();
        }
        $user = $this->user();
        return $this->view('user/api', [
            'title' => 'API access',
            'user' => $user,
            'key' => ApiKeyService::active((int) $user['id']),
            'newKey' => Session::pull('new_api_key'),
            'logs' => Database::instance()->fetchAll('SELECT action, http_status, ip, created_at FROM api_logs WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$user['id']]),
        ]);
    }

    public function apiGenerate(Request $request): Response
    {
        $user = $this->user();
        if ((int) $user['api_enabled'] !== 1) {
            throw new ValidationException('API access is disabled for your account. Please contact support.');
        }
        Session::set('new_api_key', ApiKeyService::generate((int) $user['id']));
        $this->success('New API key generated. Copy it now — it will not be shown again.');
        return $this->redirect('/account/api');
    }

    public function apiRevoke(Request $request): Response
    {
        ApiKeyService::revoke((int) $this->user()['id']);
        $this->success('API key revoked.');
        return $this->redirect('/account/api');
    }

    public function affiliates(Request $request): Response
    {
        if (setting('referral_enabled', '1') !== '1') {
            $this->notFound();
        }
        $user = $this->user();
        $db = Database::instance();
        return $this->view('user/affiliates', [
            'title' => 'Affiliates',
            'user' => $user,
            'stats' => ReferralService::stats((int) $user['id']),
            'link' => url('/r/' . $user['referral_code']),
            'commissions' => Paginator::query(
                'rt.*, u.username',
                'FROM referral_transactions rt JOIN users u ON u.id = rt.referred_id WHERE rt.referrer_id = ?',
                [(int) $user['id']],
                'rt.id DESC',
                $this->pageNum($request),
                20
            ),
            'referrals' => $db->fetchAll("SELECT u.username, r.created_at FROM referrals r JOIN users u ON u.id = r.referred_id WHERE r.referrer_id = ? AND r.status = 'active' ORDER BY r.id DESC LIMIT 20", [$user['id']]),
        ]);
    }

    public function affiliateWithdraw(Request $request): Response
    {
        $amount = ReferralService::withdraw((int) $this->user()['id']);
        $this->success(money($amount) . ' moved to your main balance.');
        return $this->redirect('/affiliates');
    }

    public function notifications(Request $request): Response
    {
        $user = $this->user();
        $items = Paginator::query('*', 'FROM notifications WHERE user_id = ?', [(int) $user['id']], 'id DESC', $this->pageNum($request), 30);
        return $this->view('user/notifications', ['title' => 'Notifications', 'items' => $items]);
    }

    public function readNotifications(Request $request): Response
    {
        NotificationService::markAllRead((int) $this->user()['id']);
        return $this->redirect('/notifications');
    }
}
