<?php
/**
 * Mentoring: one-to-one support between members, arranged and watched over by the team.
 *
 *  1. A member asks for a mentor: the area, what they hope for, how often, an optional preference.
 *  2. Members can offer to mentor (areas, a few words about themselves, how many people they can walk
 *     with); the team approves mentors before they can be matched.
 *  3. The team proposes a mentor for a request; the mentor accepts or declines.
 *  4. On acceptance the site opens a conversation between the two in AngelWrites messages (never
 *     email or phone numbers), so it stays private, reportable and within the community guidelines.
 *  5. Either of them can end it at any time; the team sees the pairs and any concern raised.
 * Every function returns a value and logs on failure; nothing throws.
 */

const AW_MENTOR_AREAS = ['faith' => 'Faith and prayer', 'writing' => 'Writing and creativity', 'life' => 'Life and relationships', 'study' => 'Study and work',
    'healing' => 'Grief and healing', 'reading' => 'Reading the Bible', 'other' => 'Something else'];
const AW_MENTOR_FREQ = ['weekly' => 'Once a week', 'fortnight' => 'Every two weeks', 'monthly' => 'Once a month', 'flexible' => 'When we can'];

function aw_mentor_tables(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS mentors (user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE, areas TEXT NOT NULL, bio TEXT,
                   max_mentees INTEGER NOT NULL DEFAULT 2, status TEXT NOT NULL DEFAULT 'pending', reviewed_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE IF NOT EXISTS mentor_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                   area TEXT NOT NULL, hopes TEXT NOT NULL, frequency TEXT, preference TEXT, status TEXT NOT NULL DEFAULT 'waiting',
                   mentor_id INTEGER REFERENCES users(id) ON DELETE SET NULL, thread_id INTEGER, proposed_at DATETIME, started_at DATETIME, closed_at DATETIME, close_note TEXT,
                   created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        return $ok = true;
    } catch (Throwable $e) { error_log('mentoring tables: ' . $e->getMessage()); return $ok = false; }
}

function aw_mentor_get(PDO $db, $uid) {
    if (!aw_mentor_tables($db)) return null;
    try { $st = $db->prepare("SELECT * FROM mentors WHERE user_id = ?"); $st->execute([(int)$uid]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
    catch (Throwable $e) { error_log('mentor get: ' . $e->getMessage()); return null; }
}

/** The member's open request (waiting, proposed or active), or null. */
function aw_mentor_my_request(PDO $db, $uid) {
    if (!aw_mentor_tables($db)) return null;
    try {
        $st = $db->prepare("SELECT r.*, u.name, u.first_name, u.last_name, u.username FROM mentor_requests r LEFT JOIN users u ON u.id = r.mentor_id
                            WHERE r.user_id = ? AND r.status IN ('waiting', 'proposed', 'active') ORDER BY r.id DESC LIMIT 1");
        $st->execute([(int)$uid]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('mentor my request: ' . $e->getMessage()); return null; }
}

function aw_mentor_request(PDO $db, $uid, array $in) {
    if (!aw_mentor_tables($db)) return ['ok' => false, 'error' => 'Mentoring is not available just now.'];
    if (aw_mentor_my_request($db, $uid)) return ['ok' => false, 'error' => 'You already have a mentoring request. You can end it before asking again.'];
    $area = isset(AW_MENTOR_AREAS[$in['area'] ?? '']) ? (string)$in['area'] : '';
    $hopes = trim(mb_substr(strip_tags(str_replace("\r\n", "\n", (string)($in['hopes'] ?? ''))), 0, 1500));
    $freq = isset(AW_MENTOR_FREQ[$in['frequency'] ?? '']) ? (string)$in['frequency'] : 'flexible';
    $pref = trim(mb_substr(strip_tags((string)($in['preference'] ?? '')), 0, 200));
    if ($area === '') return ['ok' => false, 'error' => 'Choose what you would like support with.'];
    if (mb_strlen($hopes) < 20) return ['ok' => false, 'error' => 'Tell us a little more about what you hope for (a few sentences help us find the right person).'];
    if (empty($in['adult'])) return ['ok' => false, 'error' => 'Mentoring is for adults: please confirm you are 18 or older.'];
    try {
        $db->prepare("INSERT INTO mentor_requests (user_id, area, hopes, frequency, preference) VALUES (?, ?, ?, ?, ?)")->execute([(int)$uid, $area, $hopes, $freq, $pref]);
        $id = (int)$db->lastInsertId();
        aw_mentor_tell_team($db, 'A member asked for a mentor', AW_MENTOR_AREAS[$area]);
        if (function_exists('aw_audit')) aw_audit('mentor_request', 'mentoring', $id, $area);
        return ['ok' => true];
    } catch (Throwable $e) { error_log('mentor request: ' . $e->getMessage()); return ['ok' => false, 'error' => 'Your request could not be saved just now.']; }
}

function aw_mentor_offer(PDO $db, $uid, array $in) {
    if (!aw_mentor_tables($db)) return ['ok' => false, 'error' => 'Mentoring is not available just now.'];
    $areas = array_values(array_intersect(array_keys(AW_MENTOR_AREAS), (array)($in['areas'] ?? [])));
    $bio = trim(mb_substr(strip_tags(str_replace("\r\n", "\n", (string)($in['bio'] ?? ''))), 0, 1200));
    $max = max(1, min(5, (int)($in['max_mentees'] ?? 2)));
    if (!$areas) return ['ok' => false, 'error' => 'Choose at least one area you could help with.'];
    if (mb_strlen($bio) < 30) return ['ok' => false, 'error' => 'Please write a few sentences about yourself and your walk with God.'];
    if (empty($in['adult']) || empty($in['promise'])) return ['ok' => false, 'error' => 'Please confirm you are 18 or older and agree to the mentoring promises.'];
    try {
        $old = aw_mentor_get($db, $uid);
        if ($old) $db->prepare("UPDATE mentors SET areas = ?, bio = ?, max_mentees = ?, status = CASE WHEN status = 'declined' THEN 'pending' ELSE status END, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?")->execute([implode(',', $areas), $bio, $max, (int)$uid]);
        else { $db->prepare("INSERT INTO mentors (user_id, areas, bio, max_mentees) VALUES (?, ?, ?, ?)")->execute([(int)$uid, implode(',', $areas), $bio, $max]); aw_mentor_tell_team($db, 'Someone offered to mentor', 'Review their offer in Mentoring.'); }
        return ['ok' => true];
    } catch (Throwable $e) { error_log('mentor offer: ' . $e->getMessage()); return ['ok' => false, 'error' => 'Your offer could not be saved just now.']; }
}

/** Approved mentors with their current number of mentees. */
function aw_mentor_list(PDO $db, $status = 'approved') {
    if (!aw_mentor_tables($db)) return [];
    try {
        $st = $db->prepare("SELECT m.*, u.name, u.first_name, u.last_name, u.username, u.gender, u.country,
                                   (SELECT COUNT(*) FROM mentor_requests r WHERE r.mentor_id = m.user_id AND r.status IN ('proposed', 'active')) AS load
                            FROM mentors m JOIN users u ON u.id = m.user_id WHERE m.status = ? ORDER BY m.created_at");
        $st->execute([$status]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('mentor list: ' . $e->getMessage()); return []; }
}

/** The team proposes a mentor for a waiting request. */
function aw_mentor_propose(PDO $db, $request_id, $mentor_id) {
    try {
        $m = aw_mentor_get($db, $mentor_id);
        if (!$m || $m['status'] !== 'approved') return ['ok' => false, 'error' => 'Choose an approved mentor.'];
        $st = $db->prepare("SELECT * FROM mentor_requests WHERE id = ? AND status IN ('waiting', 'proposed')");
        $st->execute([(int)$request_id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['ok' => false, 'error' => 'That request is no longer waiting.'];
        if ((int)$r['user_id'] === (int)$mentor_id) return ['ok' => false, 'error' => 'A member cannot mentor themselves.'];
        $db->prepare("UPDATE mentor_requests SET status = 'proposed', mentor_id = ?, proposed_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$mentor_id, (int)$request_id]);
        if (function_exists('awNotify')) awNotify($db, (int)$mentor_id, 'mentor_proposed', 'Would you mentor someone?', 'The team thinks you could walk alongside a member (' . (AW_MENTOR_AREAS[$r['area']] ?? 'mentoring') . '). Please say yes or no.', SITE_URL . '/mentoring.php', ['email' => true, 'actor' => 0]);
        if (function_exists('aw_audit')) aw_audit('mentor_propose', 'mentoring', (int)$request_id, 'mentor ' . (int)$mentor_id);
        return ['ok' => true];
    } catch (Throwable $e) { error_log('mentor propose: ' . $e->getMessage()); return ['ok' => false, 'error' => 'That did not work.']; }
}

/** The mentor answers a proposal. Accepting opens their conversation in AngelWrites messages. */
function aw_mentor_answer(PDO $db, $mentor_id, $request_id, $yes) {
    try {
        $st = $db->prepare("SELECT * FROM mentor_requests WHERE id = ? AND mentor_id = ? AND status = 'proposed'");
        $st->execute([(int)$request_id, (int)$mentor_id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['ok' => false, 'error' => 'That proposal is no longer open.'];
        if (!$yes) {
            $db->prepare("UPDATE mentor_requests SET status = 'waiting', mentor_id = NULL, proposed_at = NULL WHERE id = ?")->execute([(int)$request_id]);
            aw_mentor_tell_team($db, 'A mentor said no to a proposal', 'Please choose someone else for this request.');
            return ['ok' => true, 'message' => 'Thank you for letting us know. The team will find someone else.'];
        }
        $tid = aw_mentor_open_thread($db, (int)$mentor_id, (int)$r['user_id'], $r);
        $db->prepare("UPDATE mentor_requests SET status = 'active', started_at = CURRENT_TIMESTAMP, thread_id = ? WHERE id = ?")->execute([$tid ?: null, (int)$request_id]);
        if (function_exists('awNotify')) awNotify($db, (int)$r['user_id'], 'mentor_matched', 'You have a mentor', 'Someone from the AngelWrites family will walk alongside you. Say hello in Messages.', SITE_URL . '/messages.php' . ($tid ? '?t=' . $tid : ''), ['email' => true, 'actor' => 0]);
        if (function_exists('aw_audit')) aw_audit('mentor_accept', 'mentoring', (int)$request_id, '');
        return ['ok' => true, 'message' => 'Thank you! A conversation with them is open in your Messages.', 'thread' => $tid];
    } catch (Throwable $e) { error_log('mentor answer: ' . $e->getMessage()); return ['ok' => false, 'error' => 'That did not work.']; }
}

/** A conversation both have agreed to (the member asked, the mentor accepted), with a first hello. */
function aw_mentor_open_thread(PDO $db, $mentor, $mentee, array $r) {
    if (!function_exists('aw_msg_tables') || !aw_msg_tables($db)) return 0;
    try {
        $ex = aw_msg_direct_thread($db, $mentor, $mentee);
        if ($ex) { $db->prepare("UPDATE msg_participants SET status = 'active' WHERE thread_id = ?")->execute([(int)$ex['id']]); $tid = (int)$ex['id']; }
        else {
            $db->beginTransaction();
            $db->prepare("INSERT INTO msg_threads (kind, created_by) VALUES ('direct', ?)")->execute([(int)$mentor]);
            $tid = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO msg_participants (thread_id, user_id, status) VALUES (?, ?, 'active'), (?, ?, 'active')")->execute([$tid, (int)$mentor, $tid, (int)$mentee]);
            $db->commit();
        }
        $hello = "Hello! The AngelWrites team has introduced us for mentoring (" . (AW_MENTOR_AREAS[$r['area']] ?? 'mentoring') . "). I am glad to walk alongside you. "
               . "Tell me a little about yourself whenever you are ready, and let us agree how often to talk (" . strtolower(AW_MENTOR_FREQ[$r['frequency']] ?? 'when we can') . " was suggested). "
               . "We keep our conversations here in AngelWrites messages.";
        $db->prepare("INSERT INTO messages (thread_id, sender_id, body) VALUES (?, ?, ?)")->execute([$tid, (int)$mentor, $hello]);
        $db->prepare("UPDATE msg_threads SET last_message_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$tid]);
        return $tid;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); error_log('mentor thread: ' . $e->getMessage()); return 0; }
}

/** End a mentoring (the member, the mentor or the team). */
function aw_mentor_close(PDO $db, $request_id, $by_uid, $note = '', $team = false) {
    try {
        $st = $db->prepare("SELECT * FROM mentor_requests WHERE id = ? AND status IN ('waiting', 'proposed', 'active')");
        $st->execute([(int)$request_id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || (!$team && (int)$r['user_id'] !== (int)$by_uid && (int)$r['mentor_id'] !== (int)$by_uid)) return false;
        $db->prepare("UPDATE mentor_requests SET status = ?, closed_at = CURRENT_TIMESTAMP, close_note = ? WHERE id = ?")
           ->execute([$r['status'] === 'active' ? 'closed' : 'withdrawn', mb_substr(trim(strip_tags((string)$note)), 0, 600), (int)$request_id]);
        $other = (int)$r['user_id'] === (int)$by_uid ? (int)$r['mentor_id'] : (int)$r['user_id'];
        if ($r['status'] === 'active' && $other && function_exists('awNotify')) awNotify($db, $other, 'mentor_closed', 'A mentoring has come to an end', 'Thank you for the time you shared. You can still message each other if you both wish.', SITE_URL . '/mentoring.php', ['email' => false]);
        if ($note !== '') aw_mentor_tell_team($db, 'A mentoring ended with a note', mb_substr($note, 0, 120));
        return true;
    } catch (Throwable $e) { error_log('mentor close: ' . $e->getMessage()); return false; }
}

function aw_mentor_tell_team(PDO $db, $title, $text) {
    if (!function_exists('awNotify')) return;
    try { foreach ($db->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN) as $sid) awNotify($db, (int)$sid, 'mentoring', $title, $text, SITE_URL . '/admin/mentoring.php', ['email' => false]); }
    catch (Throwable $e) { error_log('mentor tell team: ' . $e->getMessage()); }
}
