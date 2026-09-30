<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HtmlSanitizer;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\BlogSeo;
use App\Services\SeoService;

final class BlogController extends Controller
{
    private function guard(): void
    {
        if (setting('blog_enabled', '1') !== '1') {
            $this->notFound();
        }
    }

    public function index(Request $request): Response
    {
        $this->guard();
        return $this->listing($request, '', [], 'Blog', 'Guides, tips and updates on social media growth.', '/blog');
    }

    public function category(Request $request, string $slug): Response
    {
        $this->guard();
        $cat = Database::instance()->fetch('SELECT * FROM blog_categories WHERE slug = ?', [$slug]);
        if (!$cat) {
            $this->notFound();
        }
        return $this->listing($request, ' AND p.category_id = ?', [$cat['id']], $cat['name'], 'Articles in ' . $cat['name'], '/blog/category/' . $cat['slug']);
    }

    public function tag(Request $request, string $slug): Response
    {
        $this->guard();
        $tag = Database::instance()->fetch('SELECT * FROM blog_tags WHERE slug = ?', [$slug]);
        if (!$tag) {
            $this->notFound();
        }
        // Tag archives mostly repeat category content: crawlable (follow) but not indexed.
        return $this->listing($request, ' AND p.id IN (SELECT post_id FROM blog_post_tags WHERE tag_id = ?)', [$tag['id']], '#' . $tag['name'], 'Articles tagged ' . $tag['name'], '/blog/tag/' . $tag['slug'], false);
    }

    private function listing(Request $request, string $extraWhere, array $params, string $heading, string $description, string $path, bool $indexable = true): Response
    {
        $posts = Paginator::query(
            'p.id, p.title, p.slug, p.excerpt, p.featured_image, p.published_at, c.name AS category, c.slug AS category_slug',
            "FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id WHERE p.status = 'published' AND p.published_at <= ?" . $extraWhere,
            array_merge([now()], $params),
            'p.published_at DESC',
            $this->pageNum($request),
            12
        );
        $categories = Database::instance()->fetchAll('SELECT c.name, c.slug, COUNT(p.id) AS n FROM blog_categories c JOIN blog_posts p ON p.category_id = c.id AND p.status = \'published\' AND p.published_at <= ? GROUP BY c.id ORDER BY c.name', [now()]);
        // Each page is canonical to itself (page 2+ keeps ?page=N) and only page 1 is indexed.
        $meta = SeoService::meta([
            'title' => $heading . ($posts->page > 1 ? ' — page ' . $posts->page : ''),
            'description' => $description,
            'canonical' => url($path) . ($posts->page > 1 ? '?page=' . $posts->page : ''),
            'robots' => $indexable && $posts->page === 1 ? 'index,follow' : 'noindex,follow',
        ]);
        return $this->view('public/blog/index', compact('posts', 'categories', 'heading', 'description', 'meta'));
    }

    public function show(Request $request, string $slug): Response
    {
        $this->guard();
        $db = Database::instance();
        $post = $db->fetch(
            "SELECT p.*, c.name AS category, c.slug AS category_slug, a.name AS author FROM blog_posts p
             LEFT JOIN blog_categories c ON c.id = p.category_id LEFT JOIN admins a ON a.id = p.admin_id
             WHERE p.slug = ? AND p.status = 'published' AND p.published_at <= ?",
            [$slug, now()]
        );
        if (!$post) {
            // An edited slug keeps working: the old address redirects permanently to the new one.
            $to = $db->fetchColumn(
                "SELECT p.slug FROM blog_slug_redirects r JOIN blog_posts p ON p.id = r.post_id WHERE r.old_slug = ? AND p.status = 'published' AND p.published_at <= ?",
                [$slug, now()]
            );
            if ($to) {
                return Response::redirect(url('/blog/' . rawurlencode((string) $to)), 301);
            }
            $this->notFound();
        }
        if ($post['slug'] !== $slug) {
            // Same post under another spelling (e.g. upper case): one URL only.
            return Response::redirect(BlogSeo::url($post), 301);
        }
        $db->query('UPDATE blog_posts SET views = views + 1 WHERE id = ?', [$post['id']]);
        $tags = $db->fetchAll('SELECT t.name, t.slug FROM blog_tags t JOIN blog_post_tags pt ON pt.tag_id = t.id WHERE pt.post_id = ?', [$post['id']]);
        $related = $db->fetchAll("SELECT title, slug, featured_image, published_at FROM blog_posts WHERE status = 'published' AND published_at <= ? AND id <> ? ORDER BY (category_id <=> ?) DESC, published_at DESC LIMIT 3", [now(), $post['id'], $post['category_id']]);
        $post['content'] = HtmlSanitizer::clean($post['content']);
        $robots = BlogSeo::robots($post);
        $meta = SeoService::meta([
            'title_exact' => BlogSeo::title($post),
            'description' => BlogSeo::description($post),
            'canonical' => BlogSeo::canonical($post),
            'type' => 'article',
            'image' => BlogSeo::image($post),
            'image_alt' => $post['title'],
            'robots' => $robots,
            'og_extra' => [
                'article:published_time' => gmdate('c', (int) strtotime($post['published_at'] . ' UTC')),
                'article:modified_time' => gmdate('c', (int) strtotime($post['updated_at'] . ' UTC')),
                'article:section' => (string) ($post['category'] ?? ''),
                'article:tag' => array_column($tags, 'name'),
            ],
            'jsonld' => [
                BlogSeo::jsonLd($post, $tags),
                SeoService::breadcrumbJsonLd(array_values(array_filter([['Home', '/'], ['Blog', '/blog'], $post['category_slug'] ? [$post['category'], '/blog/category/' . $post['category_slug']] : null, [$post['title'], '/blog/' . $post['slug']]]))),
            ],
        ]);
        $response = $this->view('public/blog/show', compact('post', 'tags', 'related', 'meta'));
        return $robots !== 'index,follow' ? $response->withHeader('X-Robots-Tag', str_replace(',', ', ', $robots)) : $response;
    }
}
