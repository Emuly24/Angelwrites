<?php
/**
 * Dashboard building blocks: real daily figures, trend lines, the activity chart and the activity feed.
 * Everything is read once on the server from the site's own records (no polling, no made-up numbers).
 * Every query fails soft (logged, zero/empty). Charts are inline SVG with a text table for screen readers.
 */

function aw_dsh_q(PDO $db, $sql, array $p = []) {
    try { $st = $db->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { error_log('dashboard: ' . $e->getMessage()); return []; }
}
function aw_dsh_n(PDO $db, $sql, array $p = []) {
    try { $st = $db->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); }
    catch (Throwable $e) { error_log('dashboard: ' . $e->getMessage()); return 0; }
}

/** The last $days days as ['Y-m-d' => 0, ...], oldest first (UTC, like the database). */
function aw_dsh_days($days = 14) {
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) $out[gmdate('Y-m-d', time() - $i * 86400)] = 0;
    return $out;
}

/**
 * Daily counts for the last $days days. $sources: list of [table, date column, extra WHERE] summed together.
 * Returns ['Y-m-d' => n].
 */
function aw_dsh_daily(PDO $db, array $sources, $days = 14) {
    $out = aw_dsh_days($days);
    foreach ($sources as $src) {
        [$table, $col] = $src;
        $where = $src[2] ?? '';
        $rows = aw_dsh_q($db, "SELECT date($col) AS d, COUNT(*) AS n FROM $table WHERE $col >= date('now', '-" . ((int)$days - 1) . " days')" . ($where !== '' ? " AND ($where)" : '') . " GROUP BY d");
        foreach ($rows as $r) if (isset($out[$r['d']])) $out[$r['d']] += (int)$r['n'];
    }
    return $out;
}

/** Sum of the last 7 values and the 7 before them: [this week, last week]. */
function aw_dsh_weeks(array $daily) {
    $v = array_values($daily);
    $n = count($v);
    return [array_sum(array_slice($v, max(0, $n - 7))), array_sum(array_slice($v, max(0, $n - 14), min(7, max(0, $n - 7))))];
}

/** "+25%" / "−10%" / "new" / "same", with a class for the colour. */
function aw_dsh_delta($now, $prev) {
    if ($now === $prev) return ['same as last week', 'is-flat'];
    if ($prev === 0) return [$now > 0 ? 'new this week' : 'same as last week', $now > 0 ? 'is-up' : 'is-flat'];
    $pc = (int)round(($now - $prev) * 100 / $prev);
    return [($pc > 0 ? '+' : '−') . abs($pc) . '% on last week', $pc > 0 ? 'is-up' : 'is-down'];
}

/** A small trend line (SVG) for a stat card. */
function aw_dsh_spark(array $vals, $label = '') {
    $vals = array_values($vals);
    $n = count($vals);
    if ($n < 2) return '';
    $w = 120; $h = 36; $max = max(1, max($vals));
    $pts = [];
    foreach ($vals as $i => $v) $pts[] = round($i * $w / ($n - 1), 1) . ',' . round($h - 3 - ($v / $max) * ($h - 8), 1);
    $line = 'M' . implode(' L', $pts);
    $area = $line . " L$w,$h L0,$h Z";
    return '<svg class="dsh-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
        . '<path class="dsh-spark-area" d="' . $area . '"/><path class="dsh-spark-line" d="' . $line . '" pathLength="100"/></svg>';
}

/**
 * The activity chart: several daily series as smooth lines with soft fills, drawn when shown.
 * $series = [['label' => 'Views', 'class' => 's1', 'data' => ['Y-m-d' => n]], ...]
 */
function aw_dsh_chart(array $series, $title) {
    if (!$series) return '';
    $days = array_keys($series[0]['data']);
    $n = count($days);
    $W = 720; $H = 240; $pl = 34; $pr = 12; $pt = 14; $pb = 28;
    $max = 1;
    foreach ($series as $s) $max = max($max, max($s['data']));
    $nice = $max <= 5 ? 5 : (int)(ceil($max / pow(10, floor(log10($max)))) * pow(10, floor(log10($max))));
    if ($nice < $max) $nice = $max;
    $x = function ($i) use ($n, $W, $pl, $pr) { return $pl + ($n > 1 ? $i * ($W - $pl - $pr) / ($n - 1) : 0); };
    $y = function ($v) use ($nice, $H, $pt, $pb) { return $H - $pb - ($v / $nice) * ($H - $pt - $pb); };
    $svg = '<svg class="dsh-chart-svg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . htmlspecialchars($title) . '. A table with the same numbers follows.">';
    for ($g = 0; $g <= 4; $g++) { // grid
        $gv = $nice * $g / 4; $gy = round($y($gv), 1);
        $svg .= '<line class="dsh-grid" x1="' . $pl . '" x2="' . ($W - $pr) . '" y1="' . $gy . '" y2="' . $gy . '"/>'
              . '<text class="dsh-axis" x="' . ($pl - 6) . '" y="' . ($gy + 4) . '" text-anchor="end">' . (int)round($gv) . '</text>';
    }
    foreach ($days as $i => $d) if ($i % 2 === ($n - 1) % 2) $svg .= '<text class="dsh-axis" x="' . round($x($i), 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . gmdate('j M', strtotime($d)) . '</text>';
    foreach ($series as $k => $s) {
        $vals = array_values($s['data']);
        $pts = [];
        foreach ($vals as $i => $v) $pts[] = [round($x($i), 1), round($y($v), 1)];
        // a gentle curve through the points (Catmull-Rom to cubic Bézier)
        $d = 'M' . $pts[0][0] . ',' . $pts[0][1];
        for ($i = 0; $i < count($pts) - 1; $i++) {
            $p0 = $pts[max(0, $i - 1)]; $p1 = $pts[$i]; $p2 = $pts[$i + 1]; $p3 = $pts[min(count($pts) - 1, $i + 2)];
            $c1 = [round($p1[0] + ($p2[0] - $p0[0]) / 6, 1), round(min($H - $pb, $p1[1] + ($p2[1] - $p0[1]) / 6), 1)];
            $c2 = [round($p2[0] - ($p3[0] - $p1[0]) / 6, 1), round(min($H - $pb, $p2[1] - ($p3[1] - $p1[1]) / 6), 1)];
            $d .= " C{$c1[0]},{$c1[1]} {$c2[0]},{$c2[1]} {$p2[0]},{$p2[1]}";
        }
        $base = $H - $pb;
        $svg .= '<path class="dsh-area ' . $s['class'] . '" d="' . $d . ' L' . end($pts)[0] . ',' . $base . ' L' . $pts[0][0] . ',' . $base . ' Z"/>';
        $svg .= '<path class="dsh-line ' . $s['class'] . '" d="' . $d . '" pathLength="100" style="--k:' . $k . '"/>';
        foreach ($pts as $i => $p) $svg .= '<circle class="dsh-dot ' . $s['class'] . '" cx="' . $p[0] . '" cy="' . $p[1] . '" r="3.5"><title>' . htmlspecialchars($s['label'] . ', ' . gmdate('j M', strtotime($days[$i])) . ': ' . $vals[$i]) . '</title></circle>';
    }
    $svg .= '</svg>';
    // the same numbers as a table (screen readers, and anyone who prefers figures)
    $tbl = '<details class="dsh-table"><summary>Show the numbers</summary><div class="dsh-table-wrap"><table><thead><tr><th scope="col">Day</th>';
    foreach ($series as $s) $tbl .= '<th scope="col">' . htmlspecialchars($s['label']) . '</th>';
    $tbl .= '</tr></thead><tbody>';
    foreach (array_reverse($days) as $d) {
        $tbl .= '<tr><th scope="row">' . gmdate('D j M', strtotime($d)) . '</th>';
        foreach ($series as $s) $tbl .= '<td>' . (int)$s['data'][$d] . '</td>';
        $tbl .= '</tr>';
    }
    $tbl .= '</tbody></table></div></details>';
    $legend = '<ul class="dsh-legend">';
    foreach ($series as $s) $legend .= '<li><span class="dsh-key ' . $s['class'] . '" aria-hidden="true"></span>' . htmlspecialchars($s['label']) . ' <strong>' . number_format(array_sum($s['data'])) . '</strong></li>';
    $legend .= '</ul>';
    return $legend . $svg . $tbl;
}

/** "just now", "5 min ago", "3 h ago", "yesterday", "4 days ago", "12 Nov". */
function aw_dsh_ago($when) {
    $t = strtotime((string)$when . (preg_match('/[zZ]|[+-]\d\d:?\d\d$/', (string)$when) ? '' : ' UTC'));
    if (!$t) return '';
    $s = time() - $t;
    if ($s < 60) return 'just now';
    if ($s < 3600) return (int)floor($s / 60) . ' min ago';
    if ($s < 86400) return (int)floor($s / 3600) . ' h ago';
    if ($s < 172800) return 'yesterday';
    if ($s < 604800) return (int)floor($s / 86400) . ' days ago';
    return gmdate('j M', $t);
}

function aw_dsh_short($text, $len) {
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$text), ENT_QUOTES, 'UTF-8')));
    return mb_strlen($t) > $len ? rtrim(mb_substr($t, 0, $len)) . '…' : $t;
}

/**
 * What happened recently on the site, newest first, for the admin dashboard. Each source is read only if
 * the viewer can act on it. Stories & Help keep their pseudonyms (aw_cp_author_label); private posts
 * appear only for the owner and admins. Returns [['when', 'icon', 'tone', 'who', 'what', 'title', 'link'], ...].
 */
function aw_dsh_feed(PDO $db, $limit = 14) {
    $u = rtrim(SITE_URL, '/');
    $name = "COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')), ''), u.username, 'A member')";
    $ev = [];
    $add = function ($rows, $fn) use (&$ev) { foreach ($rows as $r) { $e = $fn($r); if ($e) $ev[] = $e; } };
    $can = function ($c) { return function_exists('aw_can') && aw_can($c); };
    $L = 10;
    if ($can('manage_members')) $add(aw_dsh_q($db, "SELECT u.id, $name AS who, u.created_at AS t FROM users u ORDER BY u.created_at DESC LIMIT $L"),
        function ($r) use ($u) { return [$r['t'], 'fa-user-plus', 't-member', $r['who'], 'joined AngelWrites', '', $u . '/admin/manage_users.php']; });
    if ($can('moderate')) {
        $add(aw_dsh_q($db, "SELECT r.target_type, r.target_id, r.comment, r.created_at AS t, $name AS who,
                                   CASE r.target_type WHEN 'poem' THEN (SELECT title FROM poems WHERE id = r.target_id) WHEN 'book' THEN (SELECT title FROM books WHERE id = r.target_id) END AS title
                            FROM reviews r LEFT JOIN users u ON u.id = r.user_id WHERE r.deleted_at IS NULL ORDER BY r.created_at DESC LIMIT $L"),
            function ($r) use ($u) { return [$r['t'], 'fa-comment-dots', 't-comment', $r['who'], 'commented on ' . ($r['target_type'] === 'book' ? 'the book' : 'the poem'), (string)$r['title'], $u . '/admin/comments.php']; });
        $add(aw_dsh_q($db, "SELECT q.id, q.title, q.created_at AS t, $name AS who FROM questions q LEFT JOIN users u ON u.id = q.user_id ORDER BY q.created_at DESC LIMIT $L"),
            function ($r) use ($u) { return [$r['t'], 'fa-circle-question', 't-community', $r['who'], 'asked in Community Q&A', $r['title'], $u . '/community.php?id=' . (int)$r['id']]; });
        $add(aw_dsh_q($db, "SELECT a.question_id, a.created_at AS t, $name AS who, q.title FROM answers a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN questions q ON q.id = a.question_id ORDER BY a.created_at DESC LIMIT $L"),
            function ($r) use ($u) { return [$r['t'], 'fa-reply', 't-community', $r['who'], 'answered', (string)$r['title'], $u . '/community.php?id=' . (int)$r['question_id']]; });
        $add(aw_dsh_q($db, "SELECT c.reflection_id, c.created_at AS t, $name AS who, b.title, b.slug FROM reflection_comments c LEFT JOIN users u ON u.id = c.user_id LEFT JOIN blog_posts b ON b.id = c.reflection_id ORDER BY c.created_at DESC LIMIT $L"),
            function ($r) use ($u) { return [$r['t'], 'fa-comment', 't-comment', $r['who'], 'commented on the reflection', (string)$r['title'], $u . '/blog_post.php?slug=' . urlencode((string)$r['slug']) . '#comments']; });
        $add(aw_dsh_q($db, "SELECT c.video_id, c.created_at AS t, $name AS who, v.title FROM video_comments c LEFT JOIN users u ON u.id = c.user_id LEFT JOIN videos v ON v.id = c.video_id ORDER BY c.created_at DESC LIMIT $L"),
            function ($r) use ($u) { return [$r['t'], 'fa-comment', 't-comment', $r['who'], 'commented on the video', (string)$r['title'], $u . '/video_watch.php?id=' . (int)$r['video_id']]; });
        if (function_exists('aw_cp_author_label')) {
            $team = (function_exists('aw_cp_staff_full') && aw_cp_staff_full()) ? '' : " AND p.visibility <> 'team'";
            $add(aw_dsh_q($db, "SELECT p.* , COALESCE(p.published_at, p.created_at) AS t FROM community_posts p WHERE p.status <> 'removed'$team ORDER BY p.created_at DESC LIMIT $L"),
                function ($r) use ($db, $u) {
                    $kind = ['story' => 'shared a story', 'experience' => 'shared an experience', 'question' => 'asked a question', 'help' => 'asked for help'][$r['type']] ?? 'shared a post';
                    if ($r['status'] === 'held') $kind .= ' (waiting for a first read)';
                    return [$r['created_at'], 'fa-seedling', 't-community', aw_cp_author_label($db, $r), $kind . ' in Stories & Help', $r['title'], $u . '/story.php?id=' . (int)$r['id']];
                });
            $add(aw_dsh_q($db, "SELECT r.*, p.title, p.visibility, p.pseudonym AS post_pseudonym, p.author_id AS post_author, p.type, p.status AS post_status, p.category, p.warnings, p.id AS pid
                                FROM community_replies r JOIN community_posts p ON p.id = r.post_id WHERE r.status = 'published'$team ORDER BY r.created_at DESC LIMIT $L"),
                function ($r) use ($db, $u) {
                    $post = ['visibility' => $r['visibility'], 'pseudonym' => $r['post_pseudonym'], 'author_id' => $r['post_author']];
                    return [$r['created_at'], 'fa-hand-holding-heart', 't-community', aw_cp_reply_label($db, $r, $post), 'replied in Stories & Help', $r['title'], $u . '/story.php?id=' . (int)$r['pid'] . '#reply-' . (int)$r['id']];
                });
        }
        $add(aw_dsh_q($db, "SELECT p.created_at AS t, $name AS who FROM prayer_requests p LEFT JOIN users u ON u.id = p.user_id ORDER BY p.created_at DESC LIMIT 5"),
            function ($r) use ($u) { return [$r['t'], 'fa-hands-praying', 't-care', $r['who'], 'sent a prayer request', '', $u . '/admin/manage_prayer_requests.php']; });
    }
    if ($can('manage_payments')) $add(aw_dsh_q($db, "SELECT p.created_at AS t, p.status, $name AS who, b.title FROM payments p LEFT JOIN users u ON u.id = p.user_id LEFT JOIN books b ON b.id = p.book_id ORDER BY p.created_at DESC LIMIT 5"),
        function ($r) use ($u) { return [$r['t'], 'fa-receipt', 't-money', $r['who'], $r['status'] === 'pending' ? 'ordered (to confirm)' : 'bought', (string)$r['title'], $u . '/admin/manage_payments.php']; });
    if ($can('manage_members')) $add(aw_dsh_q($db, "SELECT s.created_at AS t, s.status, $name AS who FROM sessions s LEFT JOIN users u ON u.id = s.user_id ORDER BY s.created_at DESC LIMIT 5"),
        function ($r) use ($u) { return [$r['t'], 'fa-calendar-check', 't-care', $r['who'], $r['status'] === 'pending' ? 'asked for a session' : 'has a session (' . $r['status'] . ')', '', $u . '/admin/manage_sessions.php']; });
    if ($can('team_inbox')) {
        $add(aw_dsh_q($db, "SELECT c.created_at AS t, c.name AS who, c.subject FROM contact_messages c ORDER BY c.created_at DESC LIMIT 5"),
            function ($r) use ($u) { return [$r['t'], 'fa-envelope', 't-care', $r['who'] ?: 'Someone', 'wrote through Contact', (string)$r['subject'], $u . '/admin/inbox.php']; });
        $add(aw_dsh_q($db, "SELECT m.created_at AS t, m.thread_id, $name AS who FROM messages m JOIN msg_threads th ON th.id = m.thread_id LEFT JOIN users u ON u.id = m.sender_id WHERE th.kind = 'team' AND m.as_team = 0 ORDER BY m.id DESC LIMIT 5"),
            function ($r) use ($u) { return [$r['t'], 'fa-heart', 't-care', $r['who'], 'wrote to the team in Messages', '', $u . '/messages.php?t=' . (int)$r['thread_id'] . '&team=1']; });
    }
    usort($ev, function ($a, $b) { return strcmp((string)$b[0], (string)$a[0]); });
    return array_slice($ev, 0, $limit);
}
