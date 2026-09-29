<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Money;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Providers\ProviderFactory;
use App\Providers\StandardApiV2Adapter;
use App\Services\AuditService;
use App\Services\ProviderSyncService;

final class ProviderController extends Controller
{
    public function index(Request $request): Response
    {
        $providers = Database::instance()->fetchAll(
            'SELECT p.*, (SELECT COUNT(*) FROM services s WHERE s.provider_id = p.id) AS services, (SELECT COUNT(*) FROM provider_services ps WHERE ps.provider_id = p.id AND ps.is_available = 1) AS catalog
             FROM providers p ORDER BY p.id'
        );
        return $this->view('admin/providers/index', ['title' => 'Providers', 'providers' => $providers]);
    }

    public function create(Request $request): Response
    {
        return $this->view('admin/providers/form', ['title' => 'Add provider', 'provider' => null, 'config' => '', 'adapters' => ProviderFactory::ADAPTERS]);
    }

    public function edit(Request $request, int $id): Response
    {
        $p = Database::instance()->fetch('SELECT * FROM providers WHERE id = ?', [$id]);
        if (!$p) {
            $this->notFound();
        }
        $key = Crypto::decrypt($p['api_key_enc']);
        return $this->view('admin/providers/form', [
            'title' => 'Edit ' . $p['name'],
            'provider' => $p,
            'maskedKey' => Crypto::mask($key),
            'config' => $p['config'] ? json_encode(json_decode($p['config'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '',
            'adapters' => ProviderFactory::ADAPTERS,
        ]);
    }

    public function save(Request $request): Response
    {
        $db = Database::instance();
        $id = $request->int('id');
        $data = Validator::check($request->post(), [
            'name' => 'required|max:100',
            'api_url' => 'required|url|max:255',
            'currency' => 'required|regex:/^[A-Za-z]{3}$/',
            'exchange_rate' => 'required|decimal|min:0.00000001',
            'timeout' => 'integer|min:5|max:120',
        ]);
        if (!str_starts_with(strtolower($data['api_url']), 'https://')) {
            throw new ValidationException('The API URL must use HTTPS so your API key is not sent in clear text.');
        }
        $adapter = $request->str('adapter') ?: 'standard_v2';
        if (!isset(ProviderFactory::ADAPTERS[$adapter])) {
            throw new ValidationException('Unknown adapter.');
        }
        $configRaw = trim((string) ($request->post()['config'] ?? ''));
        $config = null;
        if ($configRaw !== '') {
            $decoded = json_decode($configRaw, true);
            if (!is_array($decoded)) {
                throw new ValidationException('Adapter configuration must be valid JSON.');
            }
            $allowed = array_keys(StandardApiV2Adapter::DEFAULT_CONFIG);
            if ($unknown = array_diff(array_keys($decoded), $allowed)) {
                throw new ValidationException('Unknown config keys: ' . implode(', ', $unknown));
            }
            $config = json_encode($decoded, JSON_UNESCAPED_SLASHES);
        }
        $row = [
            'name' => $data['name'],
            'adapter' => $adapter,
            'api_url' => $data['api_url'],
            'currency' => strtoupper($data['currency']),
            'exchange_rate' => Money::of($data['exchange_rate'], 8),
            'config' => $config,
            'timeout' => (int) ($data['timeout'] ?: 30),
            'status' => $request->str('status') === 'disabled' ? 'disabled' : 'active',
            'updated_at' => now(),
        ];
        $key = trim((string) $request->input('api_key', ''));
        if ($key !== '') {
            $row['api_key_enc'] = Crypto::encrypt($key);
        } elseif (!$id) {
            throw new ValidationException('API key is required.');
        }
        if ($id) {
            $db->update('providers', $row, ['id' => $id]);
        } else {
            $id = $db->insert('providers', $row + ['created_at' => now()]);
        }
        AuditService::log('provider.save', 'provider', $id, ['name' => $row['name'], 'url' => $row['api_url'], 'key_changed' => $key !== '']);
        $check = ProviderSyncService::checkProvider($id);
        $check['ok'] ? $this->success('Provider saved. Connection OK — balance ' . $check['balance'] . ' ' . $check['currency'] . '.') : $this->error('Provider saved, but the connection test failed: ' . $check['error']);
        return Response::redirect(admin_url('providers/' . $id . '/edit'));
    }

    public function delete(Request $request, int $id): Response
    {
        $db = Database::instance();
        $active = (int) $db->fetchColumn("SELECT COUNT(*) FROM orders WHERE provider_id = ? AND status IN ('pending','processing','in_progress')", [$id]);
        if ($active) {
            throw new ValidationException("This provider still has {$active} active orders. Disable it instead, and delete once they finish.");
        }
        $db->query("UPDATE services SET status = 'disabled', provider_id = NULL, provider_service_id = NULL WHERE provider_id = ?", [$id]);
        $db->delete('providers', ['id' => $id]);
        AuditService::log('provider.delete', 'provider', $id);
        $this->success('Provider deleted; its services were disabled and switched to manual.');
        return Response::redirect(admin_url('providers'));
    }

    public function check(Request $request, int $id): Response
    {
        $r = ProviderSyncService::checkProvider($id);
        $r['ok'] ? $this->success('Connection OK. Balance: ' . $r['balance'] . ' ' . $r['currency']) : $this->error('Connection failed: ' . $r['error']);
        return $this->back($request, admin_url('providers'));
    }

    public function fetch(Request $request, int $id): Response
    {
        $r = ProviderSyncService::fetchCatalog($id);
        $this->success("Fetched {$r['count']} services from the provider.");
        return Response::redirect(admin_url('providers/' . $id . '/services'));
    }

    public function catalog(Request $request, int $id): Response
    {
        $db = Database::instance();
        $provider = $db->fetch('SELECT * FROM providers WHERE id = ?', [$id]);
        if (!$provider) {
            $this->notFound();
        }
        $where = 'WHERE ps.provider_id = ?';
        $params = [$id];
        $q = mb_substr($request->str('q'), 0, 100);
        if ($q !== '') {
            $where .= ' AND (ps.name LIKE ? OR ps.category LIKE ? OR ps.provider_service_id = ?)';
            array_push($params, Database::like($q), Database::like($q), $q);
        }
        if ($request->str('cat') !== '') {
            $where .= ' AND ps.category = ?';
            $params[] = $request->str('cat');
        }
        $items = Paginator::query(
            'ps.*, s.id AS local_id, s.rate AS local_rate',
            "FROM provider_services ps LEFT JOIN services s ON s.provider_id = ps.provider_id AND s.provider_service_id = ps.provider_service_id {$where}",
            $params,
            'ps.category, ps.provider_service_id + 0',
            $this->pageNum($request),
            100
        );
        return $this->view('admin/providers/catalog', [
            'title' => $provider['name'] . ' services',
            'provider' => $provider,
            'items' => $items,
            'q' => $q,
            'cat' => $request->str('cat'),
            'provCats' => array_column($db->fetchAll('SELECT DISTINCT category FROM provider_services WHERE provider_id = ? ORDER BY category', [$id]), 'category'),
            'categories' => $db->fetchPairs('SELECT id, name FROM categories ORDER BY sort_order, name'),
        ]);
    }

    public function import(Request $request, int $id): Response
    {
        $ids = array_map('strval', (array) ($request->post()['ids'] ?? []));
        if (!$ids) {
            throw new ValidationException('Select services to import.');
        }
        $n = ProviderSyncService::importServices($id, $ids, $request->int('category_id') ?: null, $request->str('markup') ?: '0', $request->bool('auto_sync'), $request->str('category_mode') === 'provider');
        $this->success("{$n} services imported/updated.");
        return $this->back($request, admin_url('providers/' . $id . '/services'));
    }

    public function syncPrices(Request $request): Response
    {
        $r = ProviderSyncService::syncServicePrices($request->int('provider_id') ?: null);
        $this->success("Price sync: {$r['updated']} services updated, {$r['disabled']} disabled (no longer offered).");
        return $this->back($request, admin_url('providers'));
    }

    public function logs(Request $request): Response
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($pid = $request->int('provider')) {
            $where .= ' AND l.provider_id = ?';
            $params[] = $pid;
        }
        if ($request->str('errors') === '1') {
            $where .= ' AND l.success = 0';
        }
        $logs = Paginator::query('l.*, p.name AS provider', "FROM provider_logs l LEFT JOIN providers p ON p.id = l.provider_id {$where}", $params, 'l.id DESC', $this->pageNum($request), 50);
        return $this->view('admin/providers/logs', ['title' => 'Provider API logs', 'logs' => $logs]);
    }
}
