<?php

declare(strict_types=1);

use App\Core\Database;

/* Homepage content + SEO: metadata, structured data, indexing rules. */

$seoDb = Database::instance();
$seoCat = $seoDb->insert('categories', ['name' => 'Instagram Likes SEO', 'slug' => 'seo-ig', 'status' => 'active', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
Fx::service(null, ['name' => 'IG Likes SEO test', 'category_id' => $seoCat]);
$seoDb->insert('faqs', ['question' => 'How fast is delivery?', 'answer' => 'Most orders start within minutes.', 'status' => 'active', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
$auth = static function (): void {
    App\Services\Auth::logoutUser();
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
};

T::test('Home: one h1, section h2s, hero image with alt text, register/login CTAs, supported platforms', function () use ($auth) {
    $auth();
    $r = http('GET', '/');
    T::eq(200, $r->status());
    $html = $r->body();
    T::eq(1, preg_match_all('/<h1[\s>]/', $html), 'exactly one h1');
    T::true(preg_match_all('/<h2[\s>]/', $html) >= 4, 'sections use h2');
    T::true((bool) preg_match('~<img[^>]+hero-growth\.svg[^>]+alt="[^"]{30,}"~', $html), 'hero illustration has meaningful alt text');
    T::true((bool) preg_match('~<img[^>]+hero-growth\.svg[^>]+width="480" height="420"~', $html), 'explicit dimensions (no layout shift)');
    T::true(str_contains($html, 'href="https://panel.test/register"') && str_contains($html, 'href="https://panel.test/login"'));
    T::true(str_contains($html, 'Supported social platforms') && str_contains($html, 'platform-card'));
    T::true(str_contains($html, 'Why choose') && str_contains($html, 'How it works') && str_contains($html, 'Frequently asked questions'));
    T::true(is_file(PUBLIC_PATH . '/assets/img/hero-growth.svg') && filesize(PUBLIC_PATH . '/assets/img/hero-growth.svg') < 20000, 'lightweight vector image');
});

T::test('Home SEO: title, description, canonical, Open Graph, Twitter card and valid JSON-LD', function () use ($auth) {
    $auth();
    $html = http('GET', '/')->body();
    $meta = static fn (string $attr, string $name) => preg_match('~<meta ' . $attr . '="' . preg_quote($name, '~') . '" content="([^"]*)"~', $html, $m) ? html_entity_decode($m[1]) : null;
    T::true((bool) preg_match('~<title>[^<]{10,}</title>~', $html));
    T::true(strlen((string) $meta('name', 'description')) >= 50, 'meta description');
    T::true(str_contains($html, '<link rel="canonical" href="https://panel.test/">'));
    T::eq('https://panel.test/', $meta('property', 'og:url'));
    T::true($meta('property', 'og:title') !== null && $meta('property', 'og:description') !== null);
    T::eq('https://panel.test/assets/img/og-default.jpg', $meta('property', 'og:image'), 'default share image');
    T::eq(['1200', '630'], [$meta('property', 'og:image:width'), $meta('property', 'og:image:height')]);
    T::eq('summary_large_image', $meta('name', 'twitter:card'));
    T::true($meta('name', 'twitter:title') !== null && $meta('name', 'twitter:description') !== null);
    T::eq('index,follow', $meta('name', 'robots'));
    preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);
    $types = array_map(static fn ($j) => json_decode($j, true, 512, JSON_THROW_ON_ERROR)['@type'], $m[1]);
    T::eq(['Organization', 'WebSite', 'FAQPage'], $types);
    T::true(str_contains($m[1][2], 'How fast is delivery?'), 'FAQ structured data matches the visible FAQ');
    [$w, $h] = getimagesize(PUBLIC_PATH . '/assets/img/og-default.jpg');
    T::eq([1200, 630], [$w, $h]);
    T::true(filesize(PUBLIC_PATH . '/assets/img/og-default.jpg') < 150000, 'optimized share image');
});

T::test('SEO: canonical ignores query strings (no duplicate canonicals)', function () use ($auth) {
    $auth();
    T::true(str_contains(http('GET', '/', ['ref' => 'abc', 'utm_source' => 'x'])->body(), '<link rel="canonical" href="https://panel.test/">'));
    T::true(str_contains(http('GET', '/services', ['q' => 'likes'])->body(), '<link rel="canonical" href="https://panel.test/services">'));
});

T::test('SEO: robots.txt and sitemap.xml agree; private pages are never indexable', function () use ($auth) {
    $auth();
    $robots = http('GET', '/robots.txt')->body();
    foreach (['/dashboard', '/order', '/subscriptions', '/funds', '/account', '/login', '/register', '/api/', '/webhooks/', '/tasks/', '/install'] as $p) {
        T::true(str_contains($robots, "Disallow: {$p}\n"), "robots disallows {$p}");
    }
    T::true(!str_contains($robots, '/' . admin_path()), 'admin path not revealed');
    T::true(str_contains($robots, 'Sitemap: https://panel.test/sitemap.xml'));
    $sitemap = http('GET', '/sitemap.xml')->body();
    $xml = simplexml_load_string($sitemap);
    T::true($xml !== false, 'valid XML');
    $locs = [];
    foreach ($xml->url as $u) {
        $locs[] = (string) $u->loc;
    }
    T::true(in_array('https://panel.test/', $locs, true) && in_array('https://panel.test/services', $locs, true));
    foreach ($locs as $loc) {
        T::true(!App\Services\SeoService::isPrivatePath((string) parse_url($loc, PHP_URL_PATH)), "sitemap lists no private URL: {$loc}");
    }
    T::eq(count($locs), count(array_unique($locs)), 'no duplicate URLs');
});

T::test('SEO: account, auth and admin responses carry X-Robots-Tag noindex; public pages do not', function () use ($auth) {
    $auth();
    T::eq(null, http('GET', '/')->header('X-Robots-Tag'));
    T::eq(null, http('GET', '/services')->header('X-Robots-Tag'));
    foreach (['/login', '/register', '/dashboard', '/order', '/' . admin_path() . '/login', '/' . admin_path()] as $p) {
        T::eq('noindex, nofollow', http('GET', $p)->header('X-Robots-Tag'), $p);
    }
    $u = Fx::user('1');
    login_as_user($u);
    $dash = http('GET', '/dashboard');
    T::eq('noindex, nofollow', $dash->header('X-Robots-Tag'));
    T::true(str_contains($dash->body(), '<meta name="robots" content="noindex,nofollow">'));
});
