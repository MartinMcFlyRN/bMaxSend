<?php

declare(strict_types=1);

namespace App\Core;

final class Paginator
{
    public int $page;
    public int $pages;
    public int $offset;

    public function __construct(public int $total, public int $perPage = 50)
    {
        $this->page = max(1, (int) ($_GET['page'] ?? 1));
        $this->pages = max(1, (int) ceil($total / max(1, $perPage)));
        $this->page = min($this->page, $this->pages);
        $this->offset = ($this->page - 1) * $perPage;
    }

    public function links(): string
    {
        if ($this->pages <= 1) {
            return '';
        }
        $q = $_GET;
        $html = '<nav class="pagination">';
        $start = max(1, $this->page - 3);
        $end = min($this->pages, $this->page + 3);
        if ($start > 1) {
            $q['page'] = 1;
            $html .= '<a href="?' . e(http_build_query($q)) . '">1</a><span>…</span>';
        }
        for ($i = $start; $i <= $end; $i++) {
            $q['page'] = $i;
            $cls = $i === $this->page ? ' class="current"' : '';
            $html .= '<a' . $cls . ' href="?' . e(http_build_query($q)) . '">' . $i . '</a>';
        }
        if ($end < $this->pages) {
            $q['page'] = $this->pages;
            $html .= '<span>…</span><a href="?' . e(http_build_query($q)) . '">' . $this->pages . '</a>';
        }
        return $html . '</nav>';
    }
}
