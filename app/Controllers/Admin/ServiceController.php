<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Money;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AuditService;
use App\Services\OrderService;
use App\Services\PriceProtection;
use App\Services\SubscriptionService;

final class ServiceController extends Controller
{
    public function index(Request $request): Response
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($c = $request->int('category')) {
            $where .= ' AND s.category_id = ?';
            $params[] = $c;
        }
        if ($p = $request->str('provider')) {
            if ($p === 'manual') {
                $where .= ' AND s.provider_id IS NULL';
            } elseif (ctype_digit($p)) {
                $where .= ' AND s.provider_id = ?';
                $params[] = (int) $p;
            }
        }
        $status = $request->str('status');
        if ($status === 'active' || $status === 'disabled') {
            $where .= ' AND s.status = ?';
            $params[] = $status;
        } elseif ($status === 'hidden') {
            $where .= ' AND s.is_hidden = 1';
        }
        if ($status === 'price') {
            $where .= ' AND (s.price_blocked = 1 OR s.price_disabled = 1)';
        }
        $type = $request->str('type');
        if ($type === 'subscription') {
            $where .= " AND (s.type = 'subscription' OR s.subscription_enabled = 1)";
        } elseif ($type !== '' && isset(OrderService::TYPES[$type])) {
            $where .= ' AND s.type = ?';
            $params[] = $type;
        }
        $q = mb_substr($request->str('q'), 0, 100);
        if ($q !== '') {
            $where .= ' AND (s.name LIKE ? OR s.id = ? OR s.provider_service_id = ?)';
            array_push($params, Database::like($q), ctype_digit($q) ? (int) $q : 0, $q);
        }
        $services = Paginator::query(
            's.*, c.name AS category, p.name AS provider',
            "FROM services s JOIN categories c ON c.id = s.category_id LEFT JOIN providers p ON p.id = s.provider_id {$where}",
            $params,
            'c.sort_order, c.name, s.sort_order, s.id',
            $this->pageNum($request),
            50
        );
        $db = Database::instance();
        return $this->view('admin/services/index', [
            'title' => 'Services',
            'services' => $services,
            'categories' => $db->fetchPairs('SELECT id, name FROM categories ORDER BY sort_order, name'),
            'providers' => $db->fetchPairs('SELECT id, name FROM providers ORDER BY name'),
            'types' => array_map(static fn ($t) => $t['label'], OrderService::TYPES),
            'f' => ['category' => $request->int('category'), 'provider' => $request->str('provider'), 'status' => $status, 'q' => $q, 'type' => $type],
        ]);
    }

    /** Provider price protection: services needing attention + the history of cost/price events. */
    public function priceChanges(Request $request): Response
    {
        $events = Paginator::query(
            'e.*, s.name AS service, p.name AS provider',
            'FROM service_price_events e JOIN services s ON s.id = e.service_id LEFT JOIN providers p ON p.id = e.provider_id' . ($request->int('service') ? ' WHERE e.service_id = ' . $request->int('service') : ''),
            [],
            'e.id DESC',
            $this->pageNum($request),
            50
        );
        return $this->view('admin/services/price-changes', [
            'title' => 'Price changes',
            'alerts' => PriceProtection::alerts(),
            'events' => $events,
            'mode' => PriceProtection::mode(),
            'margin' => PriceProtection::margin(),
            'maxDiscount' => PriceProtection::maxDiscount(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, int $id): Response
    {
        $s = Database::instance()->fetch('SELECT * FROM services WHERE id = ?', [$id]);
        if (!$s) {
            $this->notFound();
        }
        return $this->form($s);
    }

    private function form(?array $service): Response
    {
        $db = Database::instance();
        $categories = $db->fetchPairs('SELECT id, name FROM categories ORDER BY sort_order, name');
        if (!$categories) {
            $this->error('Create a category first.');
            return Response::redirect(admin_url('categories'));
        }
        return $this->view('admin/services/form', [
            'title' => $service ? 'Edit service #' . $service['id'] : 'Add service',
            'service' => $service,
            'categories' => $categories,
            'providers' => $db->fetchPairs('SELECT id, name FROM providers ORDER BY name'),
            'types' => array_map(static fn ($t) => $t['label'], OrderService::TYPES),
            'custom' => json_decode((string) ($service['custom_fields'] ?? ''), true) ?: [],
            'orders' => $service ? (int) $db->fetchColumn('SELECT COUNT(*) FROM orders WHERE service_id = ?', [$service['id']]) : 0,
            'intervals' => SubscriptionService::INTERVALS,
            'delays' => SubscriptionService::DELAYS,
            'maxCycles' => SubscriptionService::maxCycles(),
        ]);
    }

    public function save(Request $request): Response
    {
        $db = Database::instance();
        $id = $request->int('id');
        $data = Validator::check($request->post(), [
            'category_id' => 'required|integer',
            'name' => 'required|max:255',
            'type' => 'required|in:' . implode(',', array_keys(OrderService::TYPES)),
            'rate' => 'required|decimal|min:0',
            'min_quantity' => 'required|integer|min:1',
            'max_quantity' => 'required|integer|min:1',
            'markup_percent' => 'decimal|min:-100|max:10000',
            'refill_days' => 'integer|min:0|max:3650',
            'sort_order' => 'integer',
            'link_label' => 'max:60',
            'average_time' => 'max:60',
        ]);
        if ((int) $data['max_quantity'] < (int) $data['min_quantity']) {
            throw new ValidationException('Max quantity must be greater than or equal to min quantity.');
        }
        if (!$db->fetchColumn('SELECT id FROM categories WHERE id = ?', [(int) $data['category_id']])) {
            throw new ValidationException('Select a valid category.');
        }
        $providerId = $request->int('provider_id') ?: null;
        $psid = mb_substr($request->str('provider_service_id'), 0, 50) ?: null;
        if ($providerId && !$psid) {
            throw new ValidationException('Enter the provider service ID (or choose manual fulfilment).');
        }
        if ($providerId && !$db->fetchColumn('SELECT id FROM providers WHERE id = ?', [$providerId])) {
            throw new ValidationException('Select a valid provider.');
        }
        $linkType = in_array($request->str('link_type'), ['url', 'username', 'text'], true) ? $request->str('link_type') : 'url';
        $regex = $request->str('link_regex');
        if ($regex !== '' && @preg_match($regex, '') === false) {
            throw new ValidationException('Link pattern is not a valid regular expression (include delimiters, e.g. #^https://(www\.)?instagram\.com/#i).');
        }
        $custom = array_filter(['link_type' => $linkType, 'link_regex' => $regex ?: null]);

        // Subscription settings (service type "Subscriptions", or a normal service that also allows them).
        $isSubType = $data['type'] === 'subscription';
        $allowSub = $isSubType || $request->bool('subscription_enabled');
        $intervals = array_values(array_intersect(array_map('intval', (array) ($request->post()['subscription_intervals'] ?? [])), array_keys(SubscriptionService::INTERVALS)));
        $subMode = $isSubType && $request->str('subscription_mode') === 'posts' ? 'posts' : 'scheduled';
        $floor = $subMode === 'posts' ? 1 : 2;
        $what = $subMode === 'posts' ? 'new posts' : 'deliveries';
        $subMin = $request->str('subscription_min_cycles');
        $subMax = $request->str('subscription_max_cycles');
        foreach (['Minimum ' . $what => $subMin, 'Maximum ' . $what => $subMax] as $label => $v) {
            if ($v !== '' && (!ctype_digit($v) || (int) $v < $floor || (int) $v > 1000)) {
                throw new ValidationException("{$label} must be a whole number from {$floor} to 1000 (or empty for the default).");
            }
        }
        if ($subMin !== '' && $subMax !== '' && (int) $subMin > (int) $subMax) {
            throw new ValidationException("Minimum {$what} cannot be greater than maximum {$what}.");
        }
        // Post-based settings: allowed delays, old posts, expiry
        $delays = array_values(array_intersect(array_map('intval', (array) ($request->post()['subscription_delays'] ?? [])), array_keys(SubscriptionService::DELAYS)));
        if ($subMode === 'posts' && !$delays) {
            throw new ValidationException('Tick at least one allowed delay (for example "No delay").');
        }
        $oldMax = $request->str('subscription_old_posts_max');
        $expDays = $request->str('subscription_max_expiry_days');
        if ($oldMax !== '' && (!ctype_digit($oldMax) || (int) $oldMax > 10000)) {
            throw new ValidationException('Maximum old posts must be a whole number from 0 to 10000 (0 or empty = old posts not offered).');
        }
        if ($expDays !== '' && (!ctype_digit($expDays) || (int) $expDays < 1 || (int) $expDays > 3650)) {
            throw new ValidationException('Maximum expiry must be between 1 and 3650 days (or empty for no expiry limit).');
        }
        if ($isSubType && !SubscriptionService::enabled()) {
            $this->error('Saved, but auto-subscriptions are switched off in Settings → Orders, so users cannot order this service until you enable them.');
        }

        $providerRate = $request->str('provider_rate');
        $row = [
            'category_id' => (int) $data['category_id'],
            'name' => $data['name'],
            'description' => mb_substr((string) ($request->post()['description'] ?? ''), 0, 10000) ?: null,
            'type' => $data['type'],
            'link_label' => $data['link_label'] ?: 'Link',
            'provider_id' => $providerId,
            'provider_service_id' => $providerId ? $psid : null,
            'provider_rate' => $providerId && Money::isNumeric($providerRate) ? Money::of($providerRate) : null,
            'rate' => Money::of($data['rate']),
            'markup_percent' => $data['markup_percent'] !== '' && $data['markup_percent'] !== null ? Money::of($data['markup_percent'], 2) : null,
            'auto_sync' => $providerId && $request->bool('auto_sync') ? 1 : 0,
            'min_quantity' => (int) $data['min_quantity'],
            'max_quantity' => (int) $data['max_quantity'],
            'average_time' => $data['average_time'] ?: null,
            'dripfeed' => $request->bool('dripfeed') ? 1 : 0,
            'subscription_enabled' => $allowSub ? 1 : 0,
            'subscription_intervals' => $allowSub && $intervals && count($intervals) < count(SubscriptionService::INTERVALS) ? implode(',', $intervals) : null,
            'subscription_min_cycles' => $allowSub && $subMin !== '' ? (int) $subMin : null,
            'subscription_max_cycles' => $allowSub && $subMax !== '' ? (int) $subMax : null,
            'subscription_mode' => $subMode,
            'subscription_delays' => $subMode === 'posts' && count($delays) < count(SubscriptionService::DELAYS) ? implode(',', $delays) : null,
            'subscription_old_posts_max' => $subMode === 'posts' && $oldMax !== '' ? (int) $oldMax : null,
            'subscription_max_expiry_days' => $subMode === 'posts' && $expDays !== '' ? (int) $expDays : null,
            'refill' => $request->bool('refill') ? 1 : 0,
            'refill_days' => (int) ($data['refill_days'] ?: 30),
            'cancel' => $request->bool('cancel') ? 1 : 0,
            'custom_fields' => json_encode($custom),
            'status' => $request->str('status') === 'disabled' ? 'disabled' : 'active',
            'is_hidden' => $request->bool('is_hidden') ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?: 0),
            'updated_at' => now(),
        ];
        if ($id) {
            $old = $db->fetch('SELECT rate, status, provider_id, provider_service_id FROM services WHERE id = ?', [$id]);
            if (!$old) {
                $this->notFound();
            }
            $db->update('services', $row, ['id' => $id]);
            AuditService::log('service.update', 'service', $id, ['rate' => [$old['rate'], $row['rate']], 'status' => [$old['status'], $row['status']], 'provider' => [$old['provider_id'] . ':' . $old['provider_service_id'], $row['provider_id'] . ':' . $row['provider_service_id']]]);
        } else {
            $id = $db->insert('services', $row + ['created_at' => now()]);
            AuditService::log('service.create', 'service', $id, ['name' => $row['name'], 'rate' => $row['rate']]);
        }
        // Provider price protection also applies to prices set by hand.
        $fresh = $db->fetch('SELECT * FROM services WHERE id = ?', [$id]);
        $note = '';
        if ($fresh['provider_id'] && $fresh['provider_rate'] !== null && Money::isPositive((string) $fresh['provider_rate'])) {
            $cost = Money::of((string) $fresh['provider_rate']);
            $action = PriceProtection::evaluate($fresh, $cost, $cost, 'admin edit');
            $note = match ($action) {
                'blocked' => ' Ordering is blocked: the price is below the safe minimum ' . rate(PriceProtection::safeRate($cost)) . ' (provider cost + margin, allowing for the largest user discount).',
                'repriced' => ' The price was raised to the safe minimum ' . rate(PriceProtection::safeRate($cost)) . ' (protection mode: auto-adjust).',
                'disabled' => ' The service was disabled: its price is below the safe minimum ' . rate(PriceProtection::safeRate($cost)) . '.',
                'unblocked', 'restored' => ' The price is safe again, so the service can be ordered.',
                default => '',
            };
        }
        $note !== '' && !in_array($action ?? '', ['unblocked', 'restored'], true) ? $this->error('Service saved.' . $note) : $this->success('Service saved.' . $note);
        return Response::redirect(admin_url('services/' . $id . '/edit'));
    }

    public function bulk(Request $request): Response
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($request->post()['ids'] ?? []))));
        if (!$ids) {
            throw new ValidationException('Select at least one service.');
        }
        $db = Database::instance();
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $action = $request->str('action');
        $n = 0;
        switch ($action) {
            case 'enable':
            case 'disable':
                $n = $db->query("UPDATE services SET status = ?, updated_at = ? WHERE id IN ({$ph})", array_merge([$action === 'enable' ? 'active' : 'disabled', now()], $ids))->rowCount();
                break;
            case 'hide':
            case 'unhide':
                $n = $db->query("UPDATE services SET is_hidden = ?, updated_at = ? WHERE id IN ({$ph})", array_merge([$action === 'hide' ? 1 : 0, now()], $ids))->rowCount();
                break;
            case 'category':
                $cat = $request->int('category_id');
                if (!$db->fetchColumn('SELECT id FROM categories WHERE id = ?', [$cat])) {
                    throw new ValidationException('Choose a category for the move.');
                }
                $n = $db->query("UPDATE services SET category_id = ?, updated_at = ? WHERE id IN ({$ph})", array_merge([$cat, now()], $ids))->rowCount();
                break;
            case 'markup':
                // Recalculate rate = provider_rate × (1 + markup%) for provider-linked services.
                $markup = $request->str('value');
                if (!Money::isNumeric($markup)) {
                    throw new ValidationException('Enter a markup percentage.');
                }
                foreach ($db->fetchAll("SELECT id, provider_rate FROM services WHERE id IN ({$ph}) AND provider_rate IS NOT NULL", $ids) as $s) {
                    $rate = Money::add((string) $s['provider_rate'], Money::percent((string) $s['provider_rate'], $markup));
                    $db->update('services', ['rate' => $rate, 'markup_percent' => Money::of($markup, 2), 'updated_at' => now()], ['id' => $s['id']]);
                    $n++;
                }
                break;
            case 'adjust':
                // Change current rate by ±X %
                $pct = $request->str('value');
                if (!Money::isNumeric($pct)) {
                    throw new ValidationException('Enter a percentage, e.g. 10 or -5.');
                }
                foreach ($db->fetchAll("SELECT id, rate FROM services WHERE id IN ({$ph})", $ids) as $s) {
                    $rate = Money::max('0', Money::add((string) $s['rate'], Money::percent((string) $s['rate'], $pct)));
                    $db->update('services', ['rate' => $rate, 'updated_at' => now()], ['id' => $s['id']]);
                    $n++;
                }
                break;
            case 'delete':
                foreach ($ids as $id) {
                    if ($db->fetchColumn('SELECT id FROM orders WHERE service_id = ? LIMIT 1', [$id])) {
                        $db->update('services', ['status' => 'disabled', 'is_hidden' => 1, 'updated_at' => now()], ['id' => $id]);
                    } else {
                        $db->delete('services', ['id' => $id]);
                    }
                    $n++;
                }
                break;
            default:
                throw new ValidationException('Choose a bulk action.');
        }
        AuditService::log('services.bulk_' . $action, 'service', implode(',', array_slice($ids, 0, 50)), ['count' => $n, 'value' => $request->str('value')]);
        $this->success("{$n} service(s) updated.");
        return $this->back($request, admin_url('services'));
    }

    public function delete(Request $request, int $id): Response
    {
        $db = Database::instance();
        if ($db->fetchColumn('SELECT id FROM orders WHERE service_id = ? LIMIT 1', [$id])) {
            $db->update('services', ['status' => 'disabled', 'is_hidden' => 1, 'updated_at' => now()], ['id' => $id]);
            $this->success('The service has orders, so it was disabled and hidden instead of deleted (order history is preserved).');
        } else {
            $db->delete('services', ['id' => $id]);
            $this->success('Service deleted.');
        }
        AuditService::log('service.delete', 'service', $id);
        return Response::redirect(admin_url('services'));
    }

    public function categories(Request $request): Response
    {
        $cats = Database::instance()->fetchAll(
            "SELECT c.*, COUNT(s.id) AS services, SUM(s.status = 'active') AS active FROM categories c LEFT JOIN services s ON s.category_id = c.id GROUP BY c.id ORDER BY c.sort_order, c.name"
        );
        return $this->view('admin/services/categories', ['title' => 'Categories', 'categories' => $cats]);
    }

    public function saveCategory(Request $request): Response
    {
        $db = Database::instance();
        $data = Validator::check($request->post(), ['name' => 'required|max:150', 'sort_order' => 'integer', 'description' => 'max:500']);
        $id = $request->int('id');
        $slug = $request->str('slug') !== '' ? slugify($request->str('slug')) : slugify($data['name']);
        if ($db->fetchColumn('SELECT id FROM categories WHERE slug = ? AND id <> ?', [$slug, $id])) {
            $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        }
        $row = [
            'name' => $data['name'], 'slug' => $slug, 'description' => $data['description'] ?: null,
            'sort_order' => (int) ($data['sort_order'] ?: 0), 'status' => $request->str('status') === 'hidden' ? 'hidden' : 'active', 'updated_at' => now(),
        ];
        if ($id) {
            $db->update('categories', $row, ['id' => $id]);
        } else {
            $id = $db->insert('categories', $row + ['created_at' => now()]);
        }
        AuditService::log('category.save', 'category', $id, ['name' => $row['name']]);
        $this->success('Category saved.');
        return Response::redirect(admin_url('categories'));
    }

    public function deleteCategory(Request $request, int $id): Response
    {
        $db = Database::instance();
        if ($db->fetchColumn('SELECT id FROM services WHERE category_id = ? LIMIT 1', [$id])) {
            throw new ValidationException('Move or delete the services in this category first.');
        }
        $db->delete('categories', ['id' => $id]);
        AuditService::log('category.delete', 'category', $id);
        $this->success('Category deleted.');
        return Response::redirect(admin_url('categories'));
    }
}
