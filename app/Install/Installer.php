<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\SettingsService;

/**
 * Web installer at /install. Runs only while storage/installed.lock is absent;
 * afterwards every /install URL returns 404 (see App::handle()).
 *
 *   1. Requirements   PHP version, extensions, writable directories
 *   2. Database       credentials are tested before continuing
 *   3. Site & admin   writes .env, creates tables, seeds data, creates the
 *                     super-admin and writes the lock file
 */
final class Installer
{
    public const REQUIRED_EXTENSIONS = \App\Core\Requirements::EXTENSIONS;
    public const RECOMMENDED_EXTENSIONS = ['bcmath' => 'faster exact decimal math (a pure-PHP fallback is used otherwise)', 'gd' => 're-encoding uploaded images', 'intl' => 'better slugs for non-latin text', 'zip' => 'convenient updates'];

    public static function handle(Request $request): Response
    {
        Session::start($request);
        $path = rtrim($request->path(), '/');
        if ($request->isPost() && !Csrf::verify($request)) {
            throw new HttpException(419);
        }
        try {
            return match (true) {
                $path === '/install' => self::requirements($request),
                $path === '/install/database' => self::database($request),
                $path === '/install/site' => self::site($request),
                default => throw new HttpException(404),
            };
        } catch (\App\Core\Exceptions\ValidationException $e) {
            Session::flash('error', $e->getMessage());
            Session::flashInput($request->post());
            Session::ageFlashInput();
            return Response::redirect($path ?: '/install');
        }
    }

    public static function checks(): array
    {
        $checks = [];
        $checks[] = ['PHP version ≥ ' . \App\Core\Requirements::MIN_PHP, PHP_VERSION_ID >= \App\Core\Requirements::MIN_PHP_ID, 'Current: ' . PHP_VERSION, true];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $checks[] = ["Extension: {$ext}", extension_loaded($ext), '', true];
        }
        foreach (self::RECOMMENDED_EXTENSIONS as $ext => $why) {
            $checks[] = ["Extension: {$ext} (recommended)", extension_loaded($ext), $why, false];
        }
        foreach (['storage', 'storage/logs', 'storage/sessions', 'storage/cache', 'storage/uploads', 'public/uploads'] as $dir) {
            $full = BASE_PATH . '/' . $dir;
            if (!is_dir($full)) {
                @mkdir($full, 0755, true);
            }
            $checks[] = ["Writable: /{$dir}", is_dir($full) && is_writable($full), '', true];
        }
        $envOk = is_file(BASE_PATH . '/.env') ? is_writable(BASE_PATH . '/.env') : is_writable(BASE_PATH);
        $checks[] = ['Can write .env', $envOk, $envOk ? '' : 'You will be shown the file contents to create manually.', false];
        $checks[] = ['file_uploads enabled', (bool) ini_get('file_uploads'), '', false];
        $checks[] = ['HTTPS', (\App\Core\App::request()?->isSecure() ?? false), 'Strongly recommended (payment gateways require HTTPS webhooks).', false];
        return $checks;
    }

    private static function render(string $view, array $data = []): Response
    {
        return Response::html(View::render('install/' . $view, $data));
    }

    private static function requirements(Request $request): Response
    {
        $checks = self::checks();
        $ok = !array_filter($checks, static fn ($c) => $c[3] && !$c[1]);
        return self::render('requirements', ['checks' => $checks, 'ok' => $ok]);
    }

    private static function database(Request $request): Response
    {
        if (!$request->isPost()) {
            return self::render('database', ['db' => Session::get('install_db', ['host' => 'localhost', 'port' => '3306', 'name' => '', 'user' => ''])]);
        }
        $db = [
            'host' => $request->str('host') ?: 'localhost',
            'port' => (string) ($request->int('port') ?: 3306),
            'name' => $request->str('name'),
            'user' => $request->str('user'),
            'pass' => (string) $request->input('pass', ''),
        ];
        if ($db['name'] === '' || $db['user'] === '') {
            throw new \App\Core\Exceptions\ValidationException('Database name and user are required.');
        }
        try {
            $conn = new Database($db + ['charset' => 'utf8mb4', 'port' => (int) $db['port']]);
            $version = (string) $conn->fetchColumn('SELECT VERSION()');
            $tables = (int) $conn->fetchColumn("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('users','admins','orders')");
        } catch (\Throwable $e) {
            throw new \App\Core\Exceptions\ValidationException('Could not connect: ' . preg_replace('/SQLSTATE\[\w+\]\s*(\[\d+\])?\s*/', '', $e->getMessage()));
        }
        if ($tables > 0) {
            throw new \App\Core\Exceptions\ValidationException('This database already contains SMM Panel tables. Use an empty database (the installer never overwrites existing data).');
        }
        Session::set('install_db', $db);
        Session::flash('success', 'Connected to MySQL/MariaDB ' . $version . '.');
        return Response::redirect('/install/site');
    }

    private static function site(Request $request): Response
    {
        $db = Session::get('install_db');
        if (!$db) {
            return Response::redirect('/install/database');
        }
        $guessUrl = ($request->isSecure() ? 'https' : 'http') . '://' . ($request->server('HTTP_HOST') ?: 'localhost');
        if (!$request->isPost()) {
            return self::render('site', ['url' => $guessUrl, 'envContent' => Session::get('install_env')]);
        }

        $data = \App\Core\Validator::check($request->post(), [
            'site_name' => 'required|max:100',
            'app_url' => 'required|url',
            'admin_username' => 'required|username',
            'admin_email' => 'required|email',
            'admin_password' => 'required|min:10|max:128|confirmed',
        ], ['admin_password' => 'Admin password']);
        if (!preg_match('/[A-Za-z]/', $data['admin_password']) || !preg_match('/\d/', $data['admin_password'])) {
            throw new \App\Core\Exceptions\ValidationException('Admin password must contain letters and numbers.');
        }
        $appUrl = rtrim($data['app_url'], '/');
        $adminPath = preg_replace('/[^a-z0-9_-]/', '', strtolower($request->str('admin_path') ?: 'admin')) ?: 'admin';

        $appKey = Crypto::generateAppKey();
        $env = Env::render([
            'APP_NAME' => $data['site_name'],
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $appUrl,
            'APP_KEY' => $appKey,
            'ADMIN_PATH' => $adminPath,
            'FORCE_HTTPS' => str_starts_with($appUrl, 'https://') ? 'true' : 'false',
            'TRUSTED_PROXIES' => '',
            '_1' => null,
            'DB_HOST' => $db['host'],
            'DB_PORT' => $db['port'],
            'DB_NAME' => $db['name'],
            'DB_USER' => $db['user'],
            'DB_PASS' => $db['pass'],
            '_2' => null,
            'SESSION_NAME' => 'smm_' . substr(bin2hex(random_bytes(4)), 0, 8),
            'SESSION_LIFETIME' => '7200',
            '_3' => null,
            'MAIL_DRIVER' => 'mail',
            'MAIL_HOST' => '',
            'MAIL_PORT' => '587',
            'MAIL_ENCRYPTION' => 'tls',
            'MAIL_USERNAME' => '',
            'MAIL_PASSWORD' => '',
            'MAIL_FROM' => $data['admin_email'],
            'MAIL_FROM_NAME' => $data['site_name'],
            '_4' => null,
            'UPLOAD_MAX_BYTES' => '5242880',
            'CRON_KEY' => bin2hex(random_bytes(16)),
        ]);

        // Configure the running request with the new values.
        Config::set('db', ['host' => $db['host'], 'port' => (int) $db['port'], 'name' => $db['name'], 'user' => $db['user'], 'pass' => $db['pass'], 'charset' => 'utf8mb4']);
        Config::set('key', $appKey);
        Config::set('url', $appUrl);

        $conn = new Database(Config::get('db'));
        Database::setInstance($conn);
        $conn->pdo()->exec((string) file_get_contents(BASE_PATH . '/database/schema.sql'));
        Seeder::run();
        Migrator::markAllApplied();
        SettingsService::flush();
        SettingsService::set('site_name', $data['site_name']);
        SettingsService::set('admin_notify_email', $data['admin_email']);
        SettingsService::set('contact_email', $data['admin_email']);
        SettingsService::set('mail_from', $data['admin_email']);
        SettingsService::set('mail_from_name', $data['site_name']);

        $adminId = $conn->insert('admins', [
            'username' => $data['admin_username'],
            'email' => strtolower($data['admin_email']),
            'password_hash' => password_hash($data['admin_password'], PASSWORD_DEFAULT),
            'name' => $data['admin_username'],
            'status' => 'active',
            'is_super' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $conn->query("INSERT INTO admin_roles (admin_id, role_id) SELECT ?, id FROM roles WHERE name = 'Administrator'", [$adminId]);

        $envWritten = @file_put_contents(BASE_PATH . '/.env', $env, LOCK_EX) !== false;
        if ($envWritten) {
            @chmod(BASE_PATH . '/.env', 0640);
        }
        file_put_contents(STORAGE_PATH . '/installed.lock', 'Installed ' . gmdate('c') . "\n");
        Session::forget('install_db');

        return self::render('done', [
            'envWritten' => $envWritten,
            'env' => $envWritten ? null : $env,
            'adminUrl' => $appUrl . '/' . $adminPath,
            'appUrl' => $appUrl,
            'basePath' => BASE_PATH,
            'phpBinary' => \App\Core\Cli::recommendedCliBinary(), // not PHP_BINARY: the web process runs lsphp/php-fpm
        ]);
    }
}
