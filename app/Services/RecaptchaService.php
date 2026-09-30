<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\Exceptions\ValidationException;
use App\Core\HttpClient;
use App\Core\Logger;

/**
 * Google reCAPTCHA on the Login and Sign up forms (Admin → Settings → Users).
 *
 *  - OFF: no widget, no script, no verification.
 *  - ON:  the form carries a token that is verified on the server with
 *         Google's siteverify API before any credential is checked or any
 *         account is created. Failure (missing / invalid / reused token, low
 *         v3 score, wrong action, Google unreachable) never authenticates.
 *
 * Keys come from .env (RECAPTCHA_SITE_KEY / RECAPTCHA_SECRET_KEY) or the admin
 * settings; the secret is stored encrypted (SettingsService::SECRET_KEYS) and
 * is never sent to the browser. Google sign-in (OAuth) is not affected.
 */
final class RecaptchaService
{
    public const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
    public const VERSIONS = ['v2' => 'v2 — "I\'m not a robot" checkbox', 'v3' => 'v3 — invisible, score based'];
    public const FIELD = 'g-recaptcha-response';

    /** @return array{site_key:string, secret_key:string, version:string, min_score:float, source:string} */
    public static function config(): array
    {
        $envSite = trim((string) Env::get('RECAPTCHA_SITE_KEY', ''));
        $envSecret = trim((string) Env::get('RECAPTCHA_SECRET_KEY', ''));
        $version = (string) setting('recaptcha_version', 'v2');
        $score = (float) setting('recaptcha_min_score', '0.5');
        return [
            'site_key' => $envSite !== '' ? $envSite : trim((string) setting('recaptcha_site_key', '')),
            'secret_key' => $envSecret !== '' ? $envSecret : trim((string) setting('recaptcha_secret_key', '')),
            'version' => isset(self::VERSIONS[$version]) ? $version : 'v2',
            'min_score' => $score >= 0.1 && $score <= 0.9 ? $score : 0.5,
            'source' => $envSite !== '' ? 'env' : 'settings',
        ];
    }

    public static function configured(): bool
    {
        $c = self::config();
        return $c['site_key'] !== '' && $c['secret_key'] !== '';
    }

    public static function enabled(): bool
    {
        return setting('recaptcha_enabled', '0') === '1' && self::configured();
    }

    /** What the auth views need to render the widget (never the secret). */
    public static function widget(string $action): ?array
    {
        if (!self::enabled()) {
            return null;
        }
        $c = self::config();
        return ['site_key' => $c['site_key'], 'version' => $c['version'], 'action' => $action];
    }

    /** CSP for pages that show the widget (Google's script, frames and v3 calls). */
    public static function csp(): string
    {
        return "default-src 'self'; script-src 'self' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/; frame-src https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/; "
            . "style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' https://www.google.com/recaptcha/; frame-ancestors 'self'; form-action 'self' https:; base-uri 'self'; object-src 'none'";
    }

    /**
     * Server-side check; a no-op while reCAPTCHA is OFF. Throws a
     * ValidationException (shown inline on the form) when verification fails.
     */
    public static function verify(?string $token, string $ip, string $action): void
    {
        if (!self::enabled()) {
            return;
        }
        $fail = 'Please confirm you are not a robot and try again.';
        $token = trim((string) $token);
        if ($token === '' || strlen($token) > 4000) {
            throw new ValidationException($fail);
        }
        $c = self::config();
        $res = HttpClient::post(self::VERIFY_URL, ['form' => ['secret' => $c['secret_key'], 'response' => $token, 'remoteip' => $ip], 'timeout' => 10]);
        $data = $res->ok() ? json_decode($res->body, true) : null;
        if (!is_array($data)) {
            Logger::warning('reCAPTCHA verification unavailable', ['status' => $res->status, 'error' => $res->error], 'auth');
            throw new ValidationException('We could not verify the security check right now. Please try again in a moment.');
        }
        $ok = ($data['success'] ?? false) === true;
        if ($ok && $c['version'] === 'v3') {
            $ok = isset($data['score']) && (float) $data['score'] >= $c['min_score'] && ($data['action'] ?? '') === $action;
        }
        if (!$ok) {
            Logger::info('reCAPTCHA verification failed', ['action' => $action, 'codes' => $data['error-codes'] ?? [], 'score' => $data['score'] ?? null, 'ip' => $ip], 'auth');
            throw new ValidationException($fail);
        }
    }
}
