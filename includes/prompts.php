<?php
/**
 * Writing prompts with gentle feedback.
 *
 * The team sets a prompt for a week (or any dates). Members answer it with a short piece; other
 * members may leave gentle feedback in two parts, "What touched me" and "What I'd love more of".
 * Feedback goes to the writer only (no public pile-on), and there are no likes, scores or badges.
 * Pieces and feedback can be reported (includes/moderation.php).
 * Every function returns a value and logs on failure; nothing throws.
 */

const AW_PROMPT_MAX = 4000; // characters per piece

function aw_prompt_tables(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS writing_prompts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT, verse TEXT,
                   starts_on TEXT NOT NULL, ends_on TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'draft', created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE IF NOT EXISTS prompt_responses (id INTEGER PRIMARY KEY AUTOINCREMENT, prompt_id INTEGER NOT NULL REFERENCES writing_prompts(id) ON DELETE CASCADE,
                   user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, title TEXT, body TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'published',
                   created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE IF NOT EXISTS prompt_feedback (id INTEGER PRIMARY KEY AUTOINCREMENT, response_id INTEGER NOT NULL REFERENCES prompt_responses(id) ON DELETE CASCADE,
                   user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, touched TEXT, more TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE (response_id, user_id))");
        aw_prompt_seed($db);
        return $ok = true;
    } catch (Throwable $e) { error_log('prompt tables: ' . $e->getMessage()); return $ok = false; }
}

/** Four starter prompts, added once as drafts for the team to schedule. */
function aw_prompt_seed(PDO $db) {
    if ((string)(function_exists('aw_setting') ? aw_setting('prompts_seeded', '') : '') === '1') return;
    try {
        if ((int)$db->query("SELECT COUNT(*) FROM writing_prompts")->fetchColumn() === 0) {
            $st = $db->prepare("INSERT INTO writing_prompts (title, body, verse, starts_on, ends_on, status) VALUES (?, ?, ?, ?, ?, 'draft')");
            $mon = date('Y-m-d', strtotime('monday next week'));
            foreach ([
                ['Peace in an unexpected place', 'Write about a moment when you felt God\'s peace where you did not expect it: a hospital corridor, a bus, a kitchen at midnight. What did you notice?', 'John 14:27'],
                ['A letter to my younger self', 'Write a short letter to yourself at a hard moment in your past. What would you want that person to know about God, and about who they would become?', 'Jeremiah 29:11'],
                ['The hands that held me', 'Who carried you when you could not carry yourself? Write a poem or a few paragraphs about them.', 'Galatians 6:2'],
                ['Morning', 'Write about a morning: a real one, or one you long for. Let the light, the sounds and the first prayer of the day find their way into your words.', 'Lamentations 3:22-23'],
            ] as $i => $p) $st->execute([$p[0], $p[1], $p[2], date('Y-m-d', strtotime($mon . ' +' . (7 * $i) . ' days')), date('Y-m-d', strtotime($mon . ' +' . (7 * $i + 6) . ' days'))]);
        }
        $db->prepare("INSERT INTO settings (key, value) VALUES ('prompts_seeded', '1') ON CONFLICT(key) DO UPDATE SET value = '1'")->execute();
    } catch (Throwable $e) { error_log('prompt seed: ' . $e->getMessage()); }
}

/** The prompt open today (the most recent one if several), or null. */
function aw_prompt_current(PDO $db) {
    if (!aw_prompt_tables($db)) return null;
    try {
        $st = $db->query("SELECT * FROM writing_prompts WHERE status = 'published' AND starts_on <= date('now') AND ends_on >= date('now') ORDER BY starts_on DESC LIMIT 1");
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('prompt current: ' . $e->getMessage()); return null; }
}

function aw_prompt_get(PDO $db, $id) {
    if (!aw_prompt_tables($db)) return null;
    try { $st = $db->prepare("SELECT * FROM writing_prompts WHERE id = ?"); $st->execute([(int)$id]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
    catch (Throwable $e) { error_log('prompt get: ' . $e->getMessage()); return null; }
}

/** Earlier published prompts with how many answered them. */
function aw_prompt_past(PDO $db, $limit = 12) {
    if (!aw_prompt_tables($db)) return [];
    try {
        return $db->query("SELECT p.*, (SELECT COUNT(*) FROM prompt_responses r WHERE r.prompt_id = p.id AND r.status = 'published') AS n
                           FROM writing_prompts p WHERE p.status = 'published' AND p.starts_on <= date('now') ORDER BY p.starts_on DESC LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('prompt past: ' . $e->getMessage()); return []; }
}

/** The pieces written for a prompt, newest first, with whether the viewer already left feedback. */
function aw_prompt_responses(PDO $db, $prompt_id, $viewer) {
    try {
        $st = $db->prepare("SELECT r.*, u.name, u.first_name, u.last_name, u.username,
                                   (SELECT COUNT(*) FROM prompt_feedback f WHERE f.response_id = r.id) AS fb,
                                   (SELECT 1 FROM prompt_feedback f WHERE f.response_id = r.id AND f.user_id = ?) AS i_gave
                            FROM prompt_responses r JOIN users u ON u.id = r.user_id WHERE r.prompt_id = ? AND r.status = 'published' ORDER BY r.created_at DESC");
        $st->execute([(int)$viewer, (int)$prompt_id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('prompt responses: ' . $e->getMessage()); return []; }
}

/** Feedback on the writer's own piece (the writer is the only one who reads it). */
function aw_prompt_feedback_for(PDO $db, $response_id, $writer) {
    try {
        $st = $db->prepare("SELECT f.*, u.name, u.first_name, u.last_name, u.username FROM prompt_feedback f JOIN prompt_responses r ON r.id = f.response_id
                            JOIN users u ON u.id = f.user_id WHERE f.response_id = ? AND r.user_id = ? ORDER BY f.created_at");
        $st->execute([(int)$response_id, (int)$writer]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('prompt feedback: ' . $e->getMessage()); return []; }
}

function aw_prompt_write(PDO $db, $uid, $prompt_id, array $in) {
    $p = aw_prompt_get($db, $prompt_id);
    if (!$p || $p['status'] !== 'published' || $p['starts_on'] > date('Y-m-d')) return ['ok' => false, 'error' => 'This prompt is not open.'];
    $title = trim(mb_substr(strip_tags((string)($in['title'] ?? '')), 0, 120));
    $body = trim(str_replace("\r\n", "\n", strip_tags((string)($in['body'] ?? ''))));
    if (mb_strlen($body) < 20) return ['ok' => false, 'error' => 'Write a little more first (a few lines at least).'];
    if (mb_strlen($body) > AW_PROMPT_MAX) return ['ok' => false, 'error' => 'Please keep it under 4,000 characters.'];
    try {
        $st = $db->prepare("SELECT id FROM prompt_responses WHERE prompt_id = ? AND user_id = ? AND status <> 'removed'");
        $st->execute([(int)$prompt_id, (int)$uid]);
        if ($id = (int)$st->fetchColumn()) {
            $db->prepare("UPDATE prompt_responses SET title = ?, body = ?, status = 'published', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$title, $body, $id]);
            return ['ok' => true, 'id' => $id, 'updated' => true];
        }
        $db->prepare("INSERT INTO prompt_responses (prompt_id, user_id, title, body) VALUES (?, ?, ?, ?)")->execute([(int)$prompt_id, (int)$uid, $title, $body]);
        return ['ok' => true, 'id' => (int)$db->lastInsertId()];
    } catch (Throwable $e) { error_log('prompt write: ' . $e->getMessage()); return ['ok' => false, 'error' => 'Your piece could not be saved just now.']; }
}

function aw_prompt_feedback(PDO $db, $uid, $response_id, $touched, $more) {
    $touched = trim(mb_substr(strip_tags((string)$touched), 0, 600));
    $more = trim(mb_substr(strip_tags((string)$more), 0, 600));
    if ($touched === '') return ['ok' => false, 'error' => 'Start with what touched you: that part is the heart of gentle feedback.'];
    try {
        $st = $db->prepare("SELECT r.*, p.title AS ptitle FROM prompt_responses r JOIN writing_prompts p ON p.id = r.prompt_id WHERE r.id = ? AND r.status = 'published'");
        $st->execute([(int)$response_id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['ok' => false, 'error' => 'That piece is no longer here.'];
        if ((int)$r['user_id'] === (int)$uid) return ['ok' => false, 'error' => 'This is your own piece.'];
        $db->prepare("INSERT INTO prompt_feedback (response_id, user_id, touched, more) VALUES (?, ?, ?, ?) ON CONFLICT(response_id, user_id) DO UPDATE SET touched = excluded.touched, more = excluded.more")
           ->execute([(int)$response_id, (int)$uid, $touched, $more]);
        if (function_exists('awNotify')) awNotify($db, (int)$r['user_id'], 'prompt_feedback', 'Gentle feedback on your writing', 'Someone shared what touched them in your piece for "' . $r['ptitle'] . '".', SITE_URL . '/prompts.php?p=' . (int)$r['prompt_id'] . '#r' . (int)$r['id'], ['email' => false]);
        return ['ok' => true];
    } catch (Throwable $e) { error_log('prompt feedback save: ' . $e->getMessage()); return ['ok' => false, 'error' => 'Your feedback could not be saved just now.']; }
}

function aw_prompt_delete_own(PDO $db, $uid, $response_id) {
    try { $st = $db->prepare("DELETE FROM prompt_responses WHERE id = ? AND user_id = ?"); $st->execute([(int)$response_id, (int)$uid]); return $st->rowCount() > 0; }
    catch (Throwable $e) { error_log('prompt delete: ' . $e->getMessage()); return false; }
}
