<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Allow-list HTML sanitizer for admin-authored rich content (pages, blog posts,
 * announcements). Strips scripts, event handlers, style attributes and unsafe URLs.
 */
final class HtmlSanitizer
{
    private const TAGS = [
        'p' => [], 'br' => [], 'hr' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'], 'h5' => [], 'h6' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'code' => [], 'pre' => [],
        'a' => ['href', 'title', 'target', 'rel'], 'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
        'span' => [], 'div' => [], 'figure' => [], 'figcaption' => [], 'small' => [], 'sup' => [], 'sub' => [],
    ];

    public static function clean(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('__root');
        if (!$root) {
            return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
        }
        self::walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private static function walk(\DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $c) {
            $children[] = $c;
        }
        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'base', 'svg', 'math'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                if (!array_key_exists($tag, self::TAGS)) {
                    // unwrap unknown element but keep its (sanitized) children
                    self::walk($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                $allowed = self::TAGS[$tag];
                $attrs = [];
                foreach ($child->attributes as $attr) {
                    $attrs[] = $attr->name;
                }
                foreach ($attrs as $name) {
                    $lname = strtolower($name);
                    if (!in_array($lname, $allowed, true)) {
                        $child->removeAttribute($name);
                        continue;
                    }
                    if (in_array($lname, ['href', 'src'], true)) {
                        $val = trim(html_entity_decode($child->getAttribute($name)));
                        $val = preg_replace('/[\x00-\x20]+/', '', $val);
                        if (!preg_match('#^(https?:|mailto:|/|\#)#i', $val) || str_starts_with($val, '//')) {
                            $child->removeAttribute($name);
                        }
                    }
                }
                if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
                if ($tag === 'img' && !$child->hasAttribute('loading')) {
                    $child->setAttribute('loading', 'lazy');
                }
                self::walk($child);
            } elseif ($child instanceof \DOMComment) {
                $node->removeChild($child);
            }
        }
    }
}
