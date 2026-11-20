<?php
/**
 * Book clubs: a reading group reading a book together.
 *  - The club's goal is the next date in the group's schedule ("read to Chapter 5 by Sunday"); it is
 *    shown on the group page and inside the book reader.
 *  - Where each member is comes from the reader itself (reading_progress.chapter_index), so nobody has
 *    to tick chapters by hand; the group's own progress ticks still count.
 *  - Each chapter has its conversation; conversations about chapters a member has not reached are
 *    hidden behind a spoiler shield until they choose to see them.
 * Every function returns a value and logs on failure; nothing throws.
 */

/** Chapter titles for a book (from its contents list), indexed like the reader's chapter_index. */
function aw_club_chapters(PDO $db, $book_id) {
    static $cache = [];
    if (isset($cache[$book_id])) return $cache[$book_id];
    $out = [];
    try {
        $st = $db->prepare("SELECT toc_json, content_html FROM book_content WHERE book_id = ?");
        $st->execute([(int)$book_id]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            foreach ((array)json_decode((string)$r['toc_json'], true) as $i => $t) $out[$i] = ['title' => trim((string)($t['title'] ?? '')) ?: 'Chapter ' . ($i + 1), 'page' => (int)($t['page'] ?? 0)];
            if (!$out) { $n = max(1, preg_match_all('/<div class="chapter-container"/', (string)$r['content_html'])); for ($i = 0; $i < $n; $i++) $out[$i] = ['title' => 'Chapter ' . ($i + 1), 'page' => 0]; }
        }
    } catch (Throwable $e) { error_log('club chapters: ' . $e->getMessage()); }
    return $cache[$book_id] = $out;
}

function aw_club_chapter_title(PDO $db, $book_id, $i) {
    $c = aw_club_chapters($db, $book_id);
    return $c[$i]['title'] ?? 'Chapter ' . ((int)$i + 1);
}

/** The club's goal: the next scheduled chapter (or the last one if all dates have passed). */
function aw_club_goal(PDO $db, $group_id) {
    try {
        $st = $db->prepare("SELECT chapter_index, due_date FROM group_schedules WHERE group_id = ? AND date(due_date) >= date('now') ORDER BY due_date, chapter_index LIMIT 1");
        $st->execute([(int)$group_id]);
        $g = $st->fetch(PDO::FETCH_ASSOC);
        if (!$g) {
            $st = $db->prepare("SELECT chapter_index, due_date FROM group_schedules WHERE group_id = ? ORDER BY due_date DESC LIMIT 1");
            $st->execute([(int)$group_id]);
            $g = $st->fetch(PDO::FETCH_ASSOC);
            if ($g) $g['past'] = true;
        }
        return $g ?: null;
    } catch (Throwable $e) { error_log('club goal: ' . $e->getMessage()); return null; }
}

/** Furthest chapter each member has reached: [user_id => chapter_index], from the reader and the group's ticks. */
function aw_club_positions(PDO $db, $group_id, $book_id) {
    $out = [];
    try {
        $st = $db->prepare("SELECT m.user_id, MAX(COALESCE(rp.chapter_index, -1)) AS c FROM group_members m
                            LEFT JOIN reading_progress rp ON rp.user_id = m.user_id AND rp.book_id = ? WHERE m.group_id = ? GROUP BY m.user_id");
        $st->execute([(int)$book_id, (int)$group_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['user_id']] = (int)$r['c'];
        $st = $db->prepare("SELECT user_id, MAX(chapter_index) AS c FROM group_reading_progress WHERE group_id = ? AND status IN ('completed', 'done', 'read') GROUP BY user_id");
        $st->execute([(int)$group_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['user_id']] = max($out[(int)$r['user_id']] ?? -1, (int)$r['c']);
    } catch (Throwable $e) { error_log('club positions: ' . $e->getMessage()); }
    return $out;
}

/** Conversations per chapter: [chapter_index => count]. */
function aw_club_threads(PDO $db, $group_id) {
    $out = [];
    try {
        $st = $db->prepare("SELECT chapter_index, COUNT(*) AS n FROM group_discussions WHERE group_id = ? AND chapter_index IS NOT NULL GROUP BY chapter_index");
        $st->execute([(int)$group_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['chapter_index']] = (int)$r['n'];
    } catch (Throwable $e) { error_log('club threads: ' . $e->getMessage()); }
    return $out;
}

/** The clubs the member belongs to that are reading this book, with their goal, for the reader. */
function aw_club_for_book(PDO $db, $uid, $book_id) {
    $out = [];
    try {
        $st = $db->prepare("SELECT g.id, g.name FROM reading_groups g JOIN group_members m ON m.group_id = g.id AND m.user_id = ?
                            WHERE (g.content_type = 'book' AND g.content_id = ?) OR (COALESCE(g.content_type, 'book') = 'book' AND g.book_id = ?) GROUP BY g.id");
        $st->execute([(int)$uid, (int)$book_id, (int)$book_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $goal = aw_club_goal($db, (int)$g['id']);
            $pos = aw_club_positions($db, (int)$g['id'], (int)$book_id);
            $there = $goal ? count(array_filter($pos, function ($c) use ($goal) { return $c >= (int)$goal['chapter_index']; })) : 0;
            $out[] = ['id' => (int)$g['id'], 'name' => $g['name'], 'goal' => $goal, 'there' => $there, 'members' => count($pos)];
        }
    } catch (Throwable $e) { error_log('club for book: ' . $e->getMessage()); }
    return $out;
}

/** "by Sunday 24 November" / "by today" / "(was due 3 Nov)". */
function aw_club_when($goal) {
    if (!$goal) return '';
    $t = strtotime((string)$goal['due_date']);
    if (!empty($goal['past'])) return 'was due ' . date('j M', $t);
    $d = (int)floor((strtotime(date('Y-m-d', $t)) - strtotime(date('Y-m-d'))) / 86400);
    return $d === 0 ? 'by today' : ($d === 1 ? 'by tomorrow' : ($d < 7 ? 'by ' . date('l', $t) : 'by ' . date('l j F', $t)));
}
