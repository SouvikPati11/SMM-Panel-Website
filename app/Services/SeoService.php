<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Meta tags, canonical URLs, Open Graph, JSON-LD and sitemap generation. */
final class SeoService
{
    public static function meta(array $page = []): array
    {
        $site = site_name();
        $title = $page['title'] ?? null;
        return [
            'title' => $title ? $title . ' — ' . $site : (string) setting('seo_title', $site),
            'description' => mb_substr(trim(strip_tags((string) ($page['description'] ?? setting('seo_description', '')))), 0, 300),
            'canonical' => $page['canonical'] ?? url(\App\Core\App::request()?->path() ?? '/'),
            'image' => $page['image'] ?? (setting('seo_og_image') ? upload_url((string) setting('seo_og_image')) : ''),
            'type' => $page['type'] ?? 'website',
            'robots' => $page['robots'] ?? 'index,follow',
            'jsonld' => $page['jsonld'] ?? [],
        ];
    }

    public static function organizationJsonLd(): array
    {
        $sameAs = array_values(array_filter([
            setting('social_facebook'), setting('social_instagram'), setting('social_x'), setting('social_youtube'), setting('social_telegram'),
        ]));
        $org = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => site_name(), 'url' => url('/')];
        if (setting('site_logo')) {
            $org['logo'] = upload_url((string) setting('site_logo'));
        }
        if ($sameAs) {
            $org['sameAs'] = $sameAs;
        }
        return $org;
    }

    public static function breadcrumbJsonLd(array $crumbs): array
    {
        $items = [];
        foreach (array_values($crumbs) as $i => [$name, $link]) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => url($link)];
        }
        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    public static function faqJsonLd(array $faqs): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static fn ($f) => [
                '@type' => 'Question',
                'name' => $f['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags((string) $f['answer'])],
            ], $faqs),
        ];
    }

    public static function sitemap(): string
    {
        $db = Database::instance();
        $urls = [
            ['/', '1.0', null], ['/services', '0.9', null], ['/faq', '0.6', null], ['/api-docs', '0.6', null],
            ['/contact', '0.4', null], ['/login', '0.3', null], ['/register', '0.5', null],
        ];
        foreach ($db->fetchAll("SELECT slug, updated_at FROM pages WHERE status = 'published'") as $p) {
            $urls[] = ['/page/' . $p['slug'], '0.4', $p['updated_at']];
        }
        if (setting('blog_enabled', '1') === '1') {
            $urls[] = ['/blog', '0.7', null];
            foreach ($db->fetchAll("SELECT slug, updated_at FROM blog_posts WHERE status = 'published' AND published_at <= ? ORDER BY published_at DESC LIMIT 5000", [now()]) as $p) {
                $urls[] = ['/blog/' . $p['slug'], '0.6', $p['updated_at']];
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as [$path, $prio, $mod]) {
            $xml .= '  <url><loc>' . htmlspecialchars(url($path), ENT_XML1) . '</loc>'
                . ($mod ? '<lastmod>' . gmdate('Y-m-d', strtotime($mod . ' UTC')) . '</lastmod>' : '')
                . '<priority>' . $prio . '</priority></url>' . "\n";
        }
        return $xml . '</urlset>' . "\n";
    }

    public static function robots(): string
    {
        $admin = admin_path();
        $txt = "User-agent: *\nDisallow: /{$admin}/\nDisallow: /dashboard\nDisallow: /orders\nDisallow: /funds\nDisallow: /tickets\nDisallow: /account\nDisallow: /api/\nDisallow: /webhooks/\nDisallow: /install\n";
        $extra = trim((string) setting('seo_robots_extra', ''));
        if ($extra !== '') {
            $txt .= $extra . "\n";
        }
        return $txt . "\nSitemap: " . url('/sitemap.xml') . "\n";
    }
}
