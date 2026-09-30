<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Core\Exceptions\ValidationException;
use App\Core\HttpClient;
use App\Core\Logger;
use App\Core\Session;

/**
 * "Sign in with Google" — OpenID Connect authorization-code flow.
 *
 *  - state (CSRF), nonce (replay) and PKCE S256 are generated per attempt,
 *    kept in the session, single-use and valid for 10 minutes;
 *  - the code is exchanged server-to-server over TLS with the client secret,
 *    so the returned ID token's claims are trusted (OIDC Core §3.1.3.7) after
 *    checking iss, aud, exp, nonce and email_verified;
 *  - accounts are matched by Google's stable subject id ("sub"), never by
 *    display name. An existing account with the same email is linked
 *    automatically only if that account's email is verified; otherwise the
 *    owner must sign in with their password and connect Google themselves
 *    (prevents account pre-hijacking).
 *
 * Configuration: GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REDIRECT_URI
 * in .env, or Admin → Settings → Users (secret stored encrypted). .env wins.
 */
final class GoogleAuthService
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SESSION_KEY = 'oauth_google';
    private const TTL = 600;

    /** @return array{client_id:string, client_secret:string, redirect_uri:string, source:string} */
    public static function config(): array
    {
        $envId = trim((string) Env::get('GOOGLE_CLIENT_ID', ''));
        $envSecret = trim((string) Env::get('GOOGLE_CLIENT_SECRET', ''));
        return [
            'client_id' => $envId !== '' ? $envId : trim((string) setting('google_client_id', '')),
            'client_secret' => $envSecret !== '' ? $envSecret : trim((string) setting('google_client_secret', '')),
            'redirect_uri' => trim((string) Env::get('GOOGLE_REDIRECT_URI', '')) ?: url('/auth/google/callback'),
            'source' => $envId !== '' ? 'env' : 'settings',
        ];
    }

    public static function configured(): bool
    {
        $c = self::config();
        return $c['client_id'] !== '' && $c['client_secret'] !== '';
    }

    public static function enabled(): bool
    {
        return setting('google_login_enabled', '0') === '1' && self::configured();
    }

    /**
     * Start a sign-in. $intent: login | register | link (connect to the signed-in user).
     */
    public static function authorizationUrl(string $intent, ?int $linkUserId = null): string
    {
        if (!self::enabled()) {
            throw new ValidationException('Sign in with Google is not available.');
        }
        $state = bin2hex(random_bytes(24));
        $nonce = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        Session::set(self::SESSION_KEY, [
            'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'at' => time(),
            'intent' => in_array($intent, ['login', 'register', 'link'], true) ? $intent : 'login',
            'link_user' => $linkUserId,
        ]);
        $c = self::config();
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $c['client_id'],
            'redirect_uri' => $c['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
            'access_type' => 'online',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Validate the callback and return the verified Google profile plus the
     * pending attempt's intent.
     * @return array{profile: array{sub:string,email:string,name:?string}, intent:string, link_user:?int}
     */
    public static function handleCallback(array $query): array
    {
        $pending = Session::pull(self::SESSION_KEY); // single use, whatever happens next
        if (!empty($query['error'])) {
            throw new ValidationException($query['error'] === 'access_denied' ? 'Google sign-in was cancelled.' : 'Google sign-in failed (' . mb_substr(preg_replace('/[^a-z_]/i', '', (string) $query['error']), 0, 40) . ').');
        }
        if (!is_array($pending) || time() - (int) ($pending['at'] ?? 0) > self::TTL
            || !is_string($query['state'] ?? null) || !hash_equals((string) $pending['state'], $query['state'])) {
            throw new ValidationException('Your Google sign-in link expired or was not started from this browser. Please try again.');
        }
        $code = (string) ($query['code'] ?? '');
        if ($code === '' || strlen($code) > 2048) {
            throw new ValidationException('Google did not return an authorization code. Please try again.');
        }
        $c = self::config();
        $resp = HttpClient::post(self::TOKEN_URL, ['form' => [
            'code' => $code,
            'client_id' => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'redirect_uri' => $c['redirect_uri'],
            'grant_type' => 'authorization_code',
            'code_verifier' => $pending['verifier'],
        ], 'timeout' => 15]);
        $json = $resp->json();
        if ($resp->isNetworkError() || !$resp->ok() || !is_string($json['id_token'] ?? null)) {
            $err = is_array($json) ? (string) ($json['error'] ?? '') : '';
            Logger::warning('Google token exchange failed: HTTP ' . $resp->status . ' ' . ($resp->error ?? '') . ' ' . $err . ' ' . (is_array($json) ? (string) ($json['error_description'] ?? '') : ''), [], 'auth');
            throw new ValidationException($err === 'redirect_uri_mismatch' || $err === 'invalid_client'
                ? 'Google sign-in is misconfigured (' . $err . '). Please contact support.'
                : 'Google sign-in could not be completed. Please try again.');
        }
        $claims = self::claims($json['id_token']);
        $issOk = in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true);
        $aud = $claims['aud'] ?? null;
        $audOk = $aud === $c['client_id'] || (is_array($aud) && in_array($c['client_id'], $aud, true));
        if (!$issOk || !$audOk || (int) ($claims['exp'] ?? 0) < time() - 60 || !hash_equals((string) $pending['nonce'], (string) ($claims['nonce'] ?? ''))) {
            Logger::warning('Google ID token rejected (iss/aud/exp/nonce)', [], 'auth');
            throw new ValidationException('Google sign-in could not be verified. Please try again.');
        }
        $sub = (string) ($claims['sub'] ?? '');
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $verified = ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? '') === 'true';
        if ($sub === '' || strlen($sub) > 191 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Google did not share an email address for this account.');
        }
        if (!$verified) {
            throw new ValidationException('Your Google account email is not verified. Verify it with Google, or register with email and password.');
        }
        return [
            'profile' => ['sub' => $sub, 'email' => $email, 'name' => isset($claims['name']) && is_string($claims['name']) ? $claims['name'] : null],
            'intent' => (string) $pending['intent'],
            'link_user' => isset($pending['link_user']) ? (int) $pending['link_user'] : null,
        ];
    }

    /**
     * Which local account a verified Google profile belongs to.
     * @return array{status:'linked'|'link_existing'|'blocked_unverified'|'new', user:?array}
     */
    public static function resolve(array $profile): array
    {
        $db = Database::instance();
        $user = $db->fetch(
            "SELECT u.* FROM user_social_accounts sa JOIN users u ON u.id = sa.user_id WHERE sa.provider = 'google' AND sa.provider_user_id = ? AND u.deleted_at IS NULL",
            [$profile['sub']]
        );
        if ($user) {
            return ['status' => 'linked', 'user' => $user];
        }
        $user = $db->fetch('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [$profile['email']]);
        if ($user) {
            return ['status' => !empty($user['email_verified_at']) ? 'link_existing' : 'blocked_unverified', 'user' => $user];
        }
        return ['status' => 'new', 'user' => null];
    }

    public static function link(int $userId, array $profile): void
    {
        $db = Database::instance();
        $owner = $db->fetchColumn("SELECT user_id FROM user_social_accounts WHERE provider = 'google' AND provider_user_id = ?", [$profile['sub']]);
        if ($owner && (int) $owner !== $userId) {
            throw new ValidationException('This Google account is already connected to another account.');
        }
        if (!$owner) {
            if ($db->fetchColumn("SELECT id FROM user_social_accounts WHERE provider = 'google' AND user_id = ?", [$userId])) {
                throw new ValidationException('A different Google account is already connected. Disconnect it first.');
            }
            $db->insert('user_social_accounts', ['user_id' => $userId, 'provider' => 'google', 'provider_user_id' => $profile['sub'], 'email' => $profile['email'], 'created_at' => now(), 'last_login_at' => now()]);
            AuditService::log('user.google_linked', 'user', $userId, ['email' => $profile['email']], 'user', $userId);
        }
    }

    public static function unlink(array $user): void
    {
        if ((int) ($user['password_set'] ?? 1) === 0) {
            throw new ValidationException('Set a password first, otherwise you could not sign in any more.');
        }
        Database::instance()->query("DELETE FROM user_social_accounts WHERE provider = 'google' AND user_id = ?", [$user['id']]);
        AuditService::log('user.google_unlinked', 'user', (int) $user['id'], [], 'user', (int) $user['id']);
    }

    public static function linkedAccount(int $userId): ?array
    {
        return Database::instance()->fetch("SELECT * FROM user_social_accounts WHERE provider = 'google' AND user_id = ?", [$userId]);
    }

    public static function touch(int $userId): void
    {
        Database::instance()->query("UPDATE user_social_accounts SET last_login_at = ? WHERE provider = 'google' AND user_id = ?", [now(), $userId]);
    }

    /** Decode the payload of a JWT received directly from Google's token endpoint. */
    private static function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new ValidationException('Google sign-in could not be verified. Please try again.');
        }
        $payload = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
        $claims = is_string($payload) ? json_decode($payload, true) : null;
        if (!is_array($claims)) {
            throw new ValidationException('Google sign-in could not be verified. Please try again.');
        }
        return $claims;
    }
}
