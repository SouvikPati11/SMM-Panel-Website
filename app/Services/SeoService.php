<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Meta tags, canonical URLs, Open Graph, JSON-LD and sitemap generation. */
final class SeoService
{
    /** Account, auth and technical paths: never indexed (robots.txt + X-Robots-Tag). */
    public const PRIVATE_PREFIXES = [
        '/dashboard', '/order', '/orders', '/mass-order', '/subscriptions', '/refills', '/catalog', '/funds', '/transactions',
        '/tickets', '/affiliates', '/account', '/notifications', '/verify-email', '/login', '/register', '/forgot-password',
        '/reset-password', '/2fa', '/logout', '/api/', '/webhooks/', '/tasks/', '/install', '/ref/',
    ];

    public static function isPrivatePath(string $path): bool
    {
        $admin = '/' . admin_path();
        if ($path === $admin || str_starts_with($path, $admin . '/')) {
            return true;
        }
        foreach (self::PRIVATE_PREFIXES as $p) {
            $bare = rtrim($p, '/');
            if ($path === $bare || str_starts_with($path, $bare . '/')) {
                return true;
            }
        }
        return false;
    }

    /** Default share image (1200×630) used when no custom Open Graph image is uploaded. */
    public static function defaultImage(): string
    {
        return url('/assets/img/og-default.jpg');
    }
    public static function meta(array $page = []): array
    {
        $site = site_name();
        $title = $page['title'] ?? null;
        return [
            'title' => $title ? $title . ' — ' . $site : (string) setting('seo_title', $site),
            'description' => mb_substr(trim(strip_tags((string) ($page['description'] ?? setting('seo_description', '')))), 0, 300),
            'canonical' => $page['canonical'] ?? url(\App\Core\App::request()?->path() ?? '/'),
            'image' => $page['image'] ?? (setting('seo_og_image') ? upload_url((string) setting('seo_og_image')) : self::defaultImage()),
            'type' => $page['type'] ?? 'website',
            'robots' => $page['robots'] ?? 'index,follow',
            'jsonld' => $page['jsonld'] ?? [],
        ];
    }

    public static function organizationJsonLd(): array
    {
        $sameAs = array_values(array_filter(
            array_map(static fn ($k) => trim((string) setting($k, '')), ['social_facebook', 'social_instagram', 'social_x', 'social_youtube', 'social_telegram', 'social_tiktok']),
            static fn ($u) => (bool) preg_match('#^https?://#i', $u)
        ));
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
            ['/contact', '0.4', null], // login/register are noindex, so they are not listed
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
        // The admin path is deliberately NOT listed: robots.txt is public and would reveal it
        // (admin pages send X-Robots-Tag: noindex instead).
        $txt = "User-agent: *\n";
        foreach (self::PRIVATE_PREFIXES as $p) {
            $txt .= 'Disallow: ' . $p . "\n";
        }
        $extra = trim((string) setting('seo_robots_extra', ''));
        if ($extra !== '') {
            $txt .= $extra . "\n";
        }
        return $txt . "\nSitemap: " . url('/sitemap.xml') . "\n";
    }
}
