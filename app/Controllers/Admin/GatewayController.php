<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Money;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Payment\GatewayRegistry;
use App\Services\AuditService;
use App\Services\UploadService;

/** Payment methods: automatic gateways (credentials encrypted, write-only) and manual methods. */
final class GatewayController extends Controller
{
    public function index(Request $request): Response
    {
        $methods = Database::instance()->fetchAll('SELECT * FROM payment_methods ORDER BY gateway = \'manual\', sort_order, id');
        foreach ($methods as &$m) {
            $m['implemented'] = true;
            $m['configured'] = true;
            if (GatewayRegistry::isAutomatic($m['gateway'])) {
                $gw = GatewayRegistry::make($m);
                $m['implemented'] = $gw->isImplemented();
                $m['configured'] = $gw->isConfigured();
            }
        }
        return $this->view('admin/gateways/index', ['title' => 'Payment gateways', 'methods' => $methods]);
    }

    public function create(Request $request): Response
    {
        return $this->view('admin/gateways/form', ['title' => 'Add manual payment method', 'method' => ['gateway' => 'manual'], 'gateway' => null, 'creds' => [], 'options' => []]);
    }

    public function edit(Request $request, int $id): Response
    {
        $m = Database::instance()->fetch('SELECT * FROM payment_methods WHERE id = ?', [$id]);
        if (!$m) {
            $this->notFound();
        }
        $gw = GatewayRegistry::isAutomatic($m['gateway']) ? GatewayRegistry::make($m) : null;
        $creds = Crypto::decryptArray($m['credentials_enc']);
        $masked = [];
        foreach ($gw?->credentialFields() ?? [] as $f) {
            $v = (string) ($creds[$f['name']] ?? '');
            $masked[$f['name']] = $f['secret'] ? Crypto::mask($v) : $v;
        }
        return $this->view('admin/gateways/form', [
            'title' => 'Edit ' . $m['name'],
            'method' => $m,
            'gateway' => $gw,
            'creds' => $masked,
            'options' => json_decode((string) $m['config'], true) ?: [],
        ]);
    }

    public function save(Request $request): Response
    {
        $db = Database::instance();
        $id = $request->int('id');
        $existing = $id ? $db->fetch('SELECT * FROM payment_methods WHERE id = ?', [$id]) : null;
        if ($id && !$existing) {
            $this->notFound();
        }
        $gateway = $existing['gateway'] ?? 'manual';
        $data = Validator::check($request->post(), [
            'name' => 'required|max:100',
            'min_amount' => 'required|decimal|min:0',
            'max_amount' => 'required|decimal|min:0',
            'fee_percent' => 'decimal|min:0|max:50',
            'sort_order' => 'integer',
            'account' => 'max:500',
        ]);
        if (Money::cmp($data['max_amount'], $data['min_amount']) < 0) {
            throw new ValidationException('Maximum must be greater than minimum.');
        }
        $row = [
            'name' => $data['name'],
            'instructions' => mb_substr((string) ($request->post()['instructions'] ?? ''), 0, 5000) ?: null,
            'min_amount' => Money::of($data['min_amount'], 4),
            'max_amount' => Money::of($data['max_amount'], 4),
            'fee_percent' => Money::of($data['fee_percent'] ?: '0', 2),
            'sort_order' => (int) ($data['sort_order'] ?: 0),
            'status' => $request->str('status') === 'active' ? 'active' : 'disabled',
            'updated_at' => now(),
        ];

        if ($gateway === 'manual') {
            $row['account'] = $data['account'] ?: null;
            $row['require_proof'] = $request->bool('require_proof') ? 1 : 0;
            if ($file = $request->file('qr_image')) {
                $row['qr_image'] = UploadService::storePublicImage($file, 'qr');
                UploadService::deletePublic($existing['qr_image'] ?? null);
            } elseif ($request->bool('remove_qr')) {
                UploadService::deletePublic($existing['qr_image'] ?? null);
                $row['qr_image'] = null;
            }
        } else {
            $gw = GatewayRegistry::make($existing);
            // Credentials are write-only: an empty input keeps the stored value.
            $creds = Crypto::decryptArray($existing['credentials_enc']);
            $changed = [];
            foreach ($gw->credentialFields() as $f) {
                $v = trim((string) ($request->post()['cred_' . $f['name']] ?? ''));
                if ($v !== '' && !str_contains($v, '••')) {
                    $creds[$f['name']] = $v;
                    $changed[] = $f['name'];
                }
            }
            $row['credentials_enc'] = Crypto::encryptArray($creds);
            $opts = [];
            foreach ($gw->optionFields() as $f) {
                $opts[$f['name']] = mb_substr(trim((string) ($request->post()['opt_' . $f['name']] ?? $f['default'])), 0, 200);
            }
            $row['config'] = json_encode($opts);
            if ($row['status'] === 'active') {
                $check = GatewayRegistry::make(['credentials_enc' => $row['credentials_enc'], 'config' => $row['config']] + $existing);
                if (!$check->isImplemented()) {
                    $row['status'] = 'disabled';
                    $this->error('This gateway is a placeholder and cannot be enabled until it is implemented (see docs/payment-gateways.md).');
                } elseif (!$check->isConfigured()) {
                    $row['status'] = 'disabled';
                    $this->error('Enter all required credentials before enabling this gateway.');
                }
            }
            AuditService::log('gateway.credentials', 'payment_method', $id, ['changed' => $changed]);
        }

        if ($id) {
            $db->update('payment_methods', $row, ['id' => $id]);
        } else {
            $id = $db->insert('payment_methods', $row + ['gateway' => 'manual', 'created_at' => now()]);
        }
        AuditService::log('gateway.save', 'payment_method', $id, ['name' => $row['name'], 'status' => $row['status']]);
        $this->success('Payment method saved.');
        return Response::redirect(admin_url('gateways/' . $id . '/edit'));
    }

    public function delete(Request $request, int $id): Response
    {
        $db = Database::instance();
        $m = $db->fetch('SELECT * FROM payment_methods WHERE id = ?', [$id]);
        if (!$m || $m['gateway'] !== 'manual') {
            throw new ValidationException('Automatic gateways cannot be deleted — disable them instead.');
        }
        if ($db->fetchColumn('SELECT id FROM manual_payment_requests WHERE payment_method_id = ? LIMIT 1', [$id])) {
            $db->update('payment_methods', ['status' => 'disabled'], ['id' => $id]);
            $this->success('This method has payment history, so it was disabled instead of deleted.');
        } else {
            UploadService::deletePublic($m['qr_image']);
            $db->delete('payment_methods', ['id' => $id]);
            $this->success('Payment method deleted.');
        }
        AuditService::log('gateway.delete', 'payment_method', $id);
        return Response::redirect(admin_url('gateways'));
    }
}
