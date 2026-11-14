<?php
/**
 * G1: planned reads (PROGRAM_PLAN.md Phase 4, wish G v1).
 *
 * A member plans a read by hand: a book, a day, optionally a chapter (its first page) and a note.
 * There is no cron on this host, so the reminder is given the next time the member uses the site
 * on or after that day: a bell notification, and an email too if they asked for one.
 *   reading_plans(id, user_id, book_id, plan_date, page, label, note, email, reminded_at, done_at, created_at)
 * Nothing here throws.
 */
require_once __DIR__ . '/notifications.php';

function aw_plans_table(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS reading_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, book_id INTEGER NOT NULL,
                   plan_date DATE NOT NULL, page INTEGER, label TEXT, note TEXT, email INTEGER NOT NULL DEFAULT 0,
                   reminded_at DATETIME, done_at DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_plans_user ON reading_plans (user_id, done_at, plan_date)");
        return $ok = true;
    } catch (Throwable $e) { error_log('plans table: ' . $e->getMessage()); return $ok = false; }
}

/** "Today", "Tomorrow", "Monday 17 Nov", or "Planned for 3 Nov" when it has passed. */
function aw_plan_when($date) {
    $d = strtotime((string)$date . ' 00:00:00');
    if (!$d) return (string)$date;
    $today = strtotime(date('Y-m-d') . ' 00:00:00');
    $diff = (int)round(($d - $today) / 86400);
    if ($diff === 0) return 'Today';
    if ($diff === 1) return 'Tomorrow';
    if ($diff < 0) return 'Planned for ' . date('j M', $d);
    return date($diff < 7 ? 'l' : 'D j M', $d);
}

/** Plans not yet done: past ones and the next $days days, soonest first. */
function aw_plans_upcoming(PDO $db, $uid, $limit = 6, $days = 7) {
    if (!$uid || !aw_plans_table($db)) return [];
    try {
        $st = $db->prepare("SELECT p.*, b.title AS book_title FROM reading_plans p JOIN books b ON b.id = p.book_id
                            WHERE p.user_id = ? AND p.done_at IS NULL AND p.plan_date <= date('now', '+' || ? || ' days')
                            ORDER BY p.plan_date, p.id LIMIT ?");
        $st->execute([(int)$uid, (int)$days, (int)$limit]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('plans upcoming: ' . $e->getMessage()); return []; }
}

function aw_plans_for_book(PDO $db, $uid, $book_id) {
    if (!$uid || !aw_plans_table($db)) return [];
    try {
        $st = $db->prepare("SELECT * FROM reading_plans WHERE user_id = ? AND book_id = ? AND done_at IS NULL ORDER BY plan_date, id");
        $st->execute([(int)$uid, (int)$book_id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('plans for book: ' . $e->getMessage()); return []; }
}

/** Add a plan. Returns ['ok', 'message', 'id']. */
function aw_plan_add(PDO $db, $uid, $book_id, $date, $page = 0, $label = '', $note = '', $email = false) {
    if (!$uid || !aw_plans_table($db)) return ['ok' => false, 'message' => 'Planning is not available right now.'];
    $d = DateTime::createFromFormat('Y-m-d', (string)$date);
    if (!$d || $d->format('Y-m-d') !== $date) return ['ok' => false, 'message' => 'Please choose a day.'];
    if ($date < date('Y-m-d')) return ['ok' => false, 'message' => 'Please choose today or a day ahead.'];
    if ($date > date('Y-m-d', strtotime('+1 year'))) return ['ok' => false, 'message' => 'Please plan within the next year.'];
    $label = mb_substr(trim(strip_tags((string)$label)), 0, 120);
    $note = mb_substr(trim(strip_tags((string)$note)), 0, 300);
    try {
        $b = $db->prepare("SELECT 1 FROM books WHERE id = ?");
        $b->execute([(int)$book_id]);
        if (!$b->fetchColumn()) return ['ok' => false, 'message' => 'That book was not found.'];
        $n = $db->prepare("SELECT COUNT(*) FROM reading_plans WHERE user_id = ? AND done_at IS NULL");
        $n->execute([(int)$uid]);
        if ((int)$n->fetchColumn() >= 60) return ['ok' => false, 'message' => 'You have 60 planned reads already. Please finish or remove some first.'];
        $db->prepare("INSERT INTO reading_plans (user_id, book_id, plan_date, page, label, note, email) VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([(int)$uid, (int)$book_id, $date, (int)$page ?: null, $label !== '' ? $label : null, $note !== '' ? $note : null, $email ? 1 : 0]);
        return ['ok' => true, 'message' => 'Planned for ' . mb_strtolower(aw_plan_when($date)) . '.', 'id' => (int)$db->lastInsertId()];
    } catch (Throwable $e) { error_log('plan add: ' . $e->getMessage()); return ['ok' => false, 'message' => 'That was not saved. Please try again.']; }
}

/** 'done' or 'delete' one of the member's own plans. */
function aw_plan_update(PDO $db, $uid, $id, $what) {
    if (!aw_plans_table($db)) return false;
    try {
        $sql = $what === 'done' ? "UPDATE reading_plans SET done_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?" : "DELETE FROM reading_plans WHERE id = ? AND user_id = ?";
        $st = $db->prepare($sql);
        $st->execute([(int)$id, (int)$uid]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) { error_log('plan update: ' . $e->getMessage()); return false; }
}

/**
 * The reminder: for plans due today or earlier that have not been reminded, a bell notification and,
 * when asked for, a short email. Called once a day per session from the header. Returns how many.
 */
function aw_plans_remind(PDO $db, $uid) {
    if (!$uid || !aw_plans_table($db)) return 0;
    $n = 0;
    try {
        $st = $db->prepare("SELECT p.*, b.title AS book_title FROM reading_plans p JOIN books b ON b.id = p.book_id
                            WHERE p.user_id = ? AND p.done_at IS NULL AND p.reminded_at IS NULL AND p.plan_date <= date('now') ORDER BY p.plan_date LIMIT 5");
        $st->execute([(int)$uid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $link = SITE_URL . '/reader/reader.php?id=' . (int)$p['book_id'] . ($p['page'] ? '&page=' . (int)$p['page'] : '');
            $what = $p['book_title'] . ($p['label'] ? ' — ' . $p['label'] : '');
            awNotify($db, (int)$uid, 'reading_plan', 'Your planned read: ' . $p['book_title'], ($p['label'] ?: 'Pick up where you left off.') . ($p['note'] ? ' ' . $p['note'] : ''), $link, ['email' => false, 'actor' => 0]);
            if ((int)$p['email'] === 1 && function_exists('sendEmail')) {
                try {
                    $u = $db->prepare("SELECT email FROM users WHERE id = ?");
                    $u->execute([(int)$uid]);
                    $to = (string)$u->fetchColumn();
                    if ($to !== '') sendEmail($to, 'Your planned read today', 'You planned to read ' . htmlspecialchars($what) . ' today.<br><br><a href="' . htmlspecialchars($link) . '">Open the book</a><br><br>You asked for this reminder when you planned the read.');
                } catch (Throwable $e) { error_log('plan email: ' . $e->getMessage()); }
            }
            $db->prepare("UPDATE reading_plans SET reminded_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$p['id']]);
            $n++;
        }
    } catch (Throwable $e) { error_log('plans remind: ' . $e->getMessage()); }
    return $n;
}
