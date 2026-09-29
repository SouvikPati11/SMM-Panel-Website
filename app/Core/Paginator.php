<?php

declare(strict_types=1);

namespace App\Core;

final class Paginator
{
    public readonly int $pages;
    public readonly int $offset;

    public function __construct(public readonly int $total, public readonly int $page, public readonly int $perPage, public readonly array $items = [])
    {
        $this->pages = max(1, (int) ceil($total / max(1, $perPage)));
        $this->offset = ($this->page - 1) * $perPage;
    }

    /**
     * Run a count + page query. $sql must be "SELECT ... FROM ... WHERE ..." without ORDER/LIMIT;
     * pass $orderBy separately (static SQL only).
     */
    public static function query(string $select, string $from, array $params, string $orderBy, int $page, int $perPage = 25): self
    {
        $db = Database::instance();
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $total = (int) $db->fetchColumn("SELECT COUNT(*) {$from}", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $items = $db->fetchAll("SELECT {$select} {$from} ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}", $params);
        return new self($total, $page, $perPage, $items);
    }

    /** Build page links preserving current query params. */
    public function links(Request $request): string
    {
        if ($this->pages <= 1) {
            return '';
        }
        $params = $request->all();
        unset($params['_token']);
        $base = url($request->path());
        $link = static function (int $p) use ($params, $base): string {
            $params['page'] = $p;
            return e($base . '?' . http_build_query($params));
        };
        $html = '<nav class="pagination" aria-label="Pagination">';
        $html .= $this->page > 1 ? '<a href="' . $link($this->page - 1) . '" rel="prev" aria-label="Previous">&lsaquo;</a>' : '<span class="disabled">&lsaquo;</span>';
        $window = 2;
        $last = 0;
        for ($p = 1; $p <= $this->pages; $p++) {
            if ($p === 1 || $p === $this->pages || abs($p - $this->page) <= $window) {
                if ($last && $p - $last > 1) {
                    $html .= '<span class="gap">…</span>';
                }
                $html .= $p === $this->page ? '<span class="current" aria-current="page">' . $p . '</span>' : '<a href="' . $link($p) . '">' . $p . '</a>';
                $last = $p;
            }
        }
        $html .= $this->page < $this->pages ? '<a href="' . $link($this->page + 1) . '" rel="next" aria-label="Next">&rsaquo;</a>' : '<span class="disabled">&rsaquo;</span>';
        return $html . '</nav>';
    }

    public function summary(): string
    {
        if ($this->total === 0) {
            return 'No results';
        }
        $from = $this->offset + 1;
        $to = min($this->total, $this->offset + $this->perPage);
        return "Showing {$from}–{$to} of " . number_format($this->total);
    }
}
