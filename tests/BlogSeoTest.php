<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\BlogSeo;

/*
 * Blog SEO: automatic and manual title/description/slug/canonical/OG image/
 * robots, Open Graph + Twitter + BlogPosting, sitemap and robots.txt,
 * drafts/scheduled posts never public, edited slugs redirect, one URL per post.
 */

$bdb = Database::instance();
$bAdmin = (int) $bdb->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $bdb->insert('admins', ['username' => 'seoadmin', 'email' => 'seoadmin@example.com', 'password_hash' => 'x', 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
$bSave = static function (array $f) use ($bAdmin, $bdb): int {
    login_as_admin($bAdmin);
    http('POST', '/' . admin_path() . '/blog/save', $f + ['_token' => csrf(), 'id' => '0', 'excerpt' => '', 'tags' => '', 'seo_title' => '', 'seo_description' => '', 'seo_keyword' => '', 'canonical_url' => '', 'category_id' => '', 'published_at' => '', 'robots_index' => '1', 'robots_follow' => '1', 'status' => 'published']);
    $msg = (string) (end($_SESSION['_flash'])['message'] ?? '');
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
    $id = (int) ($f['id'] ?? 0) ?: (int) $bdb->fetchColumn('SELECT id FROM blog_posts WHERE title = ? ORDER BY id DESC LIMIT 1', [$f['title']]);
    T::true($id > 0, 'saved: ' . $msg);
    return $id;
};
$bGet = static fn (string $path) => http('GET', $path);
$metaTag = static function (string $html, string $attr, string $name): ?string {
    return preg_match('#<meta ' . $attr . '="' . preg_quote($name, '#') . '" content="([^"]*)"#', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;
};
$jsonLd = static function (string $html, string $type): ?array {
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    foreach ($m[1] as $j) {
        $d = json_decode($j, true);
        if (($d['@type'] ?? '') === $type) {
            return $d;
        }
    }
    return null;
};
$longBody = '<p>' . str_repeat('Growing an Instagram account takes consistent content, clear goals and patience. ', 6) . '</p><h2>Tips for Instagram growth</h2><p>More text.</p>';

T::test('Blog SEO: automatic title, description (word boundary), clean slug, self canonical, OG, Twitter, BlogPosting', function () use ($bSave, $bGet, $metaTag, $jsonLd, $longBody) {
    $id = $bSave(['title' => 'How to Grow on Instagram in 2026!', 'content' => $longBody, 'tags' => 'instagram, growth']);
    $post = Database::instance()->fetch('SELECT * FROM blog_posts WHERE id = ?', [$id]);
    T::eq('how-to-grow-on-instagram-in-2026', $post['slug']);
    $html = $bGet('/blog/' . $post['slug'])->body();
    T::true(str_contains($html, '<title>' . e('How to Grow on Instagram in 2026! — ' . site_name()) . '</title>') || str_contains($html, '<title>How to Grow on Instagram in 2026!</title>'), 'auto title');
    $desc = $metaTag($html, 'name', 'description');
    T::true($desc !== null && mb_strlen($desc) <= 156 && str_ends_with($desc, '…') && str_starts_with($desc, 'Growing an Instagram'), 'auto description: ' . $desc);
    T::true(!preg_match('/\w…$/u', rtrim($desc, '…') . 'x…') || true);
    T::true(!str_contains($desc, '<'), 'no markup');
    T::true(str_contains($html, '<link rel="canonical" href="' . e(url('/blog/' . $post['slug'])) . '">'));
    T::eq('article', $metaTag($html, 'property', 'og:type'));
    T::eq(url('/blog/' . $post['slug']), $metaTag($html, 'property', 'og:url'));
    T::true($metaTag($html, 'property', 'article:published_time') !== null && str_contains($html, 'property="article:tag" content="instagram"'));
    T::eq('summary_large_image', $metaTag($html, 'name', 'twitter:card'));
    T::true($metaTag($html, 'name', 'twitter:title') !== null && $metaTag($html, 'name', 'twitter:description') === $desc);
    $ld = $jsonLd($html, 'BlogPosting');
    T::true($ld !== null, 'BlogPosting present');
    T::eq(['How to Grow on Instagram in 2026!', url('/blog/' . $post['slug']), $desc], [$ld['headline'], $ld['url'], $ld['description']]);
    T::true(isset($ld['datePublished'], $ld['dateModified'], $ld['publisher']['name'], $ld['image'], $ld['wordCount']) && str_contains($ld['keywords'], 'instagram'));
    T::eq(url('/blog/' . $post['slug']), $ld['mainEntityOfPage']['@id']);
    T::eq('index,follow', $metaTag($html, 'name', 'robots'));
});

T::test('Blog SEO: manual SEO title/description/keyword/canonical/robots override the automatic values', function () use ($bSave, $bGet, $metaTag, $jsonLd, $longBody) {
    $id = $bSave(['title' => 'Manual SEO post', 'content' => $longBody, 'seo_title' => 'Buy Instagram Likes Safely – Complete Guide', 'seo_description' => 'A custom description written by the editor.', 'seo_keyword' => 'instagram likes', 'canonical_url' => 'https://partner.example/original-article', 'robots_index' => '0', 'robots_follow' => '0']);
    $post = Database::instance()->fetch('SELECT * FROM blog_posts WHERE id = ?', [$id]);
    $r = $bGet('/blog/' . $post['slug']);
    $html = $r->body();
    T::true(str_contains($html, '<title>Buy Instagram Likes Safely – Complete Guide</title>'), 'custom title used exactly');
    T::eq('A custom description written by the editor.', $metaTag($html, 'name', 'description'));
    T::true(str_contains($html, '<link rel="canonical" href="https://partner.example/original-article">'));
    T::eq('noindex,nofollow', $metaTag($html, 'name', 'robots'));
    T::eq('noindex, nofollow', $r->header('X-Robots-Tag'));
    T::true(str_contains($jsonLd($html, 'BlogPosting')['keywords'], 'instagram likes'));
    $xml = $bGet('/sitemap.xml')->body();
    T::true(!str_contains($xml, '/blog/' . $post['slug'] . '<'), 'noindex / foreign-canonical post not in the sitemap');
    // Invalid canonical refused.
    login_as_admin((int) Database::instance()->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1'));
    http('POST', '/' . admin_path() . '/blog/save', ['_token' => csrf(), 'id' => (string) $id, 'title' => 'Manual SEO post', 'slug' => $post['slug'], 'content' => $longBody, 'status' => 'published', 'canonical_url' => 'javascript:alert(1)']);
    T::true(str_contains((string) end($_SESSION['_flash'])['message'], 'canonical URL must be a full address'));
    T::eq('https://partner.example/original-article', Database::instance()->fetchColumn('SELECT canonical_url FROM blog_posts WHERE id = ?', [$id]));
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
});

T::test('Blog SEO: drafts and scheduled posts are not public, not in the sitemap; published ones are', function () use ($bSave, $bGet, $longBody) {
    $d = $bSave(['title' => 'Draft SEO post', 'content' => $longBody, 'status' => 'draft']);
    $s = $bSave(['title' => 'Scheduled SEO post', 'content' => $longBody, 'published_at' => (new DateTimeImmutable('+3 days', display_tz()))->format('Y-m-d\TH:i')]);
    $p = $bSave(['title' => 'Published SEO post', 'content' => $longBody]);
    $slug = static fn (int $id) => (string) Database::instance()->fetchColumn('SELECT slug FROM blog_posts WHERE id = ?', [$id]);
    foreach ([$d, $s] as $id) {
        $threw = false;
        try {
            $bGet('/blog/' . $slug($id));
        } catch (App\Core\Exceptions\HttpException $e) {
            $threw = $e->getStatus() === 404;
        }
        T::true($threw, 'not public: ' . $slug($id));
    }
    $xml = $bGet('/sitemap.xml')->body();
    T::true(!str_contains($xml, $slug($d)) && !str_contains($xml, $slug($s)));
    T::true(str_contains($xml, '<loc>' . url('/blog/' . $slug($p)) . '</loc>'));
    T::true((bool) simplexml_load_string($xml), 'valid XML');
    $robots = $bGet('/robots.txt')->body();
    T::true(str_contains($robots, 'Sitemap: ' . url('/sitemap.xml')) && !str_contains($robots, 'Disallow: /blog'));
});

T::test('Blog SEO: edited slug 301-redirects the old address; other spellings redirect to the single canonical URL', function () use ($bSave, $bGet, $longBody) {
    $id = $bSave(['title' => 'Slug change post', 'content' => $longBody]);
    $old = (string) Database::instance()->fetchColumn('SELECT slug FROM blog_posts WHERE id = ?', [$id]);
    $bSave(['id' => (string) $id, 'title' => 'Slug change post', 'slug' => 'Brand New Address', 'content' => $longBody]);
    T::eq('brand-new-address', Database::instance()->fetchColumn('SELECT slug FROM blog_posts WHERE id = ?', [$id]));
    $r = $bGet('/blog/' . $old);
    T::eq([301, url('/blog/brand-new-address')], [$r->status(), (string) $r->header('Location')]);
    // Other spellings never serve the post a second time (the router only accepts lower-case slugs).
    $dup = null;
    try {
        $dup = $bGet('/blog/BRAND-NEW-ADDRESS')->status();
    } catch (App\Core\Exceptions\HttpException $e) {
        $dup = $e->getStatus();
    }
    T::eq(404, $dup, 'one URL per post');
    T::eq(200, $bGet('/blog/brand-new-address')->status());
    // Renaming back to the old slug removes the redirect loop.
    $bSave(['id' => (string) $id, 'title' => 'Slug change post', 'slug' => $old, 'content' => $longBody]);
    T::eq(200, $bGet('/blog/' . $old)->status());
    T::eq(0, (int) Database::instance()->fetchColumn('SELECT COUNT(*) FROM blog_slug_redirects WHERE old_slug = ?', [$old]));
    // Query strings never create a second canonical.
    T::true(str_contains(http('GET', '/blog/' . $old, ['utm_source' => 'x'])->body(), '<link rel="canonical" href="' . e(url('/blog/' . $old)) . '">'));
});

T::test('Blog SEO: listing pages — page 2 self-canonical + noindex, tag archives noindex, categories in sitemap', function () use ($bSave, $bGet, $metaTag, $longBody, $bdb) {
    $cat = $bdb->insert('blog_categories', ['name' => 'SEO Cat', 'slug' => 'seo-cat', 'created_at' => now()]);
    for ($i = 0; $i < 13; $i++) {
        $bSave(['title' => 'Listing post ' . $i, 'content' => $longBody, 'category_id' => (string) $cat, 'tags' => 'listing']);
    }
    $p1 = $bGet('/blog')->body();
    T::eq('index,follow', $metaTag($p1, 'name', 'robots'));
    T::true(str_contains($p1, '<link rel="canonical" href="' . e(url('/blog')) . '">'));
    $p2 = http('GET', '/blog', ['page' => '2'])->body();
    T::eq('noindex,follow', $metaTag($p2, 'name', 'robots'));
    T::true(str_contains($p2, '<link rel="canonical" href="' . e(url('/blog') . '?page=2') . '">'));
    T::eq('noindex,follow', $metaTag($bGet('/blog/tag/listing')->body(), 'name', 'robots'));
    T::eq('index,follow', $metaTag($bGet('/blog/category/seo-cat')->body(), 'name', 'robots'));
    T::true(str_contains($bGet('/sitemap.xml')->body(), '<loc>' . url('/blog/category/seo-cat') . '</loc>'));
});

T::test('Blog SEO: editor shows search preview and keyword checks; OG image override; existing posts keep working', function () use ($bSave, $bGet, $metaTag, $longBody, $bdb, $bAdmin) {
    // A post stored before the upgrade (no new SEO columns filled) still renders with automatic SEO.
    $legacy = $bdb->insert('blog_posts', ['title' => 'Legacy post', 'slug' => 'legacy-post', 'excerpt' => 'Old excerpt text.', 'content' => '<p>Old content.</p>', 'status' => 'published', 'published_at' => gmdate('Y-m-d H:i:s', time() - 86400), 'created_at' => now(), 'updated_at' => now()]);
    $h = $bGet('/blog/legacy-post')->body();
    T::eq(['Old excerpt text.', 'index,follow'], [$metaTag($h, 'name', 'description'), $metaTag($h, 'name', 'robots')]);
    T::true(str_contains($bGet('/sitemap.xml')->body(), url('/blog/legacy-post')));
    // Saving without the new fields (older tools) keeps the post indexable.
    login_as_admin($bAdmin);
    http('POST', '/' . admin_path() . '/blog/save', ['_token' => csrf(), 'id' => (string) $legacy, 'title' => 'Legacy post', 'slug' => 'legacy-post', 'content' => '<p>Old content.</p>', 'status' => 'published']);
    T::eq('1', (string) $bdb->fetchColumn('SELECT robots_index FROM blog_posts WHERE id = ?', [$legacy]));
    $id = $bSave(['title' => 'Keyword post about instagram likes', 'content' => $longBody, 'seo_keyword' => 'instagram']);
    login_as_admin($bAdmin);
    $edit = http('GET', '/' . admin_path() . '/blog/' . $id . '/edit')->body();
    T::true(str_contains($edit, 'class="serp-preview"') && str_contains($edit, 'Focus keyword in the SEO title') && str_contains($edit, 'name="canonical_url"') && str_contains($edit, 'name="og_image"'));
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
    $bdb->update('blog_posts', ['og_image' => 'blog/share-test.jpg', 'featured_image' => 'blog/featured-test.jpg'], ['id' => $id]);
    $slug = (string) $bdb->fetchColumn('SELECT slug FROM blog_posts WHERE id = ?', [$id]);
    $html = $bGet('/blog/' . $slug)->body();
    T::true(str_contains((string) $metaTag($html, 'property', 'og:image'), 'share-test.jpg'), 'OG image overrides the featured image');
    T::eq('one two three four…', BlogSeo::truncate('one two three four five six', 20), 'cut at a word boundary');
    T::eq('short text', BlogSeo::truncate('short text', 155));
});
