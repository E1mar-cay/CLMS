<?php

declare(strict_types=1);

/**
 * Sliding-window page numbers with ellipses (for large page counts).
 *
 * @return list<int|null> Integers are 1-based page numbers; null = ellipsis.
 */
function clms_pagination_items(int $current, int $totalPages, int $delta = 2): array
{
    if ($totalPages <= 1) {
        return [];
    }
    if ($totalPages <= $delta * 2 + 3) {
        return range(1, $totalPages);
    }

    $pages = [1];
    $start = max(2, $current - $delta);
    $end = min($totalPages - 1, $current + $delta);
    if ($start > 2) {
        $pages[] = null;
    }
    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }
    if ($end < $totalPages - 1) {
        $pages[] = null;
    }
    $pages[] = $totalPages;

    return $pages;
}

/**
 * Merge a page number into query params (omit key when page is 1).
 *
 * @param array<string, string|int|float|bool> $base
 * @return array<string, string|int|float|bool>
 */
function clms_pagination_merge_page_key(array $base, string $pageKey, int $targetPage): array
{
    $out = $base;
    if ($targetPage > 1) {
        $out[$pageKey] = $targetPage;
    } else {
        unset($out[$pageKey]);
    }

    return $out;
}

/**
 * Render responsive pagination (Bootstrap 5). Optional AJAX data attribute on links.
 *
 * @param array<string, string|int|float|bool> $queryBase Query string without the page key set for page 1.
 */
function clms_admin_pagination_render(
    string $clmsWebBase,
    string $pathSuffix,
    array $queryBase,
    int $page,
    int $totalPages,
    string $ariaLabel,
    string $pageKey = 'page',
    ?string $ajaxDataAttrName = null,
    string $navExtraClass = ''
): void {
    if ($totalPages <= 1) {
        return;
    }

    $buildUrl = static function (array $params) use ($clmsWebBase, $pathSuffix): string {
        return $clmsWebBase . $pathSuffix . ($params !== [] ? '?' . http_build_query($params) : '');
    };

    $items = clms_pagination_items($page, $totalPages);
    $dataAttr = $ajaxDataAttrName !== null && $ajaxDataAttrName !== ''
        ? static function (int $p) use ($ajaxDataAttrName): string {
            return ' ' . htmlspecialchars($ajaxDataAttrName, ENT_QUOTES, 'UTF-8') . '="' . (int) $p . '"';
        }
        : static function (int $_p = 0): string {
            return '';
        };

    $prevPage = max(1, $page - 1);
    $nextPage = min($totalPages, $page + 1);
    $prevParams = clms_pagination_merge_page_key($queryBase, $pageKey, $prevPage);
    $nextParams = clms_pagination_merge_page_key($queryBase, $pageKey, $nextPage);
    $prevUrl = $buildUrl($prevParams);
    $nextUrl = $buildUrl($nextParams);

    $navClass = trim('clms-pagination-nav ' . $navExtraClass);
    echo '<nav class="' . htmlspecialchars($navClass, ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') . '">';
    echo '<div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-2 mb-2">';
    echo '<small class="text-muted text-center text-sm-start">Page ' . (int) $page . ' of ' . (int) $totalPages . '</small>';
    echo '</div>';
    echo '<div class="overflow-x-auto pb-1" style="-webkit-overflow-scrolling: touch;">';
    echo '<ul class="pagination pagination-sm flex-nowrap mb-0 justify-content-center justify-content-sm-end">';

    echo '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '">';
    if ($page <= 1) {
        echo '<span class="page-link"><span class="d-none d-sm-inline">Previous</span><span class="d-sm-none">Prev</span></span>';
    } else {
        echo '<a class="page-link" href="' . htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8') . '"' . $dataAttr($prevPage) . '>';
        echo '<span class="d-none d-sm-inline">Previous</span><span class="d-sm-none">Prev</span>';
        echo '</a>';
    }
    echo '</li>';

    foreach ($items as $pn) {
        if ($pn === null) {
            echo '<li class="page-item disabled d-none d-sm-inline-block"><span class="page-link">&hellip;</span></li>';
            continue;
        }
        $pageParams = clms_pagination_merge_page_key($queryBase, $pageKey, $pn);
        $pageUrl = $buildUrl($pageParams);
        $isActive = $pn === $page;
        echo '<li class="page-item' . ($isActive ? ' active' : '') . '">';
        if ($isActive) {
            echo '<span class="page-link">' . (int) $pn . '</span>';
        } else {
            echo '<a class="page-link" href="' . htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') . '"' . $dataAttr((int) $pn) . '>' . (int) $pn . '</a>';
        }
        echo '</li>';
    }

    echo '<li class="page-item' . ($page >= $totalPages ? ' disabled' : '') . '">';
    if ($page >= $totalPages) {
        echo '<span class="page-link"><span class="d-none d-sm-inline">Next</span><span class="d-sm-none">Next</span></span>';
    } else {
        echo '<a class="page-link" href="' . htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') . '"' . $dataAttr($nextPage) . '>';
        echo '<span class="d-none d-sm-inline">Next</span><span class="d-sm-none">Next</span>';
        echo '</a>';
    }
    echo '</li>';

    echo '</ul></div></nav>';
}
