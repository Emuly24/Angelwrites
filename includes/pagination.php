<?php
// ============================================================
//  PAGINATION.PHP – one pager for every list page (P1)
// ============================================================
// aw_page($per_page)             -> [$page, $per_page, $offset] from ?page= (always >= 1)
// aw_clamp($total, $page, $per)  -> [$page, $offset] kept within the last page
// aw_pager($total, $page, $per)  -> HTML: « Previous 1 … 4 5 6 … 20 Next »  (empty if one page)
// The links keep the page's other query parameters (search, filters, tabs) and use the same
// .pagination / .page-link classes as the existing pagers, styled in assets/css/style.css.

function aw_page($per_page = 12) {
    $page = (int)($_GET['page'] ?? 1);
    if ($page < 1) {
        $page = 1;
    }
    return [$page, (int)$per_page, ($page - 1) * (int)$per_page];
}

// A page past the end (an old link, a deleted item) shows the last page instead of an empty list.
function aw_clamp($total, $page, $per_page) {
    $last = max(1, (int)ceil(max(0, (int)$total) / max(1, (int)$per_page)));
    $page = min(max(1, (int)$page), $last);
    return [$page, ($page - 1) * (int)$per_page];
}

function aw_pager($total, $page, $per_page, $anchor = '') {
    $pages = (int)ceil(max(0, (int)$total) / max(1, (int)$per_page));
    if ($pages <= 1) {
        return '';
    }
    $page = min(max(1, (int)$page), $pages);
    $query = $_GET;
    $url = function ($p) use ($query, $anchor) {
        $query['page'] = $p;
        return '?' . htmlspecialchars(http_build_query($query)) . ($anchor !== '' ? '#' . rawurlencode($anchor) : '');
    };
    // Page numbers to show: first, last, and two either side of the current page.
    $nums = array_unique(array_filter(
        [1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages],
        function ($n) use ($pages) { return $n >= 1 && $n <= $pages; }
    ));
    sort($nums);

    $html = '<nav class="pagination aw-pager" aria-label="Pages">';
    if ($page > 1) {
        $html .= '<a class="page-link" rel="prev" href="' . $url($page - 1) . '">‹ Previous</a>';
    }
    $prev = 0;
    foreach ($nums as $n) {
        if ($prev && $n > $prev + 1) {
            $html .= '<span class="page-gap" aria-hidden="true">…</span>';
        }
        $html .= $n === $page
            ? '<a class="page-link active" aria-current="page" href="' . $url($n) . '">' . $n . '</a>'
            : '<a class="page-link" href="' . $url($n) . '" aria-label="Page ' . $n . '">' . $n . '</a>';
        $prev = $n;
    }
    if ($page < $pages) {
        $html .= '<a class="page-link" rel="next" href="' . $url($page + 1) . '">Next ›</a>';
    }
    $html .= '</nav>';
    $html .= '<p class="aw-pager-info">Page ' . $page . ' of ' . $pages . ' · ' . number_format((int)$total) . ' in total</p>';
    return $html;
}
