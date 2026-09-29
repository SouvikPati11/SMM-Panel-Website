<?php

declare(strict_types=1);

/*
 * Test bootstrap. Uses a dedicated test database (NEVER point this at production):
 *   TEST_DB_HOST, TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASS
 * All tables are dropped and recreated on each run.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$env = static fn (string $k, string $d) => getenv($k) !== false ? (string) getenv($k) : $d;
Config::set('db', [
    'host' => $env('TEST_DB_HOST', '127.0.0.1'),
    'port' => (int) $env('TEST_DB_PORT', '3306'),
    'name' => $env('TEST_DB_NAME', 'smm_test'),
    'user' => $env('TEST_DB_USER', 'smm'),
    'pass' => $env('TEST_DB_PASS', 'smmpass'),
    'charset' => 'utf8mb4',
]);
Config::set('key', 'base64:' . base64_encode(str_repeat('k', 32)));
Config::set('url', 'https://panel.test');
Config::set('debug', true);

if (!str_contains((string) Config::get('db.name'), 'test')) {
    fwrite(STDERR, "Refusing to run: TEST_DB_NAME must contain 'test'.\n");
    exit(1);
}

$db = Database::instance();
$db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($db->fetchAll('SHOW TABLES') as $row) {
    $db->pdo()->exec('DROP TABLE `' . array_values($row)[0] . '`');
}
$db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
$db->pdo()->exec((string) file_get_contents(BASE_PATH . '/database/schema.sql'));
App\Install\Seeder::run();
App\Services\SettingsService::flush();
App\Services\SettingsService::set('mail_driver', 'array');
App\Core\App::$forceInstalled = true;

// ---------------------------------------------------------------- tiny test framework
final class T
{
    public static int $pass = 0;
    public static array $fail = [];
    public static string $current = '';

    public static function test(string $name, callable $fn): void
    {
        self::$current = $name;
        App\Core\HttpClient::fake(null);
        App\Services\Auth::reset();
        $_SESSION = [];
        App\Core\HttpClient::fake(null);
        try {
            $fn();
            self::$pass++;
            echo "  \033[32m✓\033[0m {$name}\n";
        } catch (\Throwable $e) {
            self::$fail[] = $name;
            echo "  \033[31m✗ {$name}\033[0m\n    " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        }
    }

    public static function eq(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(($msg ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public static function true(mixed $v, string $msg = 'expected true'): void
    {
        if ($v !== true) {
            throw new \RuntimeException($msg);
        }
    }

    /** @param class-string $class */
    public static function throws(string $class, callable $fn, ?string $contains = null): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!$e instanceof $class) {
                throw new \RuntimeException("expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
            }
            if ($contains !== null && !str_contains($e->getMessage(), $contains)) {
                throw new \RuntimeException("exception message '{$e->getMessage()}' does not contain '{$contains}'");
            }
            return;
        }
        throw new \RuntimeException("expected exception {$class}");
    }
}

// ---------------------------------------------------------------- fixtures
final class Fx
{
    private static int $n = 0;

    public static function user(string $balance = '0', array $extra = []): array
    {
        self::$n++;
        $db = Database::instance();
        $id = $db->insert('users', $extra + [
            'username' => 'user' . self::$n . '_' . substr(bin2hex(random_bytes(3)), 0, 4),
            'email' => 'u' . self::$n . bin2hex(random_bytes(2)) . '@example.com',
            'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT),
            'referral_code' => 'ref' . self::$n . bin2hex(random_bytes(3)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        App\Services\WalletService::createWallet($id);
        if ($balance !== '0') {
            App\Services\WalletService::apply($id, $balance, 'deposit', 'fixture:' . $id, 'fixture');
        }
        return $db->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function provider(string $url = 'https://provider.test/api/v2', array $config = []): int
    {
        return Database::instance()->insert('providers', [
            'name' => 'Test Provider ' . (++self::$n),
            'adapter' => 'standard_v2',
            'api_url' => $url,
            'api_key_enc' => App\Core\Crypto::encrypt('provkey123'),
            'currency' => 'USD',
            'exchange_rate' => '1',
            'config' => $config ? json_encode($config) : null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function service(?int $providerId, array $extra = []): int
    {
        $db = Database::instance();
        $cat = (int) $db->fetchColumn('SELECT id FROM categories LIMIT 1');
        if (!$cat) {
            $cat = $db->insert('categories', ['name' => 'Instagram Followers', 'slug' => 'instagram-followers', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        return $db->insert('services', $extra + [
            'category_id' => $cat,
            'name' => 'Instagram Followers [Real]',
            'type' => 'default',
            'provider_id' => $providerId,
            'provider_service_id' => $providerId ? '101' : null,
            'rate' => '2.500000',
            'min_quantity' => 100,
            'max_quantity' => 10000,
            'refill' => 1,
            'cancel' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function balance(int $userId): string
    {
        return (string) Database::instance()->fetchColumn('SELECT balance FROM wallets WHERE user_id = ?', [$userId]);
    }

    public static function gatewayMethod(string $gateway, array $creds, array $config = []): int
    {
        $db = Database::instance();
        $id = (int) $db->fetchColumn('SELECT id FROM payment_methods WHERE gateway = ?', [$gateway]);
        $db->update('payment_methods', [
            'credentials_enc' => App\Core\Crypto::encryptArray($creds),
            'config' => json_encode($config),
            'status' => 'active',
        ], ['id' => $id]);
        return $id;
    }

    /** Fake HTTP transport: $routes = [urlPrefix => fn(method, url, opts): HttpResponse] */
    public static function http(array $routes): void
    {
        App\Core\HttpClient::fake(static function (string $method, string $url, array $opts) use ($routes) {
            foreach ($routes as $prefix => $fn) {
                if (str_starts_with($url, $prefix)) {
                    return $fn($method, $url, $opts);
                }
            }
            return new App\Core\HttpResponse(0, '', 1, 'Could not resolve host (fake)', true);
        });
    }

    public static function json(array $data, int $status = 200): App\Core\HttpResponse
    {
        return new App\Core\HttpResponse($status, json_encode($data), 5);
    }
}

/** Dispatch a request through the full HTTP kernel (routing, middleware, controllers, views). */
function http(string $method, string $path, array $params = [], array $server = [], ?string $raw = null): App\Core\Response
{
    App\Services\Auth::reset();
    $req = App\Core\Request::create($method, $path, $params, $server + ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'panel.test'], $raw);
    return App\Core\App::handle($req);
}

function login_as_user(array $user): void
{
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_sv'] = (int) App\Core\Database::instance()->fetchColumn('SELECT session_version FROM users WHERE id = ?', [$user['id']]);
}

function login_as_admin(int $adminId): void
{
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['admin_sv'] = (int) App\Core\Database::instance()->fetchColumn('SELECT session_version FROM admins WHERE id = ?', [$adminId]);
}

function csrf(): string
{
    return App\Core\Csrf::token();
}
