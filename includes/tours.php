<?php
/**
 * Guided tours: a card that points at each part of a page and moves from page to page.
 *
 *  member  everyone with an account; starts by itself after sign-up
 *  team    the Care Team; starts by itself when someone accepts an invitation (their own roles' pages)
 *  admin   the Founder's tour of every admin page (owner and stewards); started from the handbook,
 *          the dashboard or "Guided tours"
 *
 * Each person's place in each tour is kept (user_tours): on, off or done, and the step. Tours are
 * turned on, off, restarted or resumed at any time on tour.php ("Guided tours" in the account menu)
 * or from the card itself. aw_tour_footer() (called by the footer) prints what the current page needs:
 * the card's steps for this page, or a small "Continue the tour" pill when the next step is elsewhere.
 * Nothing polls; the card talks to tour.php only when someone presses a button.
 */
require_once __DIR__ . '/tour_content.php';
require_once __DIR__ . '/csrf.php';

const AW_TOURS = [
    'member' => ['title' => 'Welcome tour', 'who' => 'Everyone', 'about' => 'What you can do on AngelWrites: reading, the Bible, prayer, Stories & Help, groups, messages, your library and your account.'],
    'team'   => ['title' => 'Care Team tour', 'who' => 'The Care Team', 'about' => 'Your Team desk, training, your role\'s pages, meetings and check-ins.'],
    'admin'  => ['title' => 'Founder\'s tour', 'who' => 'The founder and stewards', 'about' => 'Every admin page, what it does and how to use it. The same words are in the Founder\'s handbook.'],
];

function aw_tour_table(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS user_tours (user_id INTEGER NOT NULL, tour TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'on', step INTEGER NOT NULL DEFAULT 0,
                   updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, tour))");
        return $ok = true;
    } catch (Throwable $e) { error_log('tour table: ' . $e->getMessage()); return $ok = false; }
}

/** The tours this person may take. */
function aw_tours_for_me() {
    if (empty($_SESSION['user_id'])) return [];
    $out = ['member'];
    if (function_exists('aw_is_staff') && aw_is_staff()) $out[] = 'team';
    if (function_exists('aw_can') && aw_can('manage_members')) $out[] = 'admin';
    return $out;
}

/** Flattened steps of a tour for the signed-in person. Each: page, sel[], click, title, body, chapter. */
function aw_tour_steps($key) {
    $norm = function ($s, $chapter = '') {
        return ['page' => $s['page'], 'sel' => (array)($s['sel'] ?? []), 'click' => (string)($s['click'] ?? ''), 'title' => $s['title'], 'body' => $s['body'], 'chapter' => $chapter];
    };
    $out = [];
    if ($key === 'member') foreach (AW_TOUR_MEMBER as $s) $out[] = $norm($s);
    if ($key === 'team') {
        foreach (AW_TOUR_TEAM_START as $s) $out[] = $norm($s);
        $roles = [];
        if (function_exists('aw_my_role') && aw_my_role() === 'moderator') $roles[] = 'moderator';
        if (function_exists('aw_user_areas') && !empty($_SESSION['user_id'])) foreach (aw_user_areas((int)$_SESSION['user_id']) as $a) $roles[] = $a;
        if (function_exists('aw_can') && aw_can('manage_members')) $roles = ['moderator']; // stewards see the moderator's pages as an example
        $seen = [];
        foreach (array_unique($roles) as $r) foreach (AW_TOUR_TEAM_ROLE[$r] ?? [] as $s) { if (isset($seen[$s['page']])) continue; $seen[$s['page']] = true; $out[] = $norm($s); }
        foreach (AW_TOUR_TEAM_END as $s) $out[] = $norm($s);
    }
    if ($key === 'admin') foreach (AW_TOUR_ADMIN as $ck => $c) foreach ($c['steps'] as $st) {
        $out[] = $norm(['page' => $c['page'], 'sel' => $st[0], 'title' => $st[1], 'body' => $st[2], 'click' => $st[3] ?? ''], $ck);
    }
    return $out;
}

/** The first step of a chapter of the Founder's tour (for "Show me" in the handbook). */
function aw_tour_chapter_step($chapter) {
    foreach (aw_tour_steps('admin') as $i => $s) if ($s['chapter'] === $chapter) return $i;
    return 0;
}

function aw_tour_get(PDO $db, $uid, $key) {
    if (!aw_tour_table($db)) return null;
    try { $st = $db->prepare("SELECT status, step FROM user_tours WHERE user_id = ? AND tour = ?"); $st->execute([(int)$uid, (string)$key]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
    catch (Throwable $e) { error_log('aw_tour_get: ' . $e->getMessage()); return null; }
}

/** Set a tour's status (on/off/done) and step. Turning one on turns the others off, so only one runs. */
function aw_tour_set(PDO $db, $uid, $key, $status, $step = null) {
    if (!isset(AW_TOURS[$key]) || !in_array($status, ['on', 'off', 'done'], true) || !aw_tour_table($db)) return false;
    try {
        if ($status === 'on') $db->prepare("UPDATE user_tours SET status = 'off', updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND tour <> ? AND status = 'on'")->execute([(int)$uid, $key]);
        $cur = aw_tour_get($db, $uid, $key);
        $step = $step === null ? (int)($cur['step'] ?? 0) : max(0, (int)$step);
        $db->prepare("INSERT OR REPLACE INTO user_tours (user_id, tour, status, step, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)")->execute([(int)$uid, $key, $status, $step]);
        return true;
    } catch (Throwable $e) { error_log('aw_tour_set: ' . $e->getMessage()); return false; }
}

/** The tour that is on for this person: [key, step] or null. */
function aw_tour_active(PDO $db, $uid) {
    if (!aw_tour_table($db)) return null;
    try {
        $st = $db->prepare("SELECT tour, step FROM user_tours WHERE user_id = ? AND status = 'on' ORDER BY updated_at DESC LIMIT 1");
        $st->execute([(int)$uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return ($r && in_array($r['tour'], aw_tours_for_me(), true)) ? [$r['tour'], (int)$r['step']] : null;
    } catch (Throwable $e) { error_log('aw_tour_active: ' . $e->getMessage()); return null; }
}

/** This page's path inside the site, e.g. "admin/manage_users.php". */
function aw_tour_here() {
    $p = (string)parse_url((string)($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH);
    $base = (string)parse_url(SITE_URL, PHP_URL_PATH);
    if ($base !== '' && $base !== '/' && strpos($p, $base) === 0) $p = substr($p, strlen($base));
    return ltrim($p, '/');
}

function aw_tour_url(array $step) { return rtrim(SITE_URL, '/') . '/' . $step['page']; }

/** What the footer prints: the card for this page, or the "continue" pill. */
function aw_tour_footer() {
    $db = $GLOBALS['db'] ?? null;
    if (!$db instanceof PDO || empty($_SESSION['user_id'])) return '';
    $act = aw_tour_active($db, (int)$_SESSION['user_id']);
    if (!$act) return '';
    [$key, $step] = $act;
    $steps = aw_tour_steps($key);
    $n = count($steps);
    if ($n === 0) return '';
    if ($step >= $n) { aw_tour_set($db, (int)$_SESSION['user_id'], $key, 'done', $n - 1); return ''; }
    $here = aw_tour_here();
    if (function_exists('generate_csrf_token')) $token = generate_csrf_token();
    else { if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); $token = $_SESSION['csrf_token']; }
    $cfg = ['tour' => $key, 'title' => AW_TOURS[$key]['title'], 'total' => $n, 'current' => $step, 'endpoint' => SITE_URL . '/tour.php', 'token' => $token,
            'manage' => SITE_URL . '/tour.php', 'here' => $steps[$step]['page'] === $here];
    if ($cfg['here']) {
        // every step on this page (so Back and Next work without reloading), with the pages before and after
        $first = $step; while ($first > 0 && $steps[$first - 1]['page'] === $here) $first--;
        $last = $step; while ($last < $n - 1 && $steps[$last + 1]['page'] === $here) $last++;
        $cfg['steps'] = [];
        for ($i = $first; $i <= $last; $i++) $cfg['steps'][] = ['i' => $i, 'sel' => $steps[$i]['sel'], 'click' => $steps[$i]['click'], 'title' => $steps[$i]['title'], 'body' => $steps[$i]['body']];
        $cfg['prevUrl'] = $first > 0 ? aw_tour_url($steps[$first - 1]) : '';
        $cfg['nextUrl'] = $last < $n - 1 ? aw_tour_url($steps[$last + 1]) : '';
        $cfg['chapter'] = $steps[$step]['chapter'] !== '' ? (AW_TOUR_ADMIN[$steps[$step]['chapter']]['title'] ?? '') : '';
    } else {
        $cfg['resumeUrl'] = aw_tour_url($steps[$step]);
        $cfg['resumeTitle'] = $steps[$step]['title'];
    }
    return '<script>window.AW_TOUR = ' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . ';</script>'
         . '<script src="' . SITE_URL . '/assets/js/aw-tour.js?v=2" defer></script>';
}
