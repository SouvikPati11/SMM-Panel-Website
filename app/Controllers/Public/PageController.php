<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\HtmlSanitizer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\MailService;
use App\Services\OrderService;
use App\Services\SeoService;

final class PageController extends Controller
{
    public function home(Request $request): Response
    {
        $db = Database::instance();
        $stats = [
            'services' => (int) $db->fetchColumn("SELECT COUNT(*) FROM services WHERE status = 'active' AND is_hidden = 0"),
            'orders' => (int) $db->fetchColumn('SELECT COUNT(*) FROM orders'),
            'categories' => (int) $db->fetchColumn("SELECT COUNT(*) FROM categories WHERE status = 'active'"),
        ];
        $popular = $db->fetchAll(
            "SELECT s.id, s.name, s.rate, s.type, c.name AS category FROM services s JOIN categories c ON c.id = s.category_id
             WHERE s.status = 'active' AND s.is_hidden = 0 AND c.status = 'active' ORDER BY s.sort_order, s.id LIMIT 5"
        );
        $faqs = $db->fetchAll("SELECT * FROM faqs WHERE status = 'active' ORDER BY sort_order, id LIMIT 5");
        $posts = setting('blog_enabled', '1') === '1'
            ? $db->fetchAll("SELECT id, title, slug, excerpt, featured_image, published_at FROM blog_posts WHERE status = 'published' AND published_at <= ? ORDER BY published_at DESC LIMIT 3", [now()])
            : [];
        // Supported platforms = what the live catalog actually offers (no invented claims).
        $platforms = [];
        foreach ($db->fetchAll("SELECT c.id, c.name, COUNT(s.id) AS n FROM categories c JOIN services s ON s.category_id = c.id AND s.status = 'active' AND s.is_hidden = 0 WHERE c.status = 'active' GROUP BY c.id, c.name ORDER BY c.sort_order, c.name") as $c) {
            $key = \App\Helpers\Platforms::detect($c['name']);
            $platforms[$key] ??= ['key' => $key, 'label' => \App\Helpers\Platforms::label($key), 'services' => 0, 'category' => (int) $c['id']];
            $platforms[$key]['services'] += (int) $c['n'];
        }
        uasort($platforms, static fn ($a, $b) => ($a['key'] === 'other') <=> ($b['key'] === 'other') ?: $b['services'] <=> $a['services']);
        $jsonld = [
            SeoService::organizationJsonLd(),
            ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => site_name(), 'url' => url('/')],
        ];
        if ($faqs) {
            $jsonld[] = SeoService::faqJsonLd($faqs); // same questions as shown on the page
        }
        $meta = SeoService::meta(['canonical' => url('/'), 'jsonld' => $jsonld]);
        return $this->view('public/home', compact('stats', 'popular', 'faqs', 'posts', 'meta', 'platforms'));
    }

    public function services(Request $request): Response
    {
        $db = Database::instance();
        $q = mb_substr($request->str('q'), 0, 100);
        $cat = $request->int('category');
        $where = "s.status = 'active' AND s.is_hidden = 0 AND c.status = 'active'";
        $params = [];
        if ($q !== '') {
            $where .= ' AND (s.name LIKE ? OR s.id = ?)';
            $params[] = Database::like($q);
            $params[] = ctype_digit($q) ? (int) $q : 0;
        }
        if ($cat > 0) {
            $where .= ' AND c.id = ?';
            $params[] = $cat;
        }
        $rows = $db->fetchAll(
            "SELECT s.id, s.name, s.rate, s.min_quantity, s.max_quantity, s.type, s.refill, s.cancel, s.dripfeed, s.subscription_enabled, s.average_time, c.id AS cat_id, c.name AS cat_name
             FROM services s JOIN categories c ON c.id = s.category_id WHERE {$where} ORDER BY c.sort_order, c.name, s.sort_order, s.id LIMIT 3000",
            $params
        );
        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['cat_id']]['name'] = $r['cat_name'];
            $grouped[$r['cat_id']]['services'][] = $r;
        }
        $categories = $db->fetchPairs("SELECT id, name FROM categories WHERE status = 'active' ORDER BY sort_order, name");
        $meta = SeoService::meta([
            'title' => 'Services & Pricing',
            'description' => 'Browse our full catalog of social media services with transparent per-1000 pricing, minimum and maximum quantities and refill guarantees.',
            'jsonld' => [SeoService::breadcrumbJsonLd([['Home', '/'], ['Services', '/services']])],
        ]);
        return $this->view('public/services', compact('grouped', 'categories', 'q', 'cat', 'meta'));
    }

    public function faq(Request $request): Response
    {
        $faqs = Database::instance()->fetchAll("SELECT * FROM faqs WHERE status = 'active' ORDER BY sort_order, id");
        $meta = SeoService::meta(['title' => 'Frequently Asked Questions', 'description' => 'Answers to common questions about orders, payments, refills and the API.', 'jsonld' => $faqs ? [SeoService::faqJsonLd($faqs)] : []]);
        return $this->view('public/faq', compact('faqs', 'meta'));
    }

    public function about(Request $request): Response
    {
        return $this->renderPage('about');
    }

    public function terms(Request $request): Response
    {
        return $this->renderPage('terms');
    }

    public function privacy(Request $request): Response
    {
        return $this->renderPage('privacy');
    }

    public function refund(Request $request): Response
    {
        return $this->renderPage('refund-policy');
    }

    public function page(Request $request, string $slug): Response
    {
        return $this->renderPage($slug);
    }

    private function renderPage(string $slug): Response
    {
        $page = Database::instance()->fetch("SELECT * FROM pages WHERE slug = ? AND status = 'published'", [$slug]);
        if (!$page) {
            $this->notFound();
        }
        $canonical = in_array($slug, ['about', 'terms', 'privacy'], true) ? '/' . $slug : ($slug === 'refund-policy' ? '/refund-policy' : '/page/' . $slug);
        $meta = SeoService::meta([
            'title' => $page['seo_title'] ?: $page['title'],
            'description' => $page['seo_description'] ?: mb_substr(strip_tags((string) $page['content']), 0, 160),
            'canonical' => url($canonical),
            'jsonld' => [SeoService::breadcrumbJsonLd([['Home', '/'], [$page['title'], $canonical]])],
        ]);
        $page['content'] = HtmlSanitizer::clean($page['content']);
        return $this->view('public/page', compact('page', 'meta'));
    }

    public function contact(Request $request): Response
    {
        $meta = SeoService::meta(['title' => 'Contact us', 'description' => 'Get in touch with our support team.']);
        return $this->view('public/contact', compact('meta'));
    }

    public function sendContact(Request $request): Response
    {
        // Honeypot field: bots fill hidden inputs.
        if ($request->str('website') !== '') {
            $this->success('Thanks! Your message has been sent.');
            return $this->redirect('/contact');
        }
        $data = Validator::check($request->all(), [
            'name' => 'required|max:100',
            'email' => 'required|email',
            'subject' => 'required|min:3|max:150',
            'message' => 'required|min:10|max:3000',
        ]);
        $to = (string) setting('contact_email', '');
        if ($to === '') {
            throw new ValidationException('The contact form is not configured yet. Please use the support ticket system.');
        }
        MailService::queue($to, '[Contact] ' . $data['subject'], '<p><strong>From:</strong> ' . e($data['name']) . ' &lt;' . e($data['email']) . '&gt;<br><strong>IP:</strong> ' . e($request->ip()) . '</p><p>' . nl2br(e($data['message'])) . '</p>');
        $this->success('Thanks! Your message has been sent. We usually reply within 24 hours.');
        return $this->redirect('/contact');
    }

    public function apiDocs(Request $request): Response
    {
        $meta = SeoService::meta(['title' => 'API Documentation', 'description' => 'Reseller API documentation: place orders, check statuses, request refills and read your balance programmatically.']);
        $example = Database::instance()->fetch("SELECT id FROM services WHERE status = 'active' AND is_hidden = 0 ORDER BY id LIMIT 1");
        return $this->view('public/api-docs', ['meta' => $meta, 'exampleService' => $example['id'] ?? 1, 'types' => OrderService::TYPES]);
    }

    public function sitemap(Request $request): Response
    {
        return Response::text(SeoService::sitemap(), 200, 'application/xml')->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function robots(Request $request): Response
    {
        return Response::text(SeoService::robots())->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
