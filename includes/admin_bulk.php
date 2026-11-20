<?php
/**
 * Bulk actions on staff list pages ("select rows, choose an action, Apply").
 *
 *   $ids = aw_bulk_ids();                       the ticked rows, as unique positive integers
 *   [$done, $failed] = aw_bulk_delete($db, 'blog_posts', $ids);   safe deletes (includes/safe_delete.php)
 *   aw_bulk_done('manage_blog', $message, $error);                remember the result, go back to the list
 *   aw_bulk_take('manage_blog', $success, $error);                show it on the list (call before the page)
 *
 * The result used to be lost: the pages set $success and then redirected, so nobody saw whether the
 * action worked. The bulk form must also not wrap the table: row forms inside it (role or status
 * pickers) are dropped by browsers and their fields were sent with the bulk action instead.
 * Nothing here throws.
 */
require_once __DIR__ . '/safe_delete.php';

function aw_bulk_ids($field = 'selected_ids') {
    $raw = $_POST[$field] ?? '';
    $list = is_array($raw) ? $raw : explode(',', (string)$raw);
    return array_values(array_unique(array_filter(array_map('intval', $list), function ($i) { return $i > 0; })));
}

/** Deletes each row with aw_safe_delete (tidying what points to it). Returns [done, failed]. */
function aw_bulk_delete(PDO $db, $table, array $ids) {
    $done = 0; $failed = 0;
    foreach ($ids as $id) {
        $r = aw_safe_delete($db, $table, (int)$id);
        if (!empty($r['ok'])) $done++;
        elseif (($r['error'] ?? '') !== 'It was already deleted.') { $failed++; error_log("bulk delete $table#$id: " . ($r['error'] ?? '')); }
    }
    return [$done, $failed];
}

/** "3 posts" / "1 post". */
function aw_bulk_n($n, $one, $many = null) {
    return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
}

/** Keep the result for the next page view and go back to the list (keeping its search and page). */
function aw_bulk_done($page, $success, $error = '') {
    $_SESSION['aw_bulk_flash'][$page] = [(string)$success, (string)$error];
    $back = (string)($_SERVER['REQUEST_URI'] ?? '');
    $q = (string)parse_url($back, PHP_URL_QUERY);
    header('Location: ' . SITE_URL . '/admin/' . $page . '.php' . ($q !== '' ? '?' . $q : ''));
    exit;
}

function aw_bulk_take($page, &$success, &$error) {
    if (empty($_SESSION['aw_bulk_flash'][$page])) return;
    [$s, $e] = $_SESSION['aw_bulk_flash'][$page];
    unset($_SESSION['aw_bulk_flash'][$page]);
    if ($s !== '') $success = $s;
    if ($e !== '') $error = $e;
}
