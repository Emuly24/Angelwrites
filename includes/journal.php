<?php
/**
 * Private journal: a member's own space to write prayers, thoughts and what they are learning.
 *
 * Only the member reads it. There is no team page for journals, and what is written is encrypted in
 * the database (libsodium secretbox, a key per member derived from a site key that is NOT in the
 * database: JOURNAL_KEY in .env, or a key file made once in data/), so a database copy or backup on
 * its own does not reveal anyone's journal. Deleting the account deletes the journal (foreign key).
 *
 * Every function returns a value and logs on failure; nothing throws.
 */

const AW_JOURNAL_MOODS = [
    'grateful' => ['Grateful', '🙏'], 'peaceful' => ['Peaceful', '🕊️'], 'hopeful' => ['Hopeful', '🌱'], 'joyful' => ['Joyful', '☀️'],
    'tired' => ['Tired', '🌙'], 'anxious' => ['Anxious', '🌧️'], 'sad' => ['Sad', '💧'], 'struggling' => ['Struggling', '⛰️'],
];
const AW_JOURNAL_PROMPTS = [
    'What are three things you are thankful for today, however small?',
    'Where did you notice God\'s kindness this week?',
    'What is weighing on your heart? Write it down and give it to Him.',
    'Which verse or line has stayed with you lately, and why?',
    'What would you like to say to your younger self?',
    'Who has been a blessing to you recently? What would you like to tell them?',
    'What are you learning in this season?',
    'Write a short prayer for someone you love.',
    'What does rest look like for you right now?',
    'What fear would you like to let go of?',
    'Describe a moment today when you felt at peace.',
    'What promise of God do you want to hold on to this week?',
    'What is one small step of faith you could take tomorrow?',
    'What did today teach you about yourself?',
];
const AW_JOURNAL_MAX = 20000; // characters per entry

function aw_journal_table(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS journal_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            title_enc TEXT,
            body_enc TEXT NOT NULL,
            mood TEXT,
            verse_enc TEXT,
            entry_date TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_journal_user ON journal_entries(user_id, entry_date)");
        return $ok = true;
    } catch (Throwable $e) { error_log('journal table: ' . $e->getMessage()); return $ok = false; }
}

/** The member's own key (32 bytes), derived from the site key; '' when encryption is unavailable. */
function aw_journal_key($uid) {
    static $site = null;
    if (!function_exists('sodium_crypto_secretbox')) return '';
    if ($site === null) {
        $env = trim((string)aw_env('JOURNAL_KEY'));
        if ($env !== '') $site = sodium_crypto_generichash($env, '', 32);
        else {
            $file = dirname(__DIR__) . '/data/journal.key';
            if (!is_file($file)) {
                if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
                @file_put_contents($file, base64_encode(random_bytes(32)), LOCK_EX);
                @chmod($file, 0600);
            }
            $raw = base64_decode(trim((string)@file_get_contents($file)), true);
            $site = ($raw !== false && strlen($raw) === 32) ? $raw : '';
        }
    }
    if ($site === '') return '';
    return sodium_crypto_generichash('journal:' . (int)$uid, $site, 32);
}

function aw_journal_seal($uid, $text) {
    $text = (string)$text;
    if ($text === '') return '';
    $k = aw_journal_key($uid);
    if ($k === '') return '';
    $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'v1:' . base64_encode($n . sodium_crypto_secretbox($text, $n, $k));
}

function aw_journal_open($uid, $sealed) {
    $sealed = (string)$sealed;
    if ($sealed === '') return '';
    if (strpos($sealed, 'v1:') !== 0) return '';
    $raw = base64_decode(substr($sealed, 3), true);
    $k = aw_journal_key($uid);
    if ($raw === false || $k === '' || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $k);
    return $plain === false ? '' : $plain;
}

/** All of a member's entries, opened, newest first. */
function aw_journal_list(PDO $db, $uid, $q = '') {
    if (!aw_journal_table($db)) return [];
    try {
        $st = $db->prepare("SELECT * FROM journal_entries WHERE user_id = ? ORDER BY entry_date DESC, id DESC LIMIT 1000");
        $st->execute([(int)$uid]);
        $out = [];
        $q = mb_strtolower(trim((string)$q));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $e = aw_journal_row($uid, $r);
            if ($q !== '' && mb_strpos(mb_strtolower($e['title'] . ' ' . $e['body'] . ' ' . $e['verse']), $q) === false) continue;
            $out[] = $e;
        }
        return $out;
    } catch (Throwable $e) { error_log('journal list: ' . $e->getMessage()); return []; }
}

function aw_journal_row($uid, array $r) {
    return ['id' => (int)$r['id'], 'title' => aw_journal_open($uid, $r['title_enc']), 'body' => aw_journal_open($uid, $r['body_enc']),
            'verse' => aw_journal_open($uid, $r['verse_enc']), 'mood' => (string)$r['mood'], 'entry_date' => (string)$r['entry_date'],
            'created_at' => (string)$r['created_at'], 'updated_at' => (string)$r['updated_at']];
}

function aw_journal_get(PDO $db, $uid, $id) {
    if (!aw_journal_table($db)) return null;
    try {
        $st = $db->prepare("SELECT * FROM journal_entries WHERE id = ? AND user_id = ?");
        $st->execute([(int)$id, (int)$uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? aw_journal_row($uid, $r) : null;
    } catch (Throwable $e) { error_log('journal get: ' . $e->getMessage()); return null; }
}

/** Save (new when $id = 0). Returns ['ok' => bool, 'id' => n, 'error' => ...]. */
function aw_journal_save(PDO $db, $uid, $id, array $in) {
    if (!aw_journal_table($db)) return ['ok' => false, 'error' => 'Your journal is not available just now.'];
    if (aw_journal_key($uid) === '') return ['ok' => false, 'error' => 'Your journal cannot be kept private on this server, so nothing was saved.'];
    $title = trim(mb_substr(strip_tags((string)($in['title'] ?? '')), 0, 160));
    $body = trim(str_replace("\r\n", "\n", strip_tags((string)($in['body'] ?? ''))));
    $verse = trim(mb_substr(strip_tags((string)($in['verse'] ?? '')), 0, 200));
    $mood = isset(AW_JOURNAL_MOODS[$in['mood'] ?? '']) ? (string)$in['mood'] : '';
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['entry_date'] ?? '')) ? (string)$in['entry_date'] : date('Y-m-d');
    if ($date > date('Y-m-d', strtotime('+1 day'))) $date = date('Y-m-d');
    if ($body === '') return ['ok' => false, 'error' => 'Write something first.'];
    if (mb_strlen($body) > AW_JOURNAL_MAX) return ['ok' => false, 'error' => 'That entry is very long (more than 20,000 characters). Please split it in two.'];
    try {
        $vals = [aw_journal_seal($uid, $title), aw_journal_seal($uid, $body), $mood, aw_journal_seal($uid, $verse), $date];
        if ($id) {
            $st = $db->prepare("UPDATE journal_entries SET title_enc = ?, body_enc = ?, mood = ?, verse_enc = ?, entry_date = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?");
            $st->execute(array_merge($vals, [(int)$id, (int)$uid]));
            if (!$st->rowCount()) return ['ok' => false, 'error' => 'That entry was not found.'];
            return ['ok' => true, 'id' => (int)$id];
        }
        $db->prepare("INSERT INTO journal_entries (user_id, title_enc, body_enc, mood, verse_enc, entry_date) VALUES (?, ?, ?, ?, ?, ?)")->execute(array_merge([(int)$uid], $vals));
        return ['ok' => true, 'id' => (int)$db->lastInsertId()];
    } catch (Throwable $e) {
        error_log('journal save: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Your entry could not be saved just now. Please try again.'];
    }
}

function aw_journal_delete(PDO $db, $uid, $id) {
    if (!aw_journal_table($db)) return false;
    try { $st = $db->prepare("DELETE FROM journal_entries WHERE id = ? AND user_id = ?"); $st->execute([(int)$id, (int)$uid]); return $st->rowCount() > 0; }
    catch (Throwable $e) { error_log('journal delete: ' . $e->getMessage()); return false; }
}

/** Today's prompt (the same all day, different each day). */
function aw_journal_prompt() {
    return AW_JOURNAL_PROMPTS[(int)date('z') % count(AW_JOURNAL_PROMPTS)];
}
