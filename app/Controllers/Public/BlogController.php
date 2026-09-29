<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HtmlSanitizer;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
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
        return $this->listing($request, '', [], 'Blog', 'Guides, tips and updates on social media growth.');
    }

    public function category(Request $request, string $slug): Response
    {
        $this->guard();
        $cat = Database::instance()->fetch('SELECT * FROM blog_categories WHERE slug = ?', [$slug]);
        if (!$cat) {
            $this->notFound();
        }
        return $this->listing($request, ' AND p.category_id = ?', [$cat['id']], $cat['name'], 'Articles in ' . $cat['name']);
    }

    public function tag(Request $request, string $slug): Response
    {
        $this->guard();
        $tag = Database::instance()->fetch('SELECT * FROM blog_tags WHERE slug = ?', [$slug]);
        if (!$tag) {
            $this->notFound();
        }
        return $this->listing($request, ' AND p.id IN (SELECT post_id FROM blog_post_tags WHERE tag_id = ?)', [$tag['id']], '#' . $tag['name'], 'Articles tagged ' . $tag['name']);
    }

    private function listing(Request $request, string $extraWhere, array $params, string $heading, string $description): Response
    {
        $posts = Paginator::query(
            'p.id, p.title, p.slug, p.excerpt, p.featured_image, p.published_at, c.name AS category, c.slug AS category_slug',
            "FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id WHERE p.status = 'published' AND p.published_at <= ?" . $extraWhere,
            array_merge([now()], $params),
            'p.published_at DESC',
            $this->pageNum($request),
            12
        );
        $categories = Database::instance()->fetchAll('SELECT c.name, c.slug, COUNT(p.id) AS n FROM blog_categories c JOIN blog_posts p ON p.category_id = c.id AND p.status = \'published\' GROUP BY c.id ORDER BY c.name');
        $meta = SeoService::meta(['title' => $heading, 'description' => $description, 'robots' => $posts->page > 1 ? 'noindex,follow' : 'index,follow']);
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
            $this->notFound();
        }
        $db->query('UPDATE blog_posts SET views = views + 1 WHERE id = ?', [$post['id']]);
        $tags = $db->fetchAll('SELECT t.name, t.slug FROM blog_tags t JOIN blog_post_tags pt ON pt.tag_id = t.id WHERE pt.post_id = ?', [$post['id']]);
        $related = $db->fetchAll("SELECT title, slug, featured_image, published_at FROM blog_posts WHERE status = 'published' AND published_at <= ? AND id <> ? ORDER BY (category_id <=> ?) DESC, published_at DESC LIMIT 3", [now(), $post['id'], $post['category_id']]);
        $post['content'] = HtmlSanitizer::clean($post['content']);
        $image = $post['featured_image'] ? upload_url($post['featured_image']) : '';
        $meta = SeoService::meta([
            'title' => $post['seo_title'] ?: $post['title'],
            'description' => $post['seo_description'] ?: ($post['excerpt'] ?: mb_substr(strip_tags($post['content']), 0, 160)),
            'type' => 'article',
            'image' => $image ?: null,
            'jsonld' => [
                array_filter([
                    '@context' => 'https://schema.org',
                    '@type' => 'BlogPosting',
                    'headline' => $post['title'],
                    'datePublished' => gmdate('c', strtotime($post['published_at'] . ' UTC')),
                    'dateModified' => gmdate('c', strtotime($post['updated_at'] . ' UTC')),
                    'image' => $image ?: null,
                    'author' => ['@type' => 'Organization', 'name' => site_name()],
                    'publisher' => ['@type' => 'Organization', 'name' => site_name()],
                    'mainEntityOfPage' => url('/blog/' . $post['slug']),
                ]),
                SeoService::breadcrumbJsonLd([['Home', '/'], ['Blog', '/blog'], [$post['title'], '/blog/' . $post['slug']]]),
            ],
        ]);
        return $this->view('public/blog/show', compact('post', 'tags', 'related', 'meta'));
    }
}
