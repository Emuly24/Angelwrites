<?php
/**
 * Community Q&A, closer to Quora and Reddit, without points or ranks (no gamification):
 *  - a discussion under every answer: comments with replies to any depth (answer_comments)
 *  - follow a question: followers hear about new answers (question_follows); asking or answering follows it
 *  - "This helped me" can be taken back; the count is recounted from the marks, so it cannot drift
 *  - like / love / pray on answers, with counts (the buttons posted to a handler that did not exist)
 *  - answers in the order the reader chooses: most helpful, newest or oldest (the accepted one first)
 * Every function returns a value and logs on failure; nothing throws.
 */
require_once __DIR__ . '/comment_threads.php';

function aw_qa_tables(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS answer_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, answer_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
                   parent_id INTEGER, body TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_answer_comments ON answer_comments (answer_id, id)");
        $db->exec("CREATE TABLE IF NOT EXISTS question_follows (question_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
                   created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (question_id, user_id))");
        return $ok = true;
    } catch (Throwable $e) { error_log('qa tables: ' . $e->getMessage()); return $ok = false; }
}

function aw_qa_name() {
    return function_exists('awDisplayName') ? awDisplayName(['name' => $_SESSION['name'] ?? '', 'username' => $_SESSION['username'] ?? '']) : 'Someone';
}

function aw_qa_follow(PDO $db, $qid, $uid, $on = true) {
    if (!aw_qa_tables($db) || !$uid) return false;
    try {
        if ($on) $db->prepare("INSERT OR IGNORE INTO question_follows (question_id, user_id) VALUES (?, ?)")->execute([(int)$qid, (int)$uid]);
        else $db->prepare("DELETE FROM question_follows WHERE question_id = ? AND user_id = ?")->execute([(int)$qid, (int)$uid]);
        return true;
    } catch (Throwable $e) { error_log('qa follow: ' . $e->getMessage()); return false; }
}

function aw_qa_following(PDO $db, $qid, $uid) {
    if (!aw_qa_tables($db) || !$uid) return false;
    try { $st = $db->prepare("SELECT 1 FROM question_follows WHERE question_id = ? AND user_id = ?"); $st->execute([(int)$qid, (int)$uid]); return (bool)$st->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

function aw_qa_follower_count(PDO $db, $qid) {
    if (!aw_qa_tables($db)) return 0;
    try { $st = $db->prepare("SELECT COUNT(*) FROM question_follows WHERE question_id = ?"); $st->execute([(int)$qid]); return (int)$st->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/** Tell followers about a new answer (not the person who answered, not the asker: they are told already). */
function aw_qa_tell_followers(PDO $db, $qid, $title, $answerer, $asker) {
    if (!aw_qa_tables($db)) return;
    try {
        require_once __DIR__ . '/notifications.php';
        $st = $db->prepare("SELECT user_id FROM question_follows WHERE question_id = ? AND user_id NOT IN (?, ?)");
        $st->execute([(int)$qid, (int)$answerer, (int)$asker]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) {
            awNotify($db, (int)$u, 'answer', 'New answer to a question you follow', '“' . mb_substr((string)$title, 0, 80) . '”', SITE_URL . '/community.php?id=' . (int)$qid, ['actor' => (int)$answerer]);
        }
    } catch (Throwable $e) { error_log('qa followers: ' . $e->getMessage()); }
}

/** "This helped me", as a toggle. Returns ['ok', 'on', 'count'] or ['ok' => false, 'error']. */
function aw_qa_toggle_helpful(PDO $db, $answer_id, $uid) {
    try {
        $st = $db->prepare("SELECT user_id FROM answers WHERE id = ?");
        $st->execute([(int)$answer_id]);
        $author = $st->fetchColumn();
        if (!$author) return ['ok' => false, 'error' => 'That answer is no longer here.'];
        if ((int)$author === (int)$uid) return ['ok' => false, 'error' => 'This is your own answer.'];
        $del = $db->prepare("DELETE FROM reactions WHERE user_id = ? AND target_type = 'answer' AND target_id = ? AND reaction_type = 'upvote'");
        $del->execute([(int)$uid, (int)$answer_id]);
        $on = $del->rowCount() === 0;
        if ($on) $db->prepare("INSERT OR IGNORE INTO reactions (user_id, target_type, target_id, reaction_type) VALUES (?, 'answer', ?, 'upvote')")->execute([(int)$uid, (int)$answer_id]);
        $n = $db->prepare("SELECT COUNT(*) FROM reactions WHERE target_type = 'answer' AND target_id = ? AND reaction_type = 'upvote'");
        $n->execute([(int)$answer_id]);
        $count = (int)$n->fetchColumn();
        $db->prepare("UPDATE answers SET upvotes = ? WHERE id = ?")->execute([$count, (int)$answer_id]);
        return ['ok' => true, 'on' => $on, 'count' => $count];
    } catch (Throwable $e) { error_log('qa helpful: ' . $e->getMessage()); return ['ok' => false, 'error' => 'That did not work. Please try again.']; }
}

/** like / love / pray on an answer, as a toggle. Returns counts and the member's own. */
function aw_qa_toggle_reaction(PDO $db, $answer_id, $uid, $type) {
    if (!in_array($type, ['like', 'love', 'pray'], true)) return ['ok' => false, 'error' => 'Unknown reaction.'];
    try {
        $st = $db->prepare("SELECT 1 FROM answers WHERE id = ?");
        $st->execute([(int)$answer_id]);
        if (!$st->fetchColumn()) return ['ok' => false, 'error' => 'That answer is no longer here.'];
        $del = $db->prepare("DELETE FROM reactions WHERE user_id = ? AND target_type = 'answer_rx' AND target_id = ? AND reaction_type = ?");
        $del->execute([(int)$uid, (int)$answer_id, $type]);
        if ($del->rowCount() === 0) $db->prepare("INSERT OR IGNORE INTO reactions (user_id, target_type, target_id, reaction_type) VALUES (?, 'answer_rx', ?, ?)")->execute([(int)$uid, (int)$answer_id, $type]);
        return ['ok' => true] + aw_qa_reactions($db, [(int)$answer_id], $uid)[(int)$answer_id];
    } catch (Throwable $e) { error_log('qa reaction: ' . $e->getMessage()); return ['ok' => false, 'error' => 'That did not work. Please try again.']; }
}

/** [answer_id => ['counts' => [like, love, pray], 'mine' => [...]]] for many answers in one query. */
function aw_qa_reactions(PDO $db, array $ids, $uid) {
    $out = [];
    foreach ($ids as $id) $out[(int)$id] = ['counts' => ['like' => 0, 'love' => 0, 'pray' => 0], 'mine' => []];
    if (!$ids) return $out;
    try {
        $st = $db->prepare("SELECT target_id, reaction_type, COUNT(*) n, MAX(user_id = ?) m FROM reactions WHERE target_type = 'answer_rx' AND target_id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ") GROUP BY target_id, reaction_type");
        $st->execute(array_merge([(int)$uid], array_map('intval', $ids)));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['target_id']; $t = $r['reaction_type'];
            if (!isset($out[$id]['counts'][$t])) continue;
            $out[$id]['counts'][$t] = (int)$r['n'];
            if ((int)$r['m'] === 1) $out[$id]['mine'][] = $t;
        }
    } catch (Throwable $e) { error_log('qa reactions: ' . $e->getMessage()); }
    return $out;
}

/** Add a comment (or a reply to one) under an answer. Returns ['ok', 'message', 'id']. */
function aw_qa_add_comment(PDO $db, $answer_id, $uid, $body, $parent_id = 0) {
    if (!aw_qa_tables($db)) return ['ok' => false, 'message' => 'Comments are not available just now.'];
    $body = trim((string)$body);
    if ($body === '') return ['ok' => false, 'message' => 'Please write a comment.'];
    if (mb_strlen($body) > 2000) return ['ok' => false, 'message' => 'Please keep a comment under 2,000 characters.'];
    try {
        if (function_exists('aw_cp_restriction') && ($r = aw_cp_restriction($db, $uid))) return ['ok' => false, 'message' => is_string($r) ? $r : 'Posting is paused on your account for now.'];
        $st = $db->prepare("SELECT a.user_id, a.question_id, q.title FROM answers a JOIN questions q ON q.id = a.question_id WHERE a.id = ?");
        $st->execute([(int)$answer_id]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) return ['ok' => false, 'message' => 'That answer is no longer here.'];
        $parent = null;
        if ($parent_id) {
            $ps = $db->prepare("SELECT id, user_id FROM answer_comments WHERE id = ? AND answer_id = ? AND deleted_at IS NULL");
            $ps->execute([(int)$parent_id, (int)$answer_id]);
            $parent = $ps->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $cnt = $db->prepare("SELECT COUNT(*) FROM answer_comments WHERE user_id = ? AND created_at > datetime('now', '-1 day')");
        $cnt->execute([(int)$uid]);
        if ((int)$cnt->fetchColumn() >= 100) return ['ok' => false, 'message' => 'You have written a lot of comments today. Please come back tomorrow.'];
        $db->prepare("INSERT INTO answer_comments (answer_id, user_id, parent_id, body) VALUES (?, ?, ?, ?)")->execute([(int)$answer_id, (int)$uid, $parent ? (int)$parent['id'] : null, $body]);
        $cid = (int)$db->lastInsertId();
        require_once __DIR__ . '/notifications.php';
        $link = SITE_URL . '/community.php?id=' . (int)$a['question_id'] . '#ac' . $cid;
        $who = aw_qa_name();
        $told = [(int)$uid => true];
        if ($parent && empty($told[(int)$parent['user_id']])) {
            awNotify($db, (int)$parent['user_id'], 'comment_reply', $who . ' replied to your comment', 'On “' . mb_substr((string)$a['title'], 0, 80) . '”.', $link, ['actor' => (int)$uid]);
            $told[(int)$parent['user_id']] = true;
        }
        if (empty($told[(int)$a['user_id']])) {
            awNotify($db, (int)$a['user_id'], 'reply', $who . ' commented on your answer', 'On “' . mb_substr((string)$a['title'], 0, 80) . '”.', $link, ['actor' => (int)$uid]);
        }
        require_once __DIR__ . '/mentions.php';
        if (function_exists('aw_mentions_save')) aw_mentions_save($db, $body, 'answer_comment', $cid, 'the question “' . mb_substr((string)$a['title'], 0, 80) . '”', $link, $who);
        return ['ok' => true, 'message' => 'Comment posted.', 'id' => $cid, 'question' => (int)$a['question_id']];
    } catch (Throwable $e) { error_log('qa comment: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Your comment was not saved. Please try again.']; }
}

/** Remove a comment: its author, or the community team. Replies under it stay, shown under "removed". */
function aw_qa_delete_comment(PDO $db, $cid, $uid, $staff = false) {
    if (!aw_qa_tables($db)) return false;
    try {
        $st = $db->prepare("UPDATE answer_comments SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL" . ($staff ? '' : ' AND user_id = ?'));
        $st->execute($staff ? [(int)$cid] : [(int)$cid, (int)$uid]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) { error_log('qa delete comment: ' . $e->getMessage()); return false; }
}

/** [answer_id => threads] for the answers on a page. A removed comment with replies stays as "removed". */
function aw_qa_comments(PDO $db, array $answer_ids) {
    $out = [];
    if (!$answer_ids || !aw_qa_tables($db)) return $out;
    try {
        $st = $db->prepare("SELECT c.*, COALESCE(NULLIF(TRIM(u.name), ''), u.username, 'A member') AS author_name FROM answer_comments c LEFT JOIN users u ON u.id = c.user_id
                            WHERE c.answer_id IN (" . implode(',', array_fill(0, count($answer_ids), '?')) . ") ORDER BY c.id");
        $st->execute(array_map('intval', $answer_ids));
        $by = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $by[(int)$r['answer_id']][] = $r;
        foreach ($by as $aid => $rows) {
            $tree = aw_thread_tree($rows);
            // drop removed comments that have no replies left
            $prune = function (array $list) use (&$prune) {
                $keep = [];
                foreach ($list as $c) { $c['children'] = $prune($c['children']); if (!$c['deleted_at'] || $c['children']) $keep[] = $c; }
                return $keep;
            };
            $out[$aid] = $prune($tree);
        }
    } catch (Throwable $e) { error_log('qa comments: ' . $e->getMessage()); }
    return $out;
}

function aw_qa_count(array $threads) {
    $n = 0;
    foreach ($threads as $c) { if (!$c['deleted_at']) $n++; $n += aw_qa_count($c['children']); }
    return $n;
}

/** One comment and its replies. */
function aw_qa_render_comment(array $c, $qid, $uid, $staff, $token) {
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $h = '<li class="qa-c" id="ac' . (int)$c['id'] . '">';
    if ($c['deleted_at']) {
        $h .= '<p class="qa-c-removed">This comment was removed.</p>';
    } else {
        $when = strtotime((string)$c['created_at'] . ' UTC');
        $h .= '<div class="qa-c-head"><strong>' . $e($c['author_name']) . '</strong><time datetime="' . $e(gmdate('c', $when)) . '">' . $e(date('j M Y', $when)) . '</time></div>';
        $body = nl2br($e($c['body']));
        if (function_exists('aw_mentions_render')) $body = aw_mentions_render($GLOBALS['db'], $body, 'answer_comment', (int)$c['id']);
        $h .= '<div class="qa-c-body">' . $body . '</div><div class="qa-c-acts">';
        if ($uid) $h .= aw_thread_reply_button($c['id'], $c['author_name']);
        if ($uid && ((int)$c['user_id'] === (int)$uid || $staff)) {
            $h .= '<form method="post" class="qa-inline" onsubmit="return confirm(\'Remove this comment?\');"><input type="hidden" name="csrf_token" value="' . $e($token) . '">'
                . '<input type="hidden" name="delete_answer_comment" value="' . (int)$c['id'] . '"><input type="hidden" name="question_id" value="' . (int)$qid . '">'
                . '<button type="submit" class="qa-link">Remove</button></form>';
        }
        if ($uid && (int)$c['user_id'] !== (int)$uid && function_exists('aw_report_controls')) $h .= aw_report_controls('answer_comment', (int)$c['id'], (int)$c['user_id'], (string)$c['author_name']);
        $h .= '</div>';
        if ($uid) $h .= aw_thread_reply_form($c['id'], $c['author_name'], ['add_answer_comment' => '1', 'answer_id' => (int)$c['answer_id'], 'question_id' => (int)$qid], 'qa_reply');
    }
    if ($c['children']) {
        $h .= '<ul class="qa-c-list qa-c-kids">';
        foreach ($c['children'] as $k) $h .= aw_qa_render_comment($k, $qid, $uid, $staff, $token);
        $h .= '</ul>';
    }
    return $h . '</li>';
}

function aw_qa_assets() {
    return aw_thread_assets() . '<style>
.qa-discuss{margin-top:12px;border-top:1px solid var(--border,#ecdcdc);padding-top:10px}
.qa-discuss>summary{cursor:pointer;font-weight:600;font-size:.9rem;color:var(--rose,#9c5457);list-style:none;display:inline-flex;align-items:center;gap:6px;min-height:36px}
.qa-discuss>summary::-webkit-details-marker{display:none}.qa-discuss>summary:focus-visible{outline:2px solid currentColor;outline-offset:2px;border-radius:6px}
.qa-c-list{list-style:none;margin:8px 0 0;padding:0}.qa-c-kids{margin-left:16px;padding-left:12px;border-left:2px solid #ead9d6}
.qa-c{padding:8px 0}.qa-c-head{display:flex;gap:8px;align-items:baseline;font-size:.86rem}.qa-c-head time{color:var(--text-light,#6b5a5a);font-size:.78rem}
.qa-c-body{font-size:.95rem;line-height:1.55;margin:2px 0}.qa-c-removed{font-style:italic;color:var(--text-light,#6b5a5a);font-size:.88rem;margin:0}
.qa-c-acts{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.qa-inline{display:inline;margin:0}
.qa-link{background:none;border:0;padding:6px 2px;font:inherit;font-size:.82rem;color:var(--text-light,#6b5a5a);text-decoration:underline;cursor:pointer}
.qa-add{margin-top:8px;display:flex;gap:8px;align-items:flex-end}.qa-add textarea{flex:1;min-height:44px;padding:10px 12px;border:1px solid var(--border,#e2d2d2);border-radius:12px;font:inherit;resize:vertical;background:var(--card-bg,#fff);color:inherit}
.qa-rx{display:inline-flex;gap:6px}.qa-rx button{display:inline-flex;align-items:center;gap:4px;min-height:36px;padding:4px 10px;border-radius:999px;border:1px solid var(--border,#ecdcdc);background:var(--card-bg,#fff);cursor:pointer;font:inherit;font-size:.88rem;color:inherit}
.qa-rx button.active{background:#f6e7e6;border-color:#c48f91}.qa-rx button:focus-visible{outline:2px solid #9c5457;outline-offset:2px}
.qa-follow{display:inline-flex;align-items:center;gap:6px}.qa-follow .btn.active{background:#9c5457;color:#fff;border-color:#9c5457}
.qa-sort{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 10px;font-size:.9rem}.qa-sort a{padding:4px 12px;border-radius:999px;border:1px solid var(--border,#ecdcdc);text-decoration:none;color:inherit}
.qa-sort a[aria-current]{background:#9c5457;color:#fff;border-color:#9c5457}
.qa-answers-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
html[data-theme="dark"] .qa-c-kids{border-color:#4a3434}html[data-theme="dark"] .qa-rx button.active{background:#3a2526;border-color:#7a4c4f}
@media (max-width:520px){.qa-c-kids{margin-left:6px;padding-left:10px}.qa-add{flex-direction:column;align-items:stretch}}
</style>';
}
