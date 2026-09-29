<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Money;
use App\Helpers\Platforms;
use App\Services\OrderService;
use App\Services\SubscriptionService;

final class OrderController extends Controller
{
    /** Compact catalog for the order form (user-specific prices), one query each. */
    private function catalogData(array $user): array
    {
        $db = Database::instance();
        $categories = $db->fetchAll(
            "SELECT DISTINCT c.id, c.name, c.sort_order FROM categories c JOIN services s ON s.category_id = c.id
             WHERE c.status = 'active' AND s.status = 'active' AND s.is_hidden = 0 ORDER BY c.sort_order, c.name"
        );
        $rows = $db->fetchAll(
            "SELECT s.id, s.category_id, s.name, s.rate, s.type, s.link_label, s.min_quantity, s.max_quantity, s.dripfeed, s.subscription_enabled, s.refill, s.cancel, s.average_time, s.custom_fields
             FROM services s JOIN categories c ON c.id = s.category_id
             WHERE s.status = 'active' AND s.is_hidden = 0 AND c.status = 'active' ORDER BY c.sort_order, s.sort_order, s.id"
        );
        $services = [];
        $subs = SubscriptionService::enabled();
        foreach ($rows as $s) {
            $custom = json_decode((string) $s['custom_fields'], true) ?: [];
            $services[] = [
                'id' => (int) $s['id'], 'c' => (int) $s['category_id'], 'n' => $s['name'],
                'r' => OrderService::userRate($s, $user), 'ty' => $s['type'], 'l' => $s['link_label'],
                'lt' => $custom['link_type'] ?? 'url',
                'mi' => (int) $s['min_quantity'], 'ma' => (int) $s['max_quantity'],
                'df' => (int) $s['dripfeed'] === 1, 'rf' => (int) $s['refill'] === 1, 'cn' => (int) $s['cancel'] === 1,
                'sb' => $subs && (int) $s['subscription_enabled'] === 1,
                't' => (string) $s['average_time'], 'pk' => OrderService::TYPES[$s['type']]['package'] ?? false,
            ];
        }
        return [array_map(static fn ($c) => ['id' => (int) $c['id'], 'n' => $c['name'], 'p' => Platforms::detect($c['name'])], $categories), $services];
    }

    public function create(Request $request): Response
    {
        $user = $this->user();
        [$categories, $services] = $this->catalogData($user);
        return $this->view('user/order-new', [
            'title' => 'New order',
            'user' => $user,
            'categories' => $categories,
            'services' => $services,
            'preselect' => $request->int('service') ?: null,
            'formKey' => bin2hex(random_bytes(16)),
            'intervals' => SubscriptionService::INTERVALS,
            'maxCycles' => SubscriptionService::maxCycles(),
        ]);
    }

    /** Order input from the request (shared by quote and store). */
    private function orderInput(Request $request): array
    {
        return [
            'link' => $request->str('link'),
            'quantity' => $request->str('quantity'),
            'comments' => (string) ($request->post()['comments'] ?? ''),
            'usernames' => (string) ($request->post()['usernames'] ?? ''),
            'username' => $request->str('username'),
            'answer_number' => $request->str('answer_number'),
            'keywords' => $request->str('keywords'),
            'dripfeed' => $request->str('order_type') === 'subscription' ? '' : $request->str('dripfeed'),
            'runs' => $request->str('runs'),
            'interval' => $request->str('interval'),
        ];
    }

    /**
     * Server-side price quote for the confirmation dialog: validates exactly like
     * placing the order but writes nothing. The browser never decides the price.
     */
    public function quote(Request $request): Response
    {
        $user = $this->user();
        $service = OrderService::orderableService($request->int('service'));
        $isSub = $request->str('order_type') === 'subscription';
        $input = $this->orderInput($request);
        if ($isSub) {
            unset($input['dripfeed'], $input['runs'], $input['interval']);
        }
        $params = OrderService::validateInput($service, $input);
        $rate = OrderService::userRate($service, $user);
        $charge = OrderService::computeCharge($service, $rate, $params['quantity'], $params['runs']);
        $minOrder = (string) setting('min_order_amount', '0');
        if (!Money::isPositive($charge)) {
            throw new ValidationException('This order amount is too small to process.');
        }
        if (Money::isNumeric($minOrder) && Money::cmp($charge, $minOrder) < 0) {
            throw new ValidationException('The minimum order amount is ' . money($minOrder) . '.');
        }
        $package = OrderService::TYPES[$service['type']]['package'] ?? false;
        $out = [
            'ok' => true,
            'service' => ['id' => (int) $service['id'], 'name' => $service['name'], 'refill' => (int) $service['refill'] === 1, 'cancel' => (int) $service['cancel'] === 1, 'average_time' => (string) $service['average_time']],
            'link' => $params['link'],
            'quantity' => $params['quantity'],
            'runs' => $params['runs'],
            'interval' => $params['interval'],
            'extra' => $params['extra'],
            'rate' => rate($rate) . ($package ? ' per package' : ' per 1000'),
            'charge' => money($charge),
            'charge_base' => money_base($charge),
            'balance' => money($user['balance']),
            'insufficient' => Money::cmp((string) $user['balance'], $charge) < 0,
            'notes' => [],
        ];
        if ($params['runs']) {
            $out['notes'][] = "Drip-feed: {$params['runs']} runs every {$params['interval']} min (total " . number_format($params['quantity'] * $params['runs']) . ').';
        }
        if ($isSub) {
            if ((int) $service['subscription_enabled'] !== 1 || !SubscriptionService::enabled()) {
                throw new ValidationException('This service does not support auto-subscriptions.');
            }
            $hours = $request->int('sub_interval');
            $cycles = $request->int('sub_cycles');
            if (!isset(SubscriptionService::INTERVALS[$hours])) {
                throw new ValidationException('Choose how often the order should repeat.');
            }
            if ($cycles < 2 || $cycles > SubscriptionService::maxCycles()) {
                throw new ValidationException('Number of deliveries must be between 2 and ' . SubscriptionService::maxCycles() . '.');
            }
            $out['subscription'] = [
                'interval' => SubscriptionService::INTERVALS[$hours],
                'cycles' => $cycles,
                'per_delivery' => money($charge),
                'estimated_total' => money(Money::mul($charge, (string) $cycles)),
            ];
            $out['notes'][] = 'The first delivery is charged now; each later delivery is charged from your balance when it is placed, at the price at that time.';
        }
        return $this->json($out);
    }

    public function store(Request $request): Response
    {
        $user = $this->user();
        $formKey = $request->str('form_key') ?: null;
        if ($request->str('order_type') === 'subscription') {
            $sub = SubscriptionService::create((int) $user['id'], $request->int('service'), $this->orderInput($request), $request->int('sub_interval'), $request->int('sub_cycles'), $formKey ? 'web:' . $formKey : null);
            $msg = !empty($sub['duplicate']) ? 'This subscription was already created (#' . $sub['id'] . ').' : 'Subscription #' . $sub['id'] . ' created — first delivery ordered (order #' . $sub['last_order_id'] . ').';
            if ($request->wantsJson()) {
                return $this->json(['ok' => true, 'type' => 'subscription', 'subscription_id' => (int) $sub['id'], 'order_id' => (int) $sub['last_order_id'], 'message' => $msg, 'url' => url('/subscriptions/' . $sub['id']), 'form_key' => bin2hex(random_bytes(16))]);
            }
            $this->success($msg);
            return $this->redirect('/subscriptions/' . $sub['id']);
        }
        $order = OrderService::place((int) $user['id'], $request->int('service'), $this->orderInput($request), 'web', $formKey);
        if (!empty($order['duplicate'])) {
            $msg = 'This order was already placed (#' . $order['id'] . ').';
            $ok = true;
        } elseif ($order['status'] === 'failed') {
            $msg = 'Order #' . $order['id'] . ' was rejected by the provider and fully refunded.';
            $ok = false;
        } else {
            $msg = 'Order #' . $order['id'] . ' placed — ' . money($order['charge']) . ' charged.';
            $ok = true;
        }
        if ($request->wantsJson()) {
            return $this->json(['ok' => $ok, 'type' => 'order', 'order_id' => (int) $order['id'], 'status' => $order['status'], 'charge' => money($order['charge']), 'balance' => money(\App\Services\WalletService::balance((int) $user['id'])), 'message' => $msg, 'url' => url('/orders/' . $order['id']), 'form_key' => bin2hex(random_bytes(16))]);
        }
        $ok ? $this->success($msg) : $this->error($msg);
        return $this->redirect('/orders/' . $order['id']);
    }

    public function serviceInfo(Request $request, int $id): Response
    {
        $s = Database::instance()->fetch("SELECT id, description FROM services WHERE id = ? AND status = 'active' AND is_hidden = 0", [$id]);
        if (!$s) {
            return $this->json(['error' => 'Not found'], 404);
        }
        // Plain text: the client renders it with textContent.
        return $this->json(['id' => (int) $s['id'], 'description' => trim(strip_tags((string) $s['description']))])->withHeader('Cache-Control', 'private, max-age=300');
    }

    public function massForm(Request $request): Response
    {
        if (setting('mass_order_enabled', '1') !== '1') {
            $this->notFound();
        }
        return $this->view('user/mass-order', ['title' => 'Mass order', 'results' => \App\Core\Session::pull('mass_results', []), 'batchKey' => bin2hex(random_bytes(12))]);
    }

    public function massStore(Request $request): Response
    {
        $user = $this->user();
        $results = OrderService::placeMass((int) $user['id'], (string) ($request->post()['orders'] ?? ''), $request->str('batch_key') ?: bin2hex(random_bytes(12)));
        $ok = count(array_filter($results, static fn ($r) => $r['ok']));
        \App\Core\Session::set('mass_results', $results);
        $ok === count($results) ? $this->success("All {$ok} orders were placed.") : $this->error("{$ok} of " . count($results) . ' orders placed. See details below.');
        return $this->redirect('/mass-order');
    }

    public function catalog(Request $request): Response
    {
        $user = $this->user();
        [$categories, $services] = $this->catalogData($user);
        $q = mb_strtolower(mb_substr($request->str('q'), 0, 100));
        $cat = $request->int('category');
        if ($q !== '' || $cat) {
            $services = array_values(array_filter($services, static fn ($s) => (!$cat || $s['c'] === $cat) && ($q === '' || (string) $s['id'] === $q || str_contains(mb_strtolower($s['n']), $q))));
        }
        $grouped = [];
        $catNames = array_column($categories, 'n', 'id');
        foreach ($services as $s) {
            $grouped[$s['c']]['name'] = $catNames[$s['c']] ?? '';
            $grouped[$s['c']]['services'][] = $s;
        }
        return $this->view('user/catalog', ['title' => 'Services', 'grouped' => $grouped, 'categories' => $categories, 'q' => $q, 'cat' => $cat, 'discount' => OrderService::userDiscount($user)]);
    }

    public function index(Request $request): Response
    {
        $user = $this->user();
        $status = $request->str('status');
        $q = mb_substr($request->str('q'), 0, 200);
        $where = 'WHERE o.user_id = ?';
        $params = [(int) $user['id']];
        $valid = ['pending', 'processing', 'in_progress', 'completed', 'partial', 'cancelled', 'refunded', 'failed'];
        if (in_array($status, $valid, true)) {
            $where .= ' AND o.status = ?';
            $params[] = $status;
        }
        $type = $request->str('type');
        if ($type === 'subscription') {
            $where .= ' AND o.subscription_id IS NOT NULL';
        } elseif ($type === 'single') {
            $where .= ' AND o.subscription_id IS NULL';
        }
        if ($q !== '') {
            if (ctype_digit($q)) {
                $where .= ' AND o.id = ?';
                $params[] = (int) $q;
            } else {
                $where .= ' AND o.link LIKE ?';
                $params[] = \App\Core\Database::like($q);
            }
        }
        $orders = Paginator::query(
            'o.id, o.link, o.quantity, o.charge, o.start_count, o.remains, o.status, o.created_at, o.runs, o.service_id, o.subscription_id, o.subscription_cycle, s.name AS service, s.refill AS can_refill, s.cancel AS can_cancel',
            "FROM orders o JOIN services s ON s.id = o.service_id {$where}",
            $params,
            'o.id DESC',
            $this->pageNum($request),
            25
        );
        $counts = Database::instance()->fetchPairs('SELECT status, COUNT(*) FROM orders WHERE user_id = ? GROUP BY status', [(int) $user['id']]);
        return $this->view('user/orders', ['title' => 'My orders', 'orders' => $orders, 'status' => $status, 'type' => $type, 'q' => $q, 'counts' => $counts]);
    }

    public function show(Request $request, int $id): Response
    {
        $user = $this->user();
        $db = Database::instance();
        $order = $db->fetch(
            'SELECT o.*, s.name AS service, s.refill AS can_refill, s.cancel AS can_cancel, s.refill_days, s.type AS service_type, s.link_label FROM orders o JOIN services s ON s.id = o.service_id WHERE o.id = ? AND o.user_id = ?',
            [$id, (int) $user['id']]
        );
        if (!$order) {
            $this->notFound();
        }
        $logs = $db->fetchAll("SELECT event, new_status, created_at FROM order_logs WHERE order_id = ? AND event IN ('created','submitted','status','refund_partial','refund_cancel','refund_fail','refund_admin','refill_requested','cancel_requested') ORDER BY id", [$id]);
        $refills = $db->fetchAll('SELECT * FROM refills WHERE order_id = ? ORDER BY id DESC', [$id]);
        return $this->view('user/order-show', ['title' => 'Order #' . $id, 'order' => $order, 'logs' => $logs, 'refills' => $refills]);
    }

    public function cancel(Request $request, int $id): Response
    {
        $r = OrderService::requestCancel((int) $this->user()['id'], $id);
        $this->success($r === 'cancelled' ? 'Order cancelled and refunded.' : 'Cancellation requested. The refund will appear once the provider confirms.');
        return $this->redirect('/orders/' . $id);
    }

    public function refill(Request $request, int $id): Response
    {
        OrderService::requestRefill((int) $this->user()['id'], $id);
        $this->success('Refill requested for order #' . $id . '.');
        return $this->back($request, '/orders/' . $id);
    }

    public function refills(Request $request): Response
    {
        $user = $this->user();
        $refills = Paginator::query(
            'r.*, o.link, o.quantity, s.name AS service',
            'FROM refills r JOIN orders o ON o.id = r.order_id JOIN services s ON s.id = o.service_id WHERE r.user_id = ?',
            [(int) $user['id']],
            'r.id DESC',
            $this->pageNum($request),
            25
        );
        return $this->view('user/refills', ['title' => 'Refills', 'refills' => $refills]);
    }
}
