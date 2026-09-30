<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\HtmlSanitizer;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AuditService;
use App\Services\Auth;
use App\Services\UploadService;

/** Pages, FAQ and blog. All HTML is passed through the allow-list sanitizer on save AND on render. */
final class ContentController extends Controller
{
    // ------------------------------------------------------------ pages
    public function pages(Request $request): Response
    {
        return $this->view('admin/content/pages', ['title' => 'Pages', 'pages' => Database::instance()->fetchAll('SELECT id, slug, title, status, is_system, show_in_footer, updated_at FROM pages ORDER BY is_system DESC, title')]);
    }

    public function pageForm(Request $request, int $id = 0): Response
    {
        $page = $id ? Database::instance()->fetch('SELECT * FROM pages WHERE id = ?', [$id]) : null;
        if ($id && !$page) {
            $this->notFound();
        }
        return $this->view('admin/content/page-form', ['title' => $page ? 'Edit page' : 'New page', 'page' => $page]);
    }

    public function savePage(Request $request): Response
    {
        $db = Database::instance();
        $id = $request->int('id');
        $existing = $id ? $db->fetch('SELECT * FROM pages WHERE id = ?', [$id]) : null;
        $data = Validator::check($request->post(), ['title' => 'required|max:200', 'seo_title' => 'max:200', 'seo_description' => 'max:320']);
        $slug = $existing && (int) $existing['is_system'] === 1 ? $existing['slug'] : slugify($request->str('slug') ?: $data['title']);
        if ($db->fetchColumn('SELECT id FROM pages WHERE slug = ? AND id <> ?', [$slug, $id])) {
            throw new ValidationException('Another page already uses this slug.');
        }
        $row = [
            'slug' => $slug,
            'title' => $data['title'],
            'content' => HtmlSanitizer::clean((string) ($request->post()['content'] ?? '')),
            'seo_title' => $data['seo_title'] ?: null,
            'seo_description' => $data['seo_description'] ?: null,
            'seo_keyword' => $data['seo_keyword'] ?: null,
            'canonical_url' => $canonical !== '' ? $canonical : null,
            // Absent from the request (older forms/tools) = keep the current value (new posts: indexable).
            'robots_index' => array_key_exists('robots_index', $request->post()) ? ($request->bool('robots_index') ? 1 : 0) : (int) ($existing['robots_index'] ?? 1),
            'robots_follow' => array_key_exists('robots_follow', $request->post()) ? ($request->bool('robots_follow') ? 1 : 0) : (int) ($existing['robots_follow'] ?? 1),
            'status' => $request->str('status') === 'draft' ? 'draft' : 'published',
            'show_in_footer' => $request->bool('show_in_footer') ? 1 : 0,
            'updated_at' => now(),
        ];
        if ($existing) {
            $db->update('pages', $row, ['id' => $id]);
        } else {
            $id = $db->insert('pages', $row + ['created_at' => now()]);
        }
        AuditService::log('page.save', 'page', $id, ['slug' => $slug]);
        $this->success('Page saved.');
        return Response::redirect(admin_url('pages/' . $id . '/edit'));
    }

    public function deletePage(Request $request, int $id): Response
    {
        $db = Database::instance();
        if ((int) $db->fetchColumn('SELECT is_system FROM pages WHERE id = ?', [$id]) === 1) {
            throw new ValidationException('System pages (About, Terms, Privacy, Refund) can be edited but not deleted.');
        }
        $db->delete('pages', ['id' => $id]);
        AuditService::log('page.delete', 'page', $id);
        $this->success('Page deleted.');
        return Response::redirect(admin_url('pages'));
    }

    // ------------------------------------------------------------ FAQ
    public function faqs(Request $request): Response
    {
        return $this->view('admin/content/faqs', ['title' => 'FAQ', 'faqs' => Database::instance()->fetchAll('SELECT * FROM faqs ORDER BY sort_order, id')]);
    }

    public function saveFaq(Request $request): Response
    {
        $data = Validator::check($request->post(), ['question' => 'required|max:300', 'answer' => 'required|max:5000', 'sort_order' => 'integer']);
        $db = Database::instance();
        $row = ['question' => $data['question'], 'answer' => $data['answer'], 'sort_order' => (int) ($data['sort_order'] ?: 0), 'status' => $request->str('status') === 'hidden' ? 'hidden' : 'active', 'updated_at' => now()];
        $id = $request->int('id');
        $id ? $db->update('faqs', $row, ['id' => $id]) : $db->insert('faqs', $row + ['created_at' => now()]);
        $this->success('FAQ saved.');
        return Response::redirect(admin_url('faq'));
    }

    public function deleteFaq(Request $request, int $id): Response
    {
        Database::instance()->delete('faqs', ['id' => $id]);
        $this->success('FAQ deleted.');
        return Response::redirect(admin_url('faq'));
    }

    // ------------------------------------------------------------ blog
    public function posts(Request $request): Response
    {
        $db = Database::instance();
        $where = 'WHERE 1=1';
        $params = [];
        $status = $request->str('status');
        if ($status === 'published') {
            $where .= " AND p.status = 'published' AND p.published_at <= ?";
            $params[] = now();
        } elseif ($status === 'scheduled') {
            $where .= " AND p.status = 'published' AND p.published_at > ?";
            $params[] = now();
        } elseif ($status === 'draft') {
            $where .= " AND p.status = 'draft'";
        }
        $q = mb_substr($request->str('q'), 0, 100);
        if ($q !== '') {
            $where .= ' AND (p.title LIKE ? OR p.slug LIKE ?)';
            array_push($params, Database::like($q), Database::like($q));
        }
        $posts = Paginator::query('p.id, p.title, p.slug, p.status, p.published_at, p.updated_at, p.views, p.featured_image, c.name AS category', "FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id {$where}", $params, 'p.id DESC', $this->pageNum($request), 30);
        $cats = $db->fetchAll('SELECT c.*, (SELECT COUNT(*) FROM blog_posts p WHERE p.category_id = c.id) n FROM blog_categories c ORDER BY name');
        $counts = $db->fetch("SELECT COUNT(*) AS all_n, SUM(status = 'published' AND published_at <= ?) AS published, SUM(status = 'published' AND published_at > ?) AS scheduled, SUM(status = 'draft') AS draft FROM blog_posts", [now(), now()]);
        return $this->view('admin/content/posts', ['title' => 'Blog', 'posts' => $posts, 'cats' => $cats, 'counts' => $counts, 'f' => ['status' => $status, 'q' => $q]]);
    }

    public function postForm(Request $request, int $id = 0): Response
    {
        $db = Database::instance();
        $post = $id ? $db->fetch('SELECT * FROM blog_posts WHERE id = ?', [$id]) : null;
        if ($id && !$post) {
            $this->notFound();
        }
        $tags = $post ? implode(', ', array_column($db->fetchAll('SELECT t.name FROM blog_tags t JOIN blog_post_tags pt ON pt.tag_id = t.id WHERE pt.post_id = ?', [$id]), 'name')) : '';
        return $this->view('admin/content/post-form', ['title' => $post ? 'Edit post' : 'New post', 'post' => $post, 'tags' => $tags, 'categories' => $db->fetchPairs('SELECT id, name FROM blog_categories ORDER BY name')]);
    }

    public function savePost(Request $request): Response
    {
        $db = Database::instance();
        $id = $request->int('id');
        $existing = $id ? $db->fetch('SELECT * FROM blog_posts WHERE id = ?', [$id]) : null;
        $data = Validator::check($request->post(), ['title' => 'required|max:220', 'excerpt' => 'max:500', 'seo_title' => 'max:200', 'seo_description' => 'max:320', 'seo_keyword' => 'max:100', 'canonical_url' => 'max:500']);
        if ($id && !$existing) {
            $this->notFound();
        }
        // Clean slug from the given slug or the title; titles without latin letters/digits get a stable fallback.
        $slug = trim(mb_substr(slugify($request->str('slug') ?: $data['title']), 0, 200), '-');
        if ($slug === '') {
            $slug = $existing['slug'] ?? ('post-' . gmdate('Ymd-His'));
        }
        $canonical = trim((string) ($data['canonical_url'] ?? ''));
        if ($canonical !== '' && !\App\Services\BlogSeo::validCanonical($canonical)) {
            throw new ValidationException('The canonical URL must be a full address starting with https:// (or http://). Leave it empty to use this post\'s own URL.');
        }
        if ($db->fetchColumn('SELECT id FROM blog_posts WHERE slug = ? AND id <> ?', [$slug, $id])) {
            throw new ValidationException('Another post already uses the address /blog/' . $slug . '. Change the slug.');
        }
        $content = HtmlSanitizer::clean((string) ($request->post()['content'] ?? ''));
        if (trim(strip_tags($content)) === '') {
            throw new ValidationException('Post content is required.');
        }
        $status = $request->str('status') === 'published' ? 'published' : 'draft';
        $publishedAt = $existing['published_at'] ?? null;
        if ($request->str('published_at') !== '') {
            $raw = $request->str('published_at');
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $raw, display_tz()) ?: \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw, display_tz());
            if (!$dt || (int) $dt->format('Y') < 2000 || (int) $dt->format('Y') > 2100) {
                throw new ValidationException('Enter a valid publish date and time.');
            }
            $publishedAt = $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } elseif ($status === 'published' && !$publishedAt) {
            $publishedAt = now();
        }
        $catId = $request->int('category_id') ?: null;
        $row = [
            'category_id' => $catId && $db->fetchColumn('SELECT id FROM blog_categories WHERE id = ?', [$catId]) ? $catId : null,
            'title' => $data['title'],
            'slug' => $slug,
            'excerpt' => $data['excerpt'] ?: mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($content))), 0, 240),
            'content' => $content,
            'seo_title' => $data['seo_title'] ?: null,
            'seo_description' => $data['seo_description'] ?: null,
            'seo_keyword' => $data['seo_keyword'] ?: null,
            'canonical_url' => $canonical !== '' ? $canonical : null,
            // Absent from the request (older forms/tools) = keep the current value (new posts: indexable).
            'robots_index' => array_key_exists('robots_index', $request->post()) ? ($request->bool('robots_index') ? 1 : 0) : (int) ($existing['robots_index'] ?? 1),
            'robots_follow' => array_key_exists('robots_follow', $request->post()) ? ($request->bool('robots_follow') ? 1 : 0) : (int) ($existing['robots_follow'] ?? 1),
            'status' => $status,
            'published_at' => $publishedAt,
            'updated_at' => now(),
        ];
        // The old image is deleted only after the post row is saved (see below).
        $oldImage = null;
        if ($file = $request->file('featured_image')) {
            $row['featured_image'] = UploadService::storePublicImage($file, 'blog');
            $oldImage = $existing['featured_image'] ?? null;
        } elseif ($request->bool('remove_image')) {
            $row['featured_image'] = null;
            $oldImage = $existing['featured_image'] ?? null;
        }
        $oldOg = null;
        if ($file = $request->file('og_image')) {
            $row['og_image'] = UploadService::storePublicImage($file, 'blog');
            $oldOg = $existing['og_image'] ?? null;
        } elseif ($request->bool('remove_og_image')) {
            $row['og_image'] = null;
            $oldOg = $existing['og_image'] ?? null;
        }
        $db->transaction(function (Database $db) use (&$id, $existing, $row, $request): void {
            if ($existing) {
                $db->update('blog_posts', $row, ['id' => $id]);
                // Old address of a renamed post redirects (301) to the new one.
                if ($existing['slug'] !== $row['slug']) {
                    $db->query('INSERT INTO blog_slug_redirects (old_slug, post_id, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE post_id = VALUES(post_id)', [$existing['slug'], $id, now()]);
                }
                $db->query('DELETE FROM blog_slug_redirects WHERE old_slug = ?', [$row['slug']]); // a live slug never redirects
            } else {
                $id = $db->insert('blog_posts', $row + ['admin_id' => Auth::adminId(), 'created_at' => now()]);
            }
            $db->query('DELETE FROM blog_post_tags WHERE post_id = ?', [$id]);
            foreach (array_slice(array_unique(array_filter(array_map('trim', explode(',', $request->str('tags'))))), 0, 15) as $tag) {
                $tag = mb_substr($tag, 0, 80);
                $tslug = slugify($tag);
                $tid = (int) $db->fetchColumn('SELECT id FROM blog_tags WHERE slug = ?', [$tslug]) ?: $db->insert('blog_tags', ['name' => $tag, 'slug' => $tslug]);
                $db->query('INSERT IGNORE INTO blog_post_tags (post_id, tag_id) VALUES (?, ?)', [$id, $tid]);
            }
        });
        UploadService::deletePublic($oldImage);
        UploadService::deletePublic($oldOg);
        AuditService::log('blog.save', 'blog_post', $id, ['status' => $status]);
        $live = $status === 'published' && $publishedAt !== null && $publishedAt <= now();
        $this->success($status === 'draft' ? 'Draft saved.' : ($live ? 'Post published.' : 'Post scheduled for ' . fmt_date($publishedAt) . '.'));
        return Response::redirect(admin_url('blog/' . $id . '/edit'));
    }

    /** Publish / unpublish from the list without opening the editor. */
    public function togglePost(Request $request, int $id): Response
    {
        $db = Database::instance();
        $post = $db->fetch('SELECT id, status, published_at FROM blog_posts WHERE id = ?', [$id]);
        if (!$post) {
            $this->notFound();
        }
        $publish = $post['status'] !== 'published';
        $db->update('blog_posts', ['status' => $publish ? 'published' : 'draft', 'published_at' => $publish ? ($post['published_at'] ?: now()) : $post['published_at'], 'updated_at' => now()], ['id' => $id]);
        AuditService::log($publish ? 'blog.publish' : 'blog.unpublish', 'blog_post', $id);
        $this->success($publish ? 'Post published.' : 'Post moved back to drafts.');
        return $this->back($request, admin_url('blog'));
    }

    public function deletePost(Request $request, int $id): Response
    {
        $db = Database::instance();
        $imgs = $db->fetch('SELECT featured_image, og_image FROM blog_posts WHERE id = ?', [$id]) ?: [];
        UploadService::deletePublic($imgs['featured_image'] ?? null);
        UploadService::deletePublic($imgs['og_image'] ?? null);
        $db->delete('blog_posts', ['id' => $id]);
        AuditService::log('blog.delete', 'blog_post', $id);
        $this->success('Post deleted.');
        return Response::redirect(admin_url('blog'));
    }

    public function saveBlogCategory(Request $request): Response
    {
        $data = Validator::check($request->post(), ['name' => 'required|max:120']);
        $db = Database::instance();
        $id = $request->int('id');
        $slug = slugify($request->str('slug') ?: $data['name']);
        if ($db->fetchColumn('SELECT id FROM blog_categories WHERE slug = ? AND id <> ?', [$slug, $id])) {
            throw new ValidationException('Slug already used.');
        }
        $id ? $db->update('blog_categories', ['name' => $data['name'], 'slug' => $slug], ['id' => $id]) : $db->insert('blog_categories', ['name' => $data['name'], 'slug' => $slug, 'created_at' => now()]);
        $this->success('Category saved.');
        return Response::redirect(admin_url('blog'));
    }

    public function deleteBlogCategory(Request $request, int $id): Response
    {
        Database::instance()->delete('blog_categories', ['id' => $id]);
        $this->success('Category deleted (its posts are now uncategorised).');
        return Response::redirect(admin_url('blog'));
    }
}
