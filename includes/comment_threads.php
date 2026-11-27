<?php
/**
 * Replies on comments (blog posts and reflections, videos): one shared way to store and show threads.
 *  - aw_thread_column(): adds parent_id to a comment table once (older databases have none).
 *  - aw_thread_tree(): turns a flat list into threads to any depth. A reply whose comment is gone is
 *    shown at the top level instead of vanishing.
 *  - aw_thread_reply_form(): the small reply box under a comment (hidden until Reply is pressed).
 *  - aw_thread_assets(): the Reply button script and styles, once per page.
 * Nothing here throws; failures are logged.
 */
require_once __DIR__ . '/csrf.php'; // the reply box carries the page token

function aw_thread_column(PDO $db, $table) {
    static $done = [];
    if (isset($done[$table])) return $done[$table];
    if (!preg_match('/^[a-z_]+$/', $table)) return $done[$table] = false;
    try {
        $cols = array_column($db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!$cols) return $done[$table] = false;
        if (!in_array('parent_id', $cols, true)) $db->exec("ALTER TABLE $table ADD COLUMN parent_id INTEGER");
        return $done[$table] = true;
    } catch (Throwable $e) {
        error_log('aw_thread_column ' . $table . ': ' . $e->getMessage());
        return $done[$table] = false;
    }
}

/** Rows (each with id and parent_id) to roots in the given order, each with 'children' (oldest first). */
function aw_thread_tree(array $rows) {
    $by = [];
    foreach ($rows as $r) { $r['children'] = []; $by[(int)$r['id']] = $r; }
    $kids = []; $roots = [];
    foreach ($by as $id => $r) {
        $p = (int)($r['parent_id'] ?? 0);
        if ($p && $p !== $id && isset($by[$p])) $kids[$p][] = $id; else $roots[] = $id;
    }
    foreach ($kids as &$k) sort($k); unset($k); // replies read in the order they were written
    $build = function ($id, $depth = 0) use (&$build, &$by, &$kids) {
        $r = $by[$id];
        if ($depth < 30) foreach ($kids[$id] ?? [] as $k) $r['children'][] = $build($k, $depth + 1);
        return $r;
    };
    return array_map($build, $roots);
}

/** The reply box under a comment. $fields: hidden inputs for the page's own handler. */
function aw_thread_reply_form($comment_id, $author, array $fields, $submit_name = 'add_comment') {
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $id = 'awReply' . (int)$comment_id;
    $h = '<form method="POST" class="aw-thread-form" id="' . $id . '" hidden>';
    $h .= '<input type="hidden" name="csrf_token" value="' . $e(generate_csrf_token()) . '">';
    $h .= '<input type="hidden" name="parent_id" value="' . (int)$comment_id . '">';
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . $e($k) . '" value="' . $e($v) . '">';
    $h .= '<label class="sr-only" for="' . $id . 't">Reply to ' . $e($author) . '</label>';
    $h .= '<textarea id="' . $id . 't" name="comment" rows="2" required maxlength="2000" placeholder="Reply to ' . $e($author) . '…"></textarea>';
    $h .= '<div class="aw-thread-acts"><button type="submit" name="' . $e($submit_name) . '" value="1" class="btn btn-sm btn-primary">Reply</button>'
        . '<button type="button" class="btn btn-sm btn-outline" data-thread-cancel>Cancel</button></div></form>';
    return $h;
}

/** The Reply button for a comment (members), or a sign-in link (guests). */
function aw_thread_reply_button($comment_id, $author) {
    if (empty($_SESSION['user_id'])) return '';
    return '<button type="button" class="aw-thread-reply" aria-expanded="false" aria-controls="awReply' . (int)$comment_id . '">'
         . '<i class="fas fa-reply" aria-hidden="true"></i> Reply<span class="sr-only"> to ' . htmlspecialchars((string)$author) . '</span></button>';
}

function aw_thread_assets() {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<style>.aw-thread-kids{margin:10px 0 0 18px;padding-left:14px;border-left:2px solid #ead9d6}'
        . '.aw-thread-reply{background:none;border:0;padding:6px 2px;font:inherit;font-size:.85rem;font-weight:600;color:var(--rose,#9c5457);cursor:pointer}'
        . '.aw-thread-reply:hover{text-decoration:underline}.aw-thread-reply:focus-visible{outline:2px solid currentColor;outline-offset:2px;border-radius:4px}'
        . '.aw-thread-form{margin:8px 0 4px}.aw-thread-form textarea{width:100%;min-height:64px;padding:10px 12px;border:1px solid var(--border,#e2d2d2);border-radius:12px;font:inherit;resize:vertical;background:var(--card-bg,#fff);color:inherit}'
        . '.aw-thread-acts{display:flex;gap:8px;margin-top:6px}.aw-thread-to{font-size:.8rem;color:var(--text-light,#6b5a5a);margin-bottom:2px}'
        . '@media (max-width:520px){.aw-thread-kids{margin-left:6px;padding-left:10px}}'
        . 'html[data-theme="dark"] .aw-thread-kids{border-color:#4a3434}</style>'
        . '<script>document.addEventListener("click",function(e){var b=e.target.closest(".aw-thread-reply");'
        . 'if(b){var f=document.getElementById(b.getAttribute("aria-controls"));if(!f)return;var open=f.hidden;f.hidden=!open;b.setAttribute("aria-expanded",open?"true":"false");if(open)f.querySelector("textarea").focus();return;}'
        . 'var c=e.target.closest("[data-thread-cancel]");if(c){var fm=c.closest(".aw-thread-form");fm.hidden=true;var bt=document.querySelector("[aria-controls=\'"+fm.id+"\']");if(bt){bt.setAttribute("aria-expanded","false");bt.focus();}}});</script>';
}
