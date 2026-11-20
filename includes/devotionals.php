<?php
/**
 * Devotional reading plans: a few minutes with God each day. Each day has a Bible passage (shown
 * from the site's own Bibles, in the reader's chosen version), a short reflection, a question to
 * think or journal about, and a prayer. Members start a plan and mark days as read; their progress
 * is private and there are no streaks or scores: a missed day simply waits for them.
 *
 * The team writes plans in Admin > Devotionals. A starter plan ("Seven days of hope") is added once
 * as a draft so the page is not empty; it is only shown to members after the team publishes it.
 * Every function returns a value and logs on failure; nothing throws.
 */

function aw_devo_tables(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS devo_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, summary TEXT, palette INTEGER NOT NULL DEFAULT 0,
                   status TEXT NOT NULL DEFAULT 'draft', created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE IF NOT EXISTS devo_days (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INTEGER NOT NULL REFERENCES devo_plans(id) ON DELETE CASCADE,
                   day_no INTEGER NOT NULL, title TEXT, book TEXT, chapter INTEGER, verse_from INTEGER, verse_to INTEGER, reflection TEXT, question TEXT, prayer TEXT, UNIQUE (plan_id, day_no))");
        $db->exec("CREATE TABLE IF NOT EXISTS devo_progress (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, plan_id INTEGER NOT NULL REFERENCES devo_plans(id) ON DELETE CASCADE,
                   started_at DATETIME DEFAULT CURRENT_TIMESTAMP, finished_at DATETIME, PRIMARY KEY (user_id, plan_id))");
        $db->exec("CREATE TABLE IF NOT EXISTS devo_done (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, plan_id INTEGER NOT NULL REFERENCES devo_plans(id) ON DELETE CASCADE,
                   day_no INTEGER NOT NULL, done_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, plan_id, day_no))");
        aw_devo_seed($db);
        return $ok = true;
    } catch (Throwable $e) { error_log('devotional tables: ' . $e->getMessage()); return $ok = false; }
}

const AW_DEVO_PALETTES = [['#5a2a35', '#b86b6e'], ['#2f4a4a', '#5f8f86'], ['#3b2b4f', '#8a6aa8'], ['#6b4310', '#d6a85c'], ['#26324a', '#6a7fb0']];

/** The starter plan, added once as a draft for the team to read, change and publish. */
function aw_devo_seed(PDO $db) {
    if ((string)(function_exists('aw_setting') ? aw_setting('devo_seeded', '') : '') === '1') return;
    if ((int)$db->query("SELECT COUNT(*) FROM devo_plans")->fetchColumn() > 0) { aw_devo_mark_seeded($db); return; }
    $days = [
        ['When you feel alone', 'Psalms', 23, 1, 6, "David wrote these words as someone who knew fields at night, danger, and long walks through dark valleys. He does not say the valley is not there. He says, \"You are with me.\"\n\nToday, whatever valley you are walking through, you are not walking it alone. The Shepherd goes before you, beside you, and behind you.", 'Where do you most need to know that God is with you today?', 'Good Shepherd, thank you that I am never alone. Walk with me through this day, and let me rest in your care. Amen.'],
        ['Hope that does not disappoint', 'Romans', 5, 1, 5, "Paul is honest: life brings suffering. But he sees a path through it: suffering grows perseverance, perseverance grows character, and character grows hope.\n\nThis hope is not wishful thinking. It rests on the love of God, already poured into our hearts.", 'What is one hard thing that has grown something good in you?', 'Father, pour your love into my heart again today. When I am tired, give me hope that does not disappoint. Amen.'],
        ['Strength for the weary', 'Isaiah', 40, 28, 31, "God does not grow tired. That is good news for those of us who do. Those who wait on the Lord, who lean on him rather than on their own strength, are lifted like eagles.\n\nWaiting is not doing nothing. It is trusting that God's timing and strength are better than ours.", 'Where are you trying to carry everything in your own strength?', 'Lord, I am tired. Renew my strength today. Teach me to wait on you and to trust you with what I cannot carry. Amen.'],
        ['Peace in the storm', 'Mark', 4, 35, 41, "The disciples were experienced fishermen, and still they were afraid. Jesus was asleep in the same boat, in the same storm. With a word, he brought calm.\n\nJesus does not always stop our storms at once, but he is always in the boat with us.", 'What storm would you like to bring to Jesus today?', 'Jesus, you are Lord of the wind and the waves. Speak peace over my heart and over the storms I face. Amen.'],
        ['Nothing can separate us', 'Romans', 8, 35, 39, "Paul lists everything that could ever come between us and God: trouble, hardship, danger, death itself. And then he says: none of it can separate us from the love of God in Christ Jesus.\n\nYour worst day cannot reach past his love.", 'Which of these words do you most need to hear today, and why?', 'Thank you, God, that nothing in all creation can separate me from your love. Help me to live today as one who is deeply loved. Amen.'],
        ['New every morning', 'Lamentations', 3, 21, 26, "These words were written in the middle of grief, in a city in ruins. And yet the writer says: his mercies are new every morning; great is your faithfulness.\n\nEach morning is a gift and a fresh start. Yesterday's failures do not have the last word.", 'What would you like to leave behind from yesterday?', 'Faithful God, thank you for mercies that are new this morning. Help me to receive your grace and begin again. Amen.'],
        ['Come to me', 'Matthew', 11, 28, 30, "Jesus' invitation is simple: come. Not when you have everything sorted, not when you feel strong enough. Come as you are, weary and burdened, and he will give you rest.\n\nHis way is gentle. He is humble in heart.", 'What burden would you like to lay down at the end of this week?', 'Jesus, I come to you as I am. Take my burdens and give me your rest. Teach me your gentle way. Amen.'],
    ];
    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO devo_plans (title, summary, palette, status) VALUES (?, ?, 0, 'draft')")
           ->execute(['Seven days of hope', 'A gentle week with God for anyone who feels tired, alone or afraid. Each day: a short passage, a few thoughts, a question and a prayer.']);
        $pid = (int)$db->lastInsertId();
        $st = $db->prepare("INSERT INTO devo_days (plan_id, day_no, title, book, chapter, verse_from, verse_to, reflection, question, prayer) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($days as $i => $d) $st->execute(array_merge([$pid, $i + 1], $d));
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); error_log('devotional seed: ' . $e->getMessage()); return; }
    aw_devo_mark_seeded($db);
}
function aw_devo_mark_seeded(PDO $db) {
    try { $db->prepare("INSERT INTO settings (key, value) VALUES ('devo_seeded', '1') ON CONFLICT(key) DO UPDATE SET value = '1'")->execute(); } catch (Throwable $e) { error_log('devo seeded: ' . $e->getMessage()); }
}

/** Plans with their day count and (when $uid) the member's progress. $all = drafts too (team). */
function aw_devo_plans(PDO $db, $uid = 0, $all = false) {
    if (!aw_devo_tables($db)) return [];
    try {
        $st = $db->prepare("SELECT p.*, (SELECT COUNT(*) FROM devo_days d WHERE d.plan_id = p.id) AS days,
                                   g.started_at, g.finished_at, (SELECT COUNT(*) FROM devo_done x WHERE x.plan_id = p.id AND x.user_id = ?) AS done
                            FROM devo_plans p LEFT JOIN devo_progress g ON g.plan_id = p.id AND g.user_id = ?
                            " . ($all ? '' : "WHERE p.status = 'published'") . " ORDER BY (g.started_at IS NOT NULL AND g.finished_at IS NULL) DESC, p.updated_at DESC");
        $st->execute([(int)$uid, (int)$uid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('devotional plans: ' . $e->getMessage()); return []; }
}

function aw_devo_plan(PDO $db, $id) {
    if (!aw_devo_tables($db)) return null;
    try {
        $st = $db->prepare("SELECT * FROM devo_plans WHERE id = ?");
        $st->execute([(int)$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return null;
        $st = $db->prepare("SELECT * FROM devo_days WHERE plan_id = ? ORDER BY day_no");
        $st->execute([(int)$id]);
        $p['days'] = $st->fetchAll(PDO::FETCH_ASSOC);
        return $p;
    } catch (Throwable $e) { error_log('devotional plan: ' . $e->getMessage()); return null; }
}

/** Days the member has read in a plan: [day_no => true]. */
function aw_devo_done(PDO $db, $uid, $plan_id) {
    try {
        $st = $db->prepare("SELECT day_no FROM devo_done WHERE user_id = ? AND plan_id = ?");
        $st->execute([(int)$uid, (int)$plan_id]);
        return array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
    } catch (Throwable $e) { error_log('devotional done: ' . $e->getMessage()); return []; }
}

/** Mark a day as read (or not). Starts the plan if needed; finishes it when every day is read. */
function aw_devo_mark(PDO $db, $uid, $plan_id, $day_no, $done = true) {
    try {
        $db->prepare("INSERT OR IGNORE INTO devo_progress (user_id, plan_id) VALUES (?, ?)")->execute([(int)$uid, (int)$plan_id]);
        if ($done) $db->prepare("INSERT OR IGNORE INTO devo_done (user_id, plan_id, day_no) VALUES (?, ?, ?)")->execute([(int)$uid, (int)$plan_id, (int)$day_no]);
        else $db->prepare("DELETE FROM devo_done WHERE user_id = ? AND plan_id = ? AND day_no = ?")->execute([(int)$uid, (int)$plan_id, (int)$day_no]);
        $total = (int)$db->query("SELECT COUNT(*) FROM devo_days WHERE plan_id = " . (int)$plan_id)->fetchColumn();
        $read = (int)$db->query("SELECT COUNT(*) FROM devo_done WHERE user_id = " . (int)$uid . " AND plan_id = " . (int)$plan_id)->fetchColumn();
        $db->prepare("UPDATE devo_progress SET finished_at = " . ($total && $read >= $total ? 'COALESCE(finished_at, CURRENT_TIMESTAMP)' : 'NULL') . " WHERE user_id = ? AND plan_id = ?")->execute([(int)$uid, (int)$plan_id]);
        return true;
    } catch (Throwable $e) { error_log('devotional mark: ' . $e->getMessage()); return false; }
}

function aw_devo_start(PDO $db, $uid, $plan_id) {
    try { $db->prepare("INSERT OR IGNORE INTO devo_progress (user_id, plan_id) VALUES (?, ?)")->execute([(int)$uid, (int)$plan_id]); return true; }
    catch (Throwable $e) { error_log('devotional start: ' . $e->getMessage()); return false; }
}

/** Leave a plan: progress is forgotten. */
function aw_devo_leave(PDO $db, $uid, $plan_id) {
    try {
        $db->prepare("DELETE FROM devo_done WHERE user_id = ? AND plan_id = ?")->execute([(int)$uid, (int)$plan_id]);
        $db->prepare("DELETE FROM devo_progress WHERE user_id = ? AND plan_id = ?")->execute([(int)$uid, (int)$plan_id]);
        return true;
    } catch (Throwable $e) { error_log('devotional leave: ' . $e->getMessage()); return false; }
}

/** The first day not yet read (or the last day when all are read). */
function aw_devo_next_day(array $plan, array $done) {
    foreach ($plan['days'] as $d) if (empty($done[(int)$d['day_no']])) return (int)$d['day_no'];
    return $plan['days'] ? (int)end($plan['days'])['day_no'] : 1;
}

/** "Psalms 23:1-6" */
function aw_devo_ref(array $d) {
    if (empty($d['book']) || empty($d['chapter'])) return '';
    $r = $d['book'] . ' ' . (int)$d['chapter'];
    if (!empty($d['verse_from'])) $r .= ':' . (int)$d['verse_from'] . (!empty($d['verse_to']) && (int)$d['verse_to'] > (int)$d['verse_from'] ? '-' . (int)$d['verse_to'] : '');
    return $r;
}

/** The member's active plan for the dashboard: ['plan' => row, 'day' => n] or null. */
function aw_devo_current(PDO $db, $uid) {
    if (!aw_devo_tables($db)) return null;
    try {
        $st = $db->prepare("SELECT p.id, p.title FROM devo_progress g JOIN devo_plans p ON p.id = g.plan_id WHERE g.user_id = ? AND g.finished_at IS NULL AND p.status = 'published' ORDER BY g.started_at DESC LIMIT 1");
        $st->execute([(int)$uid]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return null;
        $plan = aw_devo_plan($db, (int)$p['id']);
        return $plan ? ['plan' => $plan, 'day' => aw_devo_next_day($plan, aw_devo_done($db, $uid, (int)$p['id']))] : null;
    } catch (Throwable $e) { error_log('devotional current: ' . $e->getMessage()); return null; }
}
