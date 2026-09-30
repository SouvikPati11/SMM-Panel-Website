<?php

declare(strict_types=1);

namespace App\Services;

/**
 * SEO for blog posts. Every value has an automatic default and an optional
 * manual override from the post editor (Admin → Blog → SEO):
 *
 *   title        custom SEO title, used as-is  | post title + " — Site" (site name dropped if too long)
 *   description  custom meta description      | excerpt, else the first sentences of the content (~155 chars, word boundary)
 *   canonical    custom absolute http(s) URL   | https://site/blog/{slug} (built from the stored slug, never the request)
 *   image        custom OG image              | featured image | site default share image
 *   robots       index/noindex + follow/nofollow per post
 *
 * Only published posts whose publish date has passed are ever public; drafts
 * and scheduled posts 404 and are left out of the sitemap, as are posts set to
 * noindex or whose canonical points to another URL.
 */
final class BlogSeo
{
    public const TITLE_MAX = 60;
    public const DESCRIPTION_MAX = 155;

    public static function title(array $post): string
    {
        $custom = trim((string) ($post['seo_title'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        $title = trim((string) $post['title']);
        $full = $title . ' — ' . site_name();
        return mb_strlen($full) <= self::TITLE_MAX ? $full : $title;
    }

    public static function description(array $post): string
    {
        foreach ([$post['seo_description'] ?? '', $post['excerpt'] ?? '', $post['content'] ?? ''] as $source) {
            $text = self::plain((string) $source);
            if ($text !== '') {
                return self::truncate($text, self::DESCRIPTION_MAX);
            }
        }
        return '';
    }

    public static function canonical(array $post): string
    {
        $custom = trim((string) ($post['canonical_url'] ?? ''));
        return $custom !== '' && self::validCanonical($custom) ? $custom : self::url($post);
    }

    public static function url(array $post): string
    {
        return url('/blog/' . rawurlencode((string) $post['slug']));
    }

    public static function robots(array $post): string
    {
        return ((int) ($post['robots_index'] ?? 1) === 1 ? 'index' : 'noindex') . ',' . ((int) ($post['robots_follow'] ?? 1) === 1 ? 'follow' : 'nofollow');
    }

    /** Listed in the sitemap: indexable and canonical to itself. */
    public static function inSitemap(array $post): bool
    {
        return (int) ($post['robots_index'] ?? 1) === 1 && self::canonical($post) === self::url($post);
    }

    public static function image(array $post): ?string
    {
        foreach (['og_image', 'featured_image'] as $k) {
            if (!empty($post[$k])) {
                return upload_url((string) $post[$k]);
            }
        }
        return null;
    }

    /** An absolute http(s) URL without spaces or credentials. */
    public static function validCanonical(string $url): bool
    {
        if (strlen($url) > 500 || !preg_match('#^https?://[^\s/$.?\#@][^\s@]*$#i', $url)) {
            return false;
        }
        $p = parse_url($url);
        return is_array($p) && !empty($p['host']) && !isset($p['user']) && !isset($p['pass']);
    }

    /** BlogPosting structured data (schema.org). */
    public static function jsonLd(array $post, array $tags = []): array
    {
        $publisher = ['@type' => 'Organization', 'name' => site_name(), 'url' => url('/')];
        if (setting('site_logo')) {
            $publisher['logo'] = ['@type' => 'ImageObject', 'url' => upload_url((string) setting('site_logo'))];
        }
        $text = self::plain((string) ($post['content'] ?? ''));
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => mb_substr((string) $post['title'], 0, 110),
            'description' => self::description($post),
            'url' => self::url($post),
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => self::canonical($post)],
            'datePublished' => gmdate('c', (int) strtotime($post['published_at'] . ' UTC')),
            'dateModified' => gmdate('c', (int) strtotime(($post['updated_at'] ?: $post['published_at']) . ' UTC')),
            'image' => self::image($post) ?? SeoService::defaultImage(),
            'author' => !empty($post['author']) ? ['@type' => 'Person', 'name' => (string) $post['author']] : $publisher,
            'publisher' => $publisher,
            'articleSection' => $post['category'] ?? null,
            'keywords' => implode(', ', array_filter(array_merge([trim((string) ($post['seo_keyword'] ?? ''))], array_column($tags, 'name')))) ?: null,
            'wordCount' => $text !== '' ? count(preg_split('/\s+/u', $text) ?: []) : null,
            'inLanguage' => 'en',
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Focus-keyword checks for the editor (guidance only; search engines rank
     * on many other factors and nothing here guarantees a position).
     * @return list<array{0:bool,1:string}>
     */
    public static function keywordChecks(array $post): array
    {
        $kw = mb_strtolower(trim((string) ($post['seo_keyword'] ?? '')));
        $out = [];
        $title = self::title($post);
        $desc = self::description($post);
        $out[] = [mb_strlen($title) <= 65, 'SEO title length ' . mb_strlen($title) . ' characters (about 50–60 show in results)'];
        $out[] = [mb_strlen($desc) >= 70 && mb_strlen($desc) <= 160, 'Meta description length ' . mb_strlen($desc) . ' characters (aim for 120–160)'];
        if ($kw === '') {
            $out[] = [false, 'No focus keyword set'];
            return $out;
        }
        $text = mb_strtolower(self::plain((string) ($post['content'] ?? '')));
        $first = implode(' ', array_slice(preg_split('/\s+/u', $text) ?: [], 0, 100));
        $out[] = [str_contains(mb_strtolower($title), $kw), 'Focus keyword in the SEO title'];
        $out[] = [str_contains(mb_strtolower($desc), $kw), 'Focus keyword in the meta description'];
        $out[] = [str_contains((string) ($post['slug'] ?? ''), slugify($kw)), 'Focus keyword in the slug'];
        $out[] = [str_contains($first, $kw), 'Focus keyword in the first paragraph'];
        $out[] = [preg_match('#<h[23][^>]*>[^<]*' . preg_quote($kw, '#') . '#iu', (string) ($post['content'] ?? '')) === 1, 'Focus keyword in a subheading (H2/H3)'];
        return $out;
    }

    public static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', str_replace(['<br', '</p>', '</h', '</li>'], [' <br', ' </p>', ' </h', ' </li>'], $html)) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    public static function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut, " ,.;:-–—") . '…';
    }
}
