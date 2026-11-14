<?php
/**
 * D1: Stories & Help (PROGRAM_PLAN.md Phase 3, wishes D and B).
 *
 * One model for questions, stories, experiences and requests for help, built on the Phase 2 trust
 * layer (roles, audit log, reports, notifications, sanitiser).
 *
 * Who sees what (PROGRAM_PLAN.md 2b, with the owner's decisions 13 and 15):
 *   public-named     everyone sees the post and the member's display name
 *   public-pseudonym everyone sees the post and that post's pseudonym only; the owner and admins
 *                    can see the real account (each look is written to the audit log)
 *   team             only the author, the owner and admins see it at all
 * Moderators without the admin role moderate public posts but never see who is behind a pseudonym
 * and never see team-only posts.
 *
 * A pseudonym is chosen for each post. The author's own replies on that post keep its pseudonym.
 * author_id is never printed on a page, in an attribute or in a JSON answer for a pseudonymous
 * or team-only post; templates only ever print aw_cp_author_label().
 *
 * Posts in sensitive categories are held for a first read. Posting needs a signed-in member who
 * has confirmed they are 18 or older (decision 5). Reading published public posts is open, but
 * sensitive categories ask guests to sign in.
 *
 * The switch: settings.community_posts_enabled = '0' hides the section (rollback).
 * Nothing here throws; every function returns a value and logs failures.
 */
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/sanitize.php';

const AW_CP_TYPES = [
    'story'      => 'Story',
    'experience' => 'Experience',
    'question'   => 'Question',
    'help'       => 'Request for help',
];

/** Life areas. true = sensitive: warnings asked for, crisis block shown, held for a first read. */
const AW_CP_CATEGORIES = [
    'trauma'        => ['Trauma', true],
    'relationships' => ['Relationships', false],
    'marriage'      => ['Marriage and family', false],
    'parenting'     => ['Parenting', false],
    'anxiety'       => ['Fear and anxiety', true],
    'depression'    => ['Depression', true],
    'grief'         => ['Grief and loss', true],
    'shame'         => ['Shame and guilt', true],
    'anger'         => ['Anger', false],
    'trust'         => ['Trust', false],
    'loneliness'    => ['Loneliness', false],
    'addiction'     => ['Addiction and recovery', true],
    'illness'       => ['Illness and caregiving', false],
    'money'         => ['Money and work', false],
    'faith'         => ['Faith and doubt', false],
    'forgiveness'   => ['Forgiveness', false],
    'identity'      => ['Identity and self-worth', false],
    'gratitude'     => ['Gratitude and testimony', false],
    'other'         => ['Something else', false],
];

/** Content warnings: a reader sees these before the post opens, and can choose never to see a topic. */
const AW_CP_WARNINGS = [
    'abuse'     => 'Abuse',
    'sexual'    => 'Sexual violence',
    'violence'  => 'Violence',
    'selfharm'  => 'Self-harm',
    'suicide'   => 'Suicide',
    'death'     => 'Death and dying',
    'illness'   => 'Serious illness',
    'addiction' => 'Addiction',
    'eating'    => 'Eating difficulties',
    'pregnancy' => 'Pregnancy loss',
];
/** Warnings that also bring the crisis block. */
const AW_CP_CRISIS_WARNINGS = ['abuse', 'sexual', 'violence', 'selfharm', 'suicide'];

const AW_CP_VISIBILITY = [
    'named'  => 'Public, with my name',
    'pseudo' => 'Public, under a pseudonym',
    'team'   => 'Private, only the AngelWrites team',
];

const AW_CP_LIMITS = ['posts_per_day' => 5, 'replies_per_day' => 30, 'title' => 140, 'body' => 20000, 'reply' => 6000];

function aw_cp_enabled() {
    return !function_exists('aw_setting') || (string)aw_setting('community_posts_enabled', '1') !== '0';
}

function aw_cp_tables(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS community_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            author_id INTEGER NOT NULL,
            type TEXT NOT NULL DEFAULT 'story',
            visibility TEXT NOT NULL DEFAULT 'named',
            pseudonym TEXT,
            category TEXT NOT NULL DEFAULT 'other',
            warnings TEXT NOT NULL DEFAULT '',
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'published',
            review_note TEXT,
            source_question_id INTEGER UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            published_at DATETIME,
            removed_at DATETIME)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cp_list ON community_posts (status, visibility, published_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cp_author ON community_posts (author_id)");
        $db->exec("CREATE TABLE IF NOT EXISTS community_replies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            author_id INTEGER NOT NULL,
            pseudonym TEXT,
            as_team INTEGER NOT NULL DEFAULT 0,
            body TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'published',
            source_answer_id INTEGER UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cr_post ON community_replies (post_id, status)");
        $db->exec("CREATE TABLE IF NOT EXISTS community_thanks (reply_id INTEGER NOT NULL, user_id INTEGER NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (reply_id, user_id))");
        $db->exec("CREATE TABLE IF NOT EXISTS community_members (
            user_id INTEGER PRIMARY KEY,
            age_confirmed_at DATETIME,
            help_areas TEXT NOT NULL DEFAULT '',
            hidden_warnings TEXT NOT NULL DEFAULT '',
            restricted_until DATETIME,
            restricted_reason TEXT,
            updated_at DATETIME)");
        return $ok = true;
    } catch (Throwable $e) {
        error_log('community tables: ' . $e->getMessage());
        return $ok = false;
    }
}

// ----- small helpers ------------------------------------------------------------------------

function aw_cp_me() { return (int)($_SESSION['user_id'] ?? 0); }

function aw_cp_is_sensitive($category) { return !empty(AW_CP_CATEGORIES[$category][1]); }

function aw_cp_category_label($c) { return AW_CP_CATEGORIES[$c][0] ?? 'Something else'; }

function aw_cp_warning_list($csv) {
    $out = [];
    foreach (explode(',', (string)$csv) as $w) { $w = trim($w); if (isset(AW_CP_WARNINGS[$w])) $out[] = $w; }
    return array_values(array_unique($out));
}

/** Does this post bring the crisis block? */
function aw_cp_needs_crisis(array $post) {
    return aw_cp_is_sensitive($post['category'] ?? '') || array_intersect(aw_cp_warning_list($post['warnings'] ?? ''), AW_CP_CRISIS_WARNINGS);
}

function aw_cp_member(PDO $db, $uid) {
    $blank = ['user_id' => (int)$uid, 'age_confirmed_at' => null, 'help_areas' => '', 'hidden_warnings' => '', 'restricted_until' => null, 'restricted_reason' => null];
    if (!$uid || !aw_cp_tables($db)) return $blank;
    try {
        $st = $db->prepare("SELECT * FROM community_members WHERE user_id = ?");
        $st->execute([(int)$uid]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: $blank;
    } catch (Throwable $e) { error_log('cp member: ' . $e->getMessage()); return $blank; }
}

function aw_cp_member_save(PDO $db, $uid, array $fields) {
    if (!$uid || !aw_cp_tables($db)) return false;
    $allowed = ['age_confirmed_at', 'help_areas', 'hidden_warnings', 'restricted_until', 'restricted_reason'];
    $fields = array_intersect_key($fields, array_flip($allowed));
    if (!$fields) return false;
    try {
        $db->prepare("INSERT OR IGNORE INTO community_members (user_id) VALUES (?)")->execute([(int)$uid]);
        $set = implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($fields)));
        $db->prepare("UPDATE community_members SET $set, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?")
           ->execute(array_merge(array_values($fields), [(int)$uid]));
        return true;
    } catch (Throwable $e) { error_log('cp member save: ' . $e->getMessage()); return false; }
}

/** Is this member currently restricted from posting? Returns the message to show, or ''. */
function aw_cp_restriction(PDO $db, $uid) {
    $m = aw_cp_member($db, $uid);
    if (!empty($m['restricted_until']) && strtotime($m['restricted_until']) > time()) {
        return 'Posting is paused on your account until ' . date('j F Y', strtotime($m['restricted_until'])) . '.'
            . (trim((string)$m['restricted_reason']) !== '' ? ' Note from the team: ' . $m['restricted_reason'] : '')
            . ' You can still read, and you can reach the team through Contact.';
    }
    return '';
}

/** Can the signed-in member see real identities and team-only posts? (owner and admins) */
function aw_cp_staff_full() { return function_exists('aw_can') && aw_can('see_identities'); }
/** Can the signed-in member moderate public posts? */
function aw_cp_staff_mod() { return function_exists('aw_can') && aw_can('moderate'); }

// ----- visibility ---------------------------------------------------------------------------

/** May the current visitor open this post? */
function aw_cp_can_view(array $post) {
    $me = aw_cp_me();
    $mine = $me && (int)$post['author_id'] === $me;
    if ($post['status'] === 'removed') return $mine || aw_cp_staff_full();
    if ($post['visibility'] === 'team') return $mine || aw_cp_staff_full();
    if ($post['status'] === 'published' || $post['status'] === 'closed') return true;
    // held, hidden, draft: the author and the staff who moderate
    return $mine || aw_cp_staff_mod();
}

/** The SQL condition for posts the current visitor may see in lists (alias p). */
function aw_cp_list_where() {
    if (aw_cp_staff_full()) return "p.status IN ('published','closed')";
    return "p.status IN ('published','closed') AND p.visibility <> 'team'";
}

/** The name shown for a post's author. Never the real name of a pseudonymous or team-only post. */
function aw_cp_author_label(PDO $db, array $post) {
    if ($post['visibility'] === 'pseudo') return (string)($post['pseudonym'] ?: 'A member');
    if ($post['visibility'] === 'team') {
        $me = aw_cp_me();
        return ($me && (int)$post['author_id'] === $me) ? 'You (private to the team)' : 'A member (private to the team)';
    }
    return aw_cp_user_name($db, (int)$post['author_id']);
}

function aw_cp_reply_label(PDO $db, array $reply, array $post) {
    if (!empty($reply['as_team'])) return 'AngelWrites Team';
    if (($reply['pseudonym'] ?? '') !== '' && $reply['pseudonym'] !== null) return (string)$reply['pseudonym'];
    if ($post['visibility'] === 'team') return (int)$reply['author_id'] === (int)$post['author_id'] ? aw_cp_author_label($db, $post) : 'AngelWrites Team';
    return aw_cp_user_name($db, (int)$reply['author_id']);
}

function aw_cp_user_name(PDO $db, $uid) {
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    try {
        $st = $db->prepare("SELECT name, first_name, last_name, username, display_name FROM users WHERE id = ?");
        $st->execute([(int)$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        return $cache[$uid] = $u ? (trim((string)($u['display_name'] ?? '')) ?: awDisplayName($u)) : 'A former member';
    } catch (Throwable $e) { return 'A member'; }
}

/** For the owner and admins: the real account behind a post, written to the audit log. */
function aw_cp_reveal_identity(PDO $db, $post_id, $reply_id = 0) {
    if (!aw_cp_staff_full() || !aw_cp_tables($db)) return null;
    try {
        if ($reply_id) {
            $st = $db->prepare("SELECT author_id FROM community_replies WHERE id = ? AND post_id = ?");
            $st->execute([(int)$reply_id, (int)$post_id]);
        } else {
            $st = $db->prepare("SELECT author_id FROM community_posts WHERE id = ?");
            $st->execute([(int)$post_id]);
        }
        $uid = (int)$st->fetchColumn();
        if (!$uid) return null;
        aw_audit('identity_viewed', $reply_id ? 'community_reply' : 'community_post', $reply_id ?: $post_id, 'post #' . (int)$post_id);
        $u = $db->prepare("SELECT id, name, first_name, last_name, username, email FROM users WHERE id = ?");
        $u->execute([$uid]);
        $row = $u->fetch(PDO::FETCH_ASSOC);
        return $row ? ['id' => (int)$row['id'], 'name' => awDisplayName($row), 'username' => (string)$row['username'], 'email' => (string)$row['email']] : null;
    } catch (Throwable $e) { error_log('cp reveal: ' . $e->getMessage()); return null; }
}

// ----- pseudonyms ---------------------------------------------------------------------------

/** A gentle suggested pseudonym, e.g. "Quiet Willow". */
function aw_cp_suggest_pseudonym() {
    $a = ['Quiet', 'Gentle', 'Hopeful', 'Morning', 'Still', 'Brave', 'Kind', 'Patient', 'Steady', 'Tender', 'Bright', 'Faithful', 'Healing', 'Evening', 'Rising'];
    $b = ['Willow', 'Dove', 'River', 'Cedar', 'Sparrow', 'Lily', 'Harbour', 'Meadow', 'Olive', 'Lantern', 'Rain', 'Stone', 'Fig Tree', 'Shepherd', 'Garden'];
    return $a[random_int(0, count($a) - 1)] . ' ' . $b[random_int(0, count($b) - 1)];
}

/**
 * Check a pseudonym. Returns [clean, error]. It may not look like the team, and may not be any
 * member's name or username (so nobody can post as someone else, and no real name sits there).
 */
function aw_cp_check_pseudonym(PDO $db, $p) {
    $p = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$p)));
    if ($p === '') return ['', 'Please choose a pseudonym, or use the suggested one.'];
    if (mb_strlen($p) < 3 || mb_strlen($p) > 40) return ['', 'A pseudonym needs 3 to 40 characters.'];
    if (!preg_match('/^[\p{L}\p{N} .\'-]+$/u', $p)) return ['', 'Please use only letters, numbers, spaces and . \' -'];
    if (preg_match('/angel\s*writes|admin|moderator|\bteam\b|staff|angella|official/iu', $p)) return ['', 'Please choose a pseudonym that does not sound like the AngelWrites team.'];
    try {
        $lp = mb_strtolower($p);
        foreach ($db->query("SELECT name, first_name, last_name, username, display_name FROM users") as $u) {
            $names = [awDisplayName($u), (string)$u['username'], (string)($u['display_name'] ?? ''), trim($u['first_name'] . ' ' . $u['last_name'])];
            foreach ($names as $n) if ($n !== '' && mb_strtolower(trim($n)) === $lp) return ['', 'That is a member\'s name. Please choose a different pseudonym.'];
        }
    } catch (Throwable $e) { error_log('cp pseudonym: ' . $e->getMessage()); }
    return [$p, ''];
}

// ----- reading ------------------------------------------------------------------------------

function aw_cp_get(PDO $db, $id) {
    if (!aw_cp_tables($db)) return null;
    try {
        $st = $db->prepare("SELECT * FROM community_posts WHERE id = ?");
        $st->execute([(int)$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('cp get: ' . $e->getMessage()); return null; }
}

/**
 * Posts for the list. $f: type, category, help (bool: requests for help only), q (search), page.
 * Hidden topics are left out for signed-in members; the count of those left out is returned too.
 * Returns ['rows' => [...], 'total' => n, 'hidden' => n].
 */
function aw_cp_list(PDO $db, array $f, $per = 12) {
    $out = ['rows' => [], 'total' => 0, 'hidden' => 0];
    if (!aw_cp_tables($db)) return $out;
    $where = [aw_cp_list_where()];
    $args = [];
    if (!empty($f['type']) && isset(AW_CP_TYPES[$f['type']])) { $where[] = 'p.type = ?'; $args[] = $f['type']; }
    if (!empty($f['category']) && isset(AW_CP_CATEGORIES[$f['category']])) { $where[] = 'p.category = ?'; $args[] = $f['category']; }
    if (!empty($f['q'])) { $where[] = '(p.title LIKE ? OR p.body LIKE ?)'; $args[] = '%' . $f['q'] . '%'; $args[] = '%' . $f['q'] . '%'; }
    $me = aw_cp_me();
    if ($me && empty($f['show_hidden'])) {
        $hidden = aw_cp_warning_list(aw_cp_member($db, $me)['hidden_warnings']);
        if ($hidden) {
            $parts = [];
            foreach ($hidden as $w) { $parts[] = "(',' || p.warnings || ',') NOT LIKE ?"; $args[] = '%,' . $w . ',%'; }
            $hideSql = implode(' AND ', $parts);
            try {
                $cnt = $db->prepare("SELECT COUNT(*) FROM community_posts p WHERE " . implode(' AND ', $where) . " AND NOT (" . $hideSql . ")");
                $cnt->execute($args);
                $out['hidden'] = (int)$cnt->fetchColumn();
            } catch (Throwable $e) { error_log('cp hidden count: ' . $e->getMessage()); }
            $where[] = '(' . $hideSql . ')';
        }
    }
    $page = max(1, (int)($f['page'] ?? 1));
    try {
        $w = implode(' AND ', $where);
        $c = $db->prepare("SELECT COUNT(*) FROM community_posts p WHERE $w");
        $c->execute($args);
        $out['total'] = (int)$c->fetchColumn();
        $st = $db->prepare("SELECT p.*, (SELECT COUNT(*) FROM community_replies r WHERE r.post_id = p.id AND r.status = 'published') AS reply_count
                            FROM community_posts p WHERE $w ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC LIMIT ? OFFSET ?");
        $st->execute(array_merge($args, [(int)$per, ($page - 1) * (int)$per]));
        $out['rows'] = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('cp list: ' . $e->getMessage()); }
    return $out;
}

function aw_cp_replies(PDO $db, array $post) {
    if (!aw_cp_tables($db)) return [];
    try {
        $sql = "SELECT * FROM community_replies WHERE post_id = ? AND status = 'published' ORDER BY created_at, id";
        $st = $db->prepare($sql);
        $st->execute([(int)$post['id']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('cp replies: ' . $e->getMessage()); return []; }
}

/** A short plain-text opening for cards. */
function aw_cp_excerpt($html, $len = 220) {
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], ' ', (string)$html)), ENT_QUOTES, 'UTF-8')));
    return mb_strlen($t) > $len ? rtrim(mb_substr($t, 0, $len)) . '…' : $t;
}

/** Members who offered help in this area (opt-in), named only, in no particular order. */
function aw_cp_helpers(PDO $db, $category, $exclude = 0, $limit = 5) {
    if (!aw_cp_tables($db) || !isset(AW_CP_CATEGORIES[$category])) return [];
    try {
        $st = $db->prepare("SELECT m.user_id FROM community_members m JOIN users u ON u.id = m.user_id
                            WHERE (',' || m.help_areas || ',') LIKE ? AND m.user_id <> ? AND (m.restricted_until IS NULL OR m.restricted_until < CURRENT_TIMESTAMP)
                            ORDER BY RANDOM() LIMIT ?");
        $st->execute(['%,' . $category . ',%', (int)$exclude, (int)$limit]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            if (function_exists('aw_is_blocked') && aw_cp_me() && (aw_is_blocked($db, aw_cp_me(), (int)$uid) || aw_is_blocked($db, (int)$uid, aw_cp_me()))) continue;
            $out[] = ['id' => (int)$uid, 'name' => aw_cp_user_name($db, (int)$uid)];
        }
        return $out;
    } catch (Throwable $e) { error_log('cp helpers: ' . $e->getMessage()); return []; }
}

// ----- writing ------------------------------------------------------------------------------

function aw_cp_count_today(PDO $db, $table, $uid) {
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM $table WHERE author_id = ? AND created_at >= datetime('now', '-1 day')");
        $st->execute([(int)$uid]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** What a form sent: editor HTML, or plain text from the no-script textarea, made into paragraphs. */
function aw_cp_input_html($raw) {
    $raw = (string)$raw;
    if ($raw !== strip_tags($raw)) return $raw;
    $paras = preg_split('/\R{2,}/u', trim($raw));
    $out = '';
    foreach ($paras as $p) if (trim($p) !== '') $out .= '<p>' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES, 'UTF-8')) . '</p>';
    return $out;
}

/** Plain text length of rich text. */
function aw_cp_text_len($html) { return mb_strlen(trim(html_entity_decode(strip_tags((string)$html), ENT_QUOTES, 'UTF-8'))); }

/**
 * Save a post (new or edit). $in: id, type, visibility, pseudonym, category, warnings[], no_warnings,
 * title, body, age_ok. Returns ['ok' => bool, 'errors' => [...], 'id' => n, 'status' => s].
 */
function aw_cp_save_post(PDO $db, array $in) {
    $me = aw_cp_me();
    $errors = [];
    if (!$me) return ['ok' => false, 'errors' => ['Please sign in to share.']];
    if (!aw_cp_tables($db)) return ['ok' => false, 'errors' => ['Sharing is not available right now. Please try again later.']];
    if ($r = aw_cp_restriction($db, $me)) return ['ok' => false, 'errors' => [$r]];

    $id = (int)($in['id'] ?? 0);
    $old = $id ? aw_cp_get($db, $id) : null;
    if ($id && (!$old || (int)$old['author_id'] !== $me || $old['status'] === 'removed')) return ['ok' => false, 'errors' => ['You can only edit your own posts.']];

    $member = aw_cp_member($db, $me);
    if (empty($member['age_confirmed_at']) && !aw_cp_staff_mod()) { // the team are adults by role
        if (empty($in['age_ok'])) $errors['age_ok'] = 'Please confirm you are 18 or older. This space is for adults.';
    }
    $type = isset(AW_CP_TYPES[$in['type'] ?? '']) ? $in['type'] : '';
    if ($type === '') $errors['type'] = 'Please choose what you are sharing.';
    $vis = isset(AW_CP_VISIBILITY[$in['visibility'] ?? '']) ? $in['visibility'] : '';
    if ($vis === '') $errors['visibility'] = 'Please choose who can see this.';
    $cat = isset(AW_CP_CATEGORIES[$in['category'] ?? '']) ? $in['category'] : '';
    if ($cat === '') $errors['category'] = 'Please choose the area of life this is about.';
    $pseudo = null;
    if ($vis === 'pseudo') {
        [$pseudo, $perr] = aw_cp_check_pseudonym($db, $in['pseudonym'] ?? '');
        if ($perr) $errors['pseudonym'] = $perr;
    }
    $warn = aw_cp_warning_list(implode(',', (array)($in['warnings'] ?? [])));
    if ($cat !== '' && aw_cp_is_sensitive($cat) && !$warn && empty($in['no_warnings'])) {
        $errors['warnings'] = 'This area can be hard to read about. Please tick any warnings that apply, or tick "None of these".';
    }
    $title = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($in['title'] ?? ''))));
    if ($title === '') $errors['title'] = 'Please give it a title.';
    elseif (mb_strlen($title) > AW_CP_LIMITS['title']) $errors['title'] = 'Please keep the title under ' . AW_CP_LIMITS['title'] . ' characters.';
    $body = aw_clean_html(aw_cp_input_html($in['body'] ?? ''), 'member');
    $len = aw_cp_text_len($body);
    if ($len < 20) $errors['body'] = 'Please write a little more (at least 20 characters).';
    elseif ($len > AW_CP_LIMITS['body']) $errors['body'] = 'This is longer than ' . number_format(AW_CP_LIMITS['body']) . ' characters. Please shorten it.';
    if (!$id && aw_cp_count_today($db, 'community_posts', $me) >= AW_CP_LIMITS['posts_per_day']) $errors[] = 'You have shared ' . AW_CP_LIMITS['posts_per_day'] . ' posts today. Please come back tomorrow.';
    if ($errors) return ['ok' => false, 'errors' => $errors];

    // Sensitive areas are read by the team first. Team-only posts go straight to the team.
    $held = $vis !== 'team' && (aw_cp_is_sensitive($cat) || (string)aw_setting('community_premoderate_all', '0') === '1');
    if ($old && $old['status'] === 'published' && !$held) $status = 'published';
    else $status = $held ? 'held' : 'published';
    if (aw_cp_staff_mod() && $vis !== 'team') $status = 'published'; // the team's own posts need no first read

    try {
        if (empty($member['age_confirmed_at'])) aw_cp_member_save($db, $me, ['age_confirmed_at' => date('Y-m-d H:i:s')]);
        if ($old) {
            $db->prepare("UPDATE community_posts SET type = ?, visibility = ?, pseudonym = ?, category = ?, warnings = ?, title = ?, body = ?, status = ?,
                          updated_at = CURRENT_TIMESTAMP, published_at = CASE WHEN ? = 'published' THEN COALESCE(published_at, CURRENT_TIMESTAMP) ELSE published_at END WHERE id = ?")
               ->execute([$type, $vis, $pseudo, $cat, implode(',', $warn), $title, $body, $status, $status, $id]);
        } else {
            $db->prepare("INSERT INTO community_posts (author_id, type, visibility, pseudonym, category, warnings, title, body, status, published_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CASE WHEN ? = 'published' THEN CURRENT_TIMESTAMP ELSE NULL END)")
               ->execute([$me, $type, $vis, $pseudo, $cat, implode(',', $warn), $title, $body, $status, $status]);
            $id = (int)$db->lastInsertId();
        }
        aw_cp_tell_staff($db, $id, $vis, $status, $old ? 'edited' : 'new');
        return ['ok' => true, 'errors' => [], 'id' => $id, 'status' => $status];
    } catch (Throwable $e) {
        error_log('cp save post: ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['Your post could not be saved. Please try again.']];
    }
}

/** Bell notifications for the team. Team-only posts reach the owner and admins only. */
function aw_cp_tell_staff(PDO $db, $post_id, $vis, $status, $what) {
    if ($status !== 'held' && $vis !== 'team') return;
    $roles = $vis === 'team' ? "('admin')" : "('admin','moderator')";
    $title = $vis === 'team' ? 'A private request for the team' : 'A post is waiting for a first read';
    if ($what === 'edited') $title = $vis === 'team' ? 'A private post for the team was updated' : 'An edited post is waiting for a first read';
    try {
        foreach ($db->query("SELECT id FROM users WHERE role IN $roles")->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            awNotify($db, (int)$sid, 'community_review', $title, 'Stories & Help', SITE_URL . '/admin/community.php', ['email' => $vis === 'team', 'actor' => 0]);
        }
    } catch (Throwable $e) { error_log('cp tell staff: ' . $e->getMessage()); }
}

/**
 * Add a reply. $in: body, mode ('named', 'pseudo', 'team'), pseudonym, age_ok.
 * Returns ['ok', 'errors', 'id'].
 */
function aw_cp_add_reply(PDO $db, array $post, array $in) {
    $me = aw_cp_me();
    if (!$me) return ['ok' => false, 'errors' => ['Please sign in to reply.']];
    if (!aw_cp_can_view($post) || !in_array($post['status'], ['published'], true)) return ['ok' => false, 'errors' => ['Replies are closed on this post.']];
    if ($r = aw_cp_restriction($db, $me)) return ['ok' => false, 'errors' => [$r]];
    $errors = [];
    $member = aw_cp_member($db, $me);
    if (empty($member['age_confirmed_at']) && empty($in['age_ok']) && !aw_cp_staff_mod()) $errors['age_ok'] = 'Please confirm you are 18 or older. This space is for adults.';
    $isAuthor = (int)$post['author_id'] === $me;
    $mode = (string)($in['mode'] ?? 'named');
    $pseudo = null; $asTeam = 0;
    if ($post['visibility'] === 'team') {
        // only the author and the owner/admins can be here; staff answer as the team
        if (!$isAuthor) { if (!aw_cp_staff_full()) return ['ok' => false, 'errors' => ['Replies are closed on this post.']]; $asTeam = 1; }
    } elseif ($isAuthor && $post['visibility'] === 'pseudo') {
        $pseudo = (string)$post['pseudonym']; // the author keeps the post's pseudonym
    } elseif ($mode === 'team' && aw_cp_staff_mod()) {
        $asTeam = 1;
    } elseif ($mode === 'pseudo') {
        [$pseudo, $perr] = aw_cp_check_pseudonym($db, $in['pseudonym'] ?? '');
        if ($perr) $errors['pseudonym'] = $perr;
    }
    $body = aw_clean_html(aw_cp_input_html($in['body'] ?? ''), 'member');
    $len = aw_cp_text_len($body);
    if ($len < 2) $errors['body'] = 'Please write your reply.';
    elseif ($len > AW_CP_LIMITS['reply']) $errors['body'] = 'Please keep a reply under ' . number_format(AW_CP_LIMITS['reply']) . ' characters.';
    if (aw_cp_count_today($db, 'community_replies', $me) >= AW_CP_LIMITS['replies_per_day']) $errors[] = 'You have written ' . AW_CP_LIMITS['replies_per_day'] . ' replies today. Please come back tomorrow.';
    if ($errors) return ['ok' => false, 'errors' => $errors];
    try {
        if (empty($member['age_confirmed_at'])) aw_cp_member_save($db, $me, ['age_confirmed_at' => date('Y-m-d H:i:s')]);
        $db->prepare("INSERT INTO community_replies (post_id, author_id, pseudonym, as_team, body) VALUES (?, ?, ?, ?, ?)")
           ->execute([(int)$post['id'], $me, $pseudo, $asTeam, $body]);
        $rid = (int)$db->lastInsertId();
        if ($asTeam) aw_audit('team_reply', 'community_post', (int)$post['id'], 'reply #' . $rid);
        $link = SITE_URL . '/story.php?id=' . (int)$post['id'] . '#reply-' . $rid;
        $what = mb_strtolower(AW_CP_TYPES[$post['type']] ?? 'post');
        if (!$isAuthor) {
            // never names a pseudonymous replier, and never names anyone on a private post
            awNotify($db, (int)$post['author_id'], 'reply', $asTeam ? 'The AngelWrites team replied' : 'Someone replied to your ' . $what,
                '“' . $post['title'] . '”', $link, ['actor' => $me]);
        } elseif ($post['visibility'] === 'team') {
            foreach ($db->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                awNotify($db, (int)$sid, 'community_review', 'A private request has a new message', 'Stories & Help', $link, ['actor' => $me]);
            }
        }
        return ['ok' => true, 'errors' => [], 'id' => $rid];
    } catch (Throwable $e) {
        error_log('cp add reply: ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['Your reply could not be saved. Please try again.']];
    }
}

/** "Thank you, this helped": private to the reply's author (no counts are shown to anyone else). */
function aw_cp_thank(PDO $db, $reply_id) {
    $me = aw_cp_me();
    if (!$me || !aw_cp_tables($db)) return false;
    try {
        $st = $db->prepare("SELECT r.*, p.title, p.id AS pid FROM community_replies r JOIN community_posts p ON p.id = r.post_id WHERE r.id = ? AND r.status = 'published'");
        $st->execute([(int)$reply_id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)$r['author_id'] === $me) return false;
        $post = aw_cp_get($db, (int)$r['pid']);
        if (!$post || !aw_cp_can_view($post)) return false;
        $ins = $db->prepare("INSERT OR IGNORE INTO community_thanks (reply_id, user_id) VALUES (?, ?)");
        $ins->execute([(int)$reply_id, $me]);
        if ($ins->rowCount()) {
            awNotify($db, (int)$r['author_id'], 'thanks', 'Someone said your reply helped them', '“' . $r['title'] . '”',
                SITE_URL . '/story.php?id=' . (int)$r['pid'] . '#reply-' . (int)$reply_id, ['actor' => $me]);
        }
        return true;
    } catch (Throwable $e) { error_log('cp thank: ' . $e->getMessage()); return false; }
}

/** The author deletes their own post or reply: kept hidden (removed) for 90 days, then purged. */
function aw_cp_delete_own(PDO $db, $kind, $id) {
    $me = aw_cp_me();
    if (!$me || !aw_cp_tables($db)) return false;
    $table = $kind === 'reply' ? 'community_replies' : 'community_posts';
    try {
        $extra = $kind === 'reply' ? '' : ', removed_at = CURRENT_TIMESTAMP';
        $st = $db->prepare("UPDATE $table SET status = 'removed'$extra, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND author_id = ?");
        $st->execute([(int)$id, $me]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) { error_log('cp delete: ' . $e->getMessage()); return false; }
}

/** Staff decisions on a held post: 'publish', 'return' (back to the author with a note), 'hide', 'close', 'reopen'. */
function aw_cp_review(PDO $db, $post_id, $decision, $note = '') {
    $post = aw_cp_get($db, $post_id);
    if (!$post || !aw_cp_staff_mod()) return false;
    if ($post['visibility'] === 'team' && !aw_cp_staff_full()) return false;
    $note = trim(strip_tags((string)$note));
    $map = ['publish' => 'published', 'return' => 'draft', 'hide' => 'hidden', 'close' => 'closed', 'reopen' => 'published'];
    if (!isset($map[$decision])) return false;
    try {
        $db->prepare("UPDATE community_posts SET status = ?, review_note = ?, updated_at = CURRENT_TIMESTAMP,
                      published_at = CASE WHEN ? = 'published' THEN COALESCE(published_at, CURRENT_TIMESTAMP) ELSE published_at END WHERE id = ?")
           ->execute([$map[$decision], $note !== '' ? $note : null, $map[$decision], (int)$post_id]);
        aw_audit('community_' . $decision, 'community_post', (int)$post_id, $note);
        $link = SITE_URL . '/story.php?id=' . (int)$post_id;
        $msgs = [
            'publish' => ['Your post is now shared', 'Thank you for trusting the community with it.'],
            'return'  => ['Your post needs a small change before it is shared', $note !== '' ? $note : 'Please open it to see the note from the team.'],
            'hide'    => ['Your post has been paused', $note !== '' ? $note : 'The team will be in touch.'],
            'close'   => ['Replies are now closed on your post', $note !== '' ? $note : 'It stays readable.'],
            'reopen'  => ['Replies are open again on your post', ''],
        ];
        awNotify($db, (int)$post['author_id'], 'community_review', $msgs[$decision][0], '“' . $post['title'] . '”' . ($msgs[$decision][1] !== '' ? ' — ' . $msgs[$decision][1] : ''), $link, ['actor' => 0]);
        return true;
    } catch (Throwable $e) { error_log('cp review: ' . $e->getMessage()); return false; }
}

/** Retention: posts and replies removed more than 90 days ago are purged. Cheap; run on staff page loads. */
function aw_cp_purge(PDO $db) {
    if (!aw_cp_tables($db)) return 0;
    try {
        $n = $db->exec("DELETE FROM community_replies WHERE status = 'removed' AND updated_at < datetime('now', '-90 days')");
        $n += $db->exec("DELETE FROM community_replies WHERE post_id IN (SELECT id FROM community_posts WHERE status = 'removed' AND removed_at < datetime('now', '-90 days'))");
        $n += $db->exec("DELETE FROM community_posts WHERE status = 'removed' AND removed_at < datetime('now', '-90 days')");
        return (int)$n;
    } catch (Throwable $e) { error_log('cp purge: ' . $e->getMessage()); return 0; }
}

/**
 * Copy the old Q&A into this model (questions become 'question' posts with the member's name;
 * answers become replies). Safe to run more than once: each item is copied once.
 */
function aw_cp_migrate_questions(PDO $db) {
    if (!aw_cp_tables($db)) return ['posts' => 0, 'replies' => 0];
    $p = 0; $r = 0;
    try {
        $db->beginTransaction();
        foreach ($db->query("SELECT * FROM questions")->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $st = $db->prepare("INSERT OR IGNORE INTO community_posts (author_id, type, visibility, category, title, body, status, source_question_id, created_at, published_at)
                                VALUES (?, 'question', 'named', 'other', ?, ?, 'published', ?, ?, ?)");
            $body = aw_clean_html(nl2br(htmlspecialchars((string)$q['body'], ENT_QUOTES, 'UTF-8')), 'member');
            $st->execute([(int)$q['user_id'], mb_substr(trim((string)$q['title']) ?: 'A question', 0, 140), $body !== '' ? $body : '<p>(no details)</p>', (int)$q['id'], $q['created_at'], $q['created_at']]);
            $p += $st->rowCount();
        }
        $map = [];
        foreach ($db->query("SELECT id, source_question_id FROM community_posts WHERE source_question_id IS NOT NULL") as $row) $map[(int)$row['source_question_id']] = (int)$row['id'];
        foreach ($db->query("SELECT * FROM answers")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if (!isset($map[(int)$a['question_id']])) continue;
            $st = $db->prepare("INSERT OR IGNORE INTO community_replies (post_id, author_id, body, source_answer_id, created_at) VALUES (?, ?, ?, ?, ?)");
            $st->execute([$map[(int)$a['question_id']], (int)$a['user_id'], aw_clean_html(nl2br(htmlspecialchars((string)$a['body'], ENT_QUOTES, 'UTF-8')), 'member'), (int)$a['id'], $a['created_at']]);
            $r += $st->rowCount();
        }
        $db->commit();
        aw_audit('community_migrated', 'community_post', null, "$p posts, $r replies");
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('cp migrate: ' . $e->getMessage());
    }
    return ['posts' => $p, 'replies' => $r];
}

// ----- shared page pieces -------------------------------------------------------------------

/** Crisis contacts: settings.crisis_contacts, one "Label | number" per line. */
function aw_cp_crisis_contacts() {
    $raw = (string)aw_setting('crisis_contacts', "Angella (AngelWrites) | +265 99 146 9386");
    $out = [];
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $parts = array_map('trim', explode('|', $line, 2));
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') $out[] = $parts;
    }
    return $out;
}

/** The calm crisis block shown on sensitive areas, the posting form and sensitive posts. */
function aw_cp_crisis_block($compact = false) {
    $items = '';
    foreach (aw_cp_crisis_contacts() as [$label, $num]) {
        $tel = preg_replace('/[^0-9+]/', '', $num);
        $items .= '<li><span>' . htmlspecialchars($label) . '</span> <a href="tel:' . htmlspecialchars($tel) . '">' . htmlspecialchars($num) . '</a></li>';
    }
    $u = htmlspecialchars(SITE_URL);
    return '<aside class="cp-crisis' . ($compact ? ' cp-crisis-compact' : '') . '" role="note" aria-label="If you need help now">'
        . '<p class="cp-crisis-lead"><i class="fas fa-hand-holding-heart" aria-hidden="true"></i> <strong>If you are in danger or thinking of harming yourself, please reach someone now.</strong> This community is here to listen, but it is not an emergency service.</p>'
        . ($items !== '' ? '<ul class="cp-crisis-list">' . $items . '</ul>' : '')
        . '<p class="cp-crisis-more"><a href="' . $u . '/story_write.php?type=help&amp;visibility=team&amp;pray=1">Ask the team to pray with me</a> · <a href="' . $u . '/book_session.php">Book a free one-to-one</a></p>'
        . '</aside>';
}

function aw_cp_assets() {
    return '<link rel="stylesheet" href="' . SITE_URL . '/assets/css/aw-community.css?v=1">';
}

/** The members' editor: Quill toolbar over a hidden field. The server cleans whatever comes back. */
function aw_cp_editor($name, $value, $label, $opts = []) {
    $id = 'ed_' . preg_replace('/\W/', '', $name);
    $min = (int)($opts['min_height'] ?? 220);
    $ph = htmlspecialchars((string)($opts['placeholder'] ?? ''));
    return '<div class="cp-editor" data-editor data-name="' . htmlspecialchars($name) . '" data-placeholder="' . $ph . '" style="--cp-ed-min:' . $min . 'px">'
        . '<textarea id="' . $id . '" name="' . htmlspecialchars($name) . '" rows="8" aria-label="' . htmlspecialchars($label) . '" placeholder="' . $ph . '">' . htmlspecialchars((string)$value) . '</textarea>'
        . '</div>';
}

function aw_cp_editor_assets() {
    $u = SITE_URL;
    return '<link rel="stylesheet" href="' . $u . '/assets/vendor/quill/quill.snow.css">'
        . '<script src="' . $u . '/assets/vendor/quill/quill.js" defer></script>'
        . '<script src="' . $u . '/assets/js/aw-editor.js?v=1" defer></script>';
}
