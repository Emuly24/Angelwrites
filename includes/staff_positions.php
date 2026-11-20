<?php
/**
 * Volunteer positions and staff invitations.
 *
 * The owner or an admin invites a member to help with one part of the site. The invitation is
 * written from the position chosen: what the person will do, what they will not do, which pages
 * they will use and how much time it usually takes. The member accepts or declines on the site
 * (staff_invite.php); nothing changes until they accept.
 *
 *  - "Moderator" makes the member a moderator (users.role = 'moderator', as on the Staff page).
 *  - Every other position is an area: the member stays a member and gains only that area's
 *    capability (staff_areas table), checked by aw_can() in includes/roles.php. Every page for an
 *    area checks it on the server; hiding a link is never the protection.
 *  - Volunteers never see who is behind a pseudonym, settings, roles, payments, backups, the
 *    newsletter, member accounts or the admin dashboard.
 *
 * Every function returns a value and logs on failure; nothing throws.
 */

/** Rules that apply to every position; every invitation lists them. */
const AW_STAFF_RULES = [
    'Keep what you see in your role private. Never screenshot, copy or share members’ words or details outside AngelWrites.',
    'Speak as part of the AngelWrites team: kind, patient and honest. Never argue with a member.',
    'If anyone may be in danger or at risk of harm, stop and pass it to the owner straight away.',
    'Keep conversations on AngelWrites. Do not move members to your own WhatsApp, email or social media.',
    'When you are unsure, leave a handover note on the Team desk or ask the owner. Nobody expects you to know everything.',
    'You can step down at any time from your Team desk, and the owner can end the role at any time.',
];

/**
 * The positions an invitation can offer. 'cap' is the capability the position grants (see AW_CAPS);
 * 'role' => 'moderator' marks the one position that changes the member's role instead.
 */
const AW_POSITIONS = [
    'moderator' => [
        'title' => 'Moderator', 'icon' => 'fa-shield-heart', 'role' => 'moderator', 'cap' => 'moderate',
        'summary' => 'Look after the whole community: reports, Stories & Help, Q&A, comments, testimonials, prayer requests and the team inbox.',
        'time' => 'About 20–30 minutes on most days',
        'pages' => [['Moderation', 'admin/moderation.php'], ['Stories & Help', 'admin/community.php'], ['Team inbox', 'admin/inbox.php'], ['Prayer requests', 'admin/manage_prayer_requests.php']],
        'do' => [
            'Review reports from members and decide whether to keep or hide what was reported.',
            'Give new Stories & Help posts their first read and publish or hold them.',
            'Answer questions in Community Q&A and reply to people asking for help.',
            'Review new comments and testimonials, and remove spam or unkind words.',
            'Look after prayer requests and answer messages in the team inbox.',
            'Leave handover notes so the next person on duty knows what happened.',
        ],
        'dont' => [
            'See who is behind a pseudonym or an anonymous post. That stays with the owner and admins.',
            'Change settings, staff roles, payments, backups or the newsletter.',
            'Edit or delete members’ accounts or profiles.',
            'Publish or change poems, books, blog posts or reflections.',
        ],
    ],
    'prayer' => [
        'title' => 'Prayer team', 'icon' => 'fa-hands-praying', 'cap' => 'prayer_team',
        'summary' => 'Read new prayer requests, pray for them and keep the prayer wall caring and safe.',
        'time' => 'A few minutes each day, or a set day each week',
        'pages' => [['Prayer requests', 'admin/manage_prayer_requests.php']],
        'do' => [
            'Read new prayer requests and pray for each one.',
            'Mark requests as prayed for, or answered when the person says so.',
            'Hide a request that names or exposes someone else, or is not a prayer request, and leave a note saying why.',
            'Pass any request that mentions danger, abuse or self-harm to the owner at once.',
        ],
        'dont' => [
            'Share requests outside the team, even to ask others to pray.',
            'Contact the person outside AngelWrites.',
            'See who sent an anonymous request.',
            'Handle reports, comments, stories or other parts of the site.',
        ],
    ],
    'community' => [
        'title' => 'Stories & Help and Q&A helper', 'icon' => 'fa-seedling', 'cap' => 'community_team',
        'summary' => 'Help members who share their stories or ask for help, and keep Community Q&A answered.',
        'time' => 'About 15 minutes on most days',
        'pages' => [['Stories & Help', 'admin/community.php'], ['Community Q&A', 'admin/manage_questions.php']],
        'do' => [
            'Give new Stories & Help posts their first read, then publish them or hold them for the owner.',
            'Reply, as the team, to people who ask for help: listen first, then encourage.',
            'Answer or tidy up questions in Community Q&A.',
            'Pass anything about danger, abuse or self-harm to the owner at once.',
        ],
        'dont' => [
            'See who is behind a pseudonym.',
            'Give medical, legal or financial advice, or promise outcomes.',
            'Remove a post because you disagree with it.',
            'Handle reports, payments or other parts of the site.',
        ],
    ],
    'comments' => [
        'title' => 'Comments & testimonials reviewer', 'icon' => 'fa-comments', 'cap' => 'comments_team',
        'summary' => 'Keep comments kind and spam-free, and review testimonials before they appear.',
        'time' => 'A few minutes each day',
        'pages' => [['Comments', 'admin/comments.php'], ['Testimonials', 'admin/manage_testimonials.php']],
        'do' => [
            'Read new comments and remove spam, unkind or unsafe ones.',
            'Review testimonials and approve the ones that are genuine and kind.',
            'Pass anything worrying to the owner.',
        ],
        'dont' => [
            'Change anyone’s words to alter what they meant.',
            'Remove fair, respectful disagreement.',
            'Handle reports, stories, prayer requests or other parts of the site.',
        ],
    ],
    'inbox' => [
        'title' => 'Team inbox helper', 'icon' => 'fa-inbox', 'cap' => 'team_inbox',
        'summary' => 'Answer messages from the contact form and from members who write to the team.',
        'time' => 'About 10 minutes each day',
        'pages' => [['Team inbox', 'admin/inbox.php']],
        'do' => [
            'Read and answer messages kindly, in the team’s name.',
            'Mark messages as answered so nobody answers twice.',
            'Pass orders, payments, safeguarding and anything you cannot answer to the owner or an admin.',
        ],
        'dont' => [
            'Give out anyone’s personal details, including your own.',
            'Promise refunds, prices or anything about payments.',
            'Move a conversation to your own WhatsApp, email or phone.',
        ],
    ],
    'events' => [
        'title' => 'Events coordinator', 'icon' => 'fa-calendar-days', 'cap' => 'events_team',
        'summary' => 'Keep the events calendar up to date: readings, launches, gatherings and online meetings.',
        'time' => 'About an hour a week',
        'pages' => [['Events', 'admin/events.php']],
        'do' => [
            'Add events with clear dates, times, places or links, and descriptions.',
            'Update or cancel events, and tell members in good time.',
            'Keep past events tidy.',
        ],
        'dont' => [
            'Ask for or collect money for an event.',
            'Post a private meeting link publicly unless the event is open to everyone.',
            'Publish or change other content on the site.',
        ],
    ],
    'prompts' => [
        'title' => 'Writing prompts curator', 'icon' => 'fa-feather-pointed', 'cap' => 'prompts_team',
        'summary' => 'Write and schedule the writing prompts that invite members to write.',
        'time' => 'About 30 minutes a week',
        'pages' => [['Writing prompts', 'admin/prompts.php']],
        'do' => [
            'Write clear, encouraging prompts that fit the AngelWrites voice.',
            'Schedule prompts ahead so a new one is always ready.',
            'Fix typos and mistakes in prompts.',
        ],
        'dont' => [
            'Edit or remove members’ responses.',
            'Publish poems, books or blog posts.',
        ],
    ],
    'devotionals' => [
        'title' => 'Devotional plans editor', 'icon' => 'fa-book-bible', 'cap' => 'devotionals_team',
        'summary' => 'Prepare devotional reading plans: daily readings, verses and short reflections.',
        'time' => 'About an hour a week',
        'pages' => [['Devotional plans', 'admin/devotionals.php']],
        'do' => [
            'Draft and edit devotional plans and their daily readings.',
            'Check every Bible reference before saving.',
            'Publish a plan only when the owner has agreed to it.',
        ],
        'dont' => [
            'Copy text from books or Bible versions the site has no permission to use.',
            'Change poems, books, blog posts or other pages.',
        ],
    ],
    'translations' => [
        'title' => 'Translator', 'icon' => 'fa-language', 'cap' => 'translations_team',
        'summary' => 'Translate the site’s buttons, menus and messages so more people can use AngelWrites in their own language.',
        'time' => 'Whenever suits you',
        'pages' => [['Translations', 'admin/translations.php']],
        'do' => [
            'Translate the site’s words faithfully and naturally.',
            'Keep the same meaning, tone and length where you can.',
            'Tell the owner about anything that is hard to translate.',
        ],
        'dont' => [
            'Change the English wording of pages.',
            'Translate poems or books without the author’s permission.',
        ],
    ],
    'mentoring' => [
        'title' => 'Mentoring coordinator', 'icon' => 'fa-hands-holding-circle', 'cap' => 'mentoring_team',
        'summary' => 'Welcome new mentors and match members who ask for a mentor with someone suitable.',
        'time' => 'About 30 minutes a week',
        'pages' => [['Mentoring', 'admin/mentoring.php']],
        'do' => [
            'Review offers to mentor and approve people who are a good fit.',
            'Match members who ask for a mentor, and check how the match is going.',
            'Pass any concern about safety or behaviour to the owner at once.',
        ],
        'dont' => [
            'Share anyone’s contact details beyond what the match needs.',
            'Arrange private meetings with young people.',
            'Give counselling beyond what the role is for.',
        ],
    ],
    'clubs' => [
        'title' => 'Book club host', 'icon' => 'fa-people-group', 'cap' => 'clubs_team',
        'summary' => 'Look after reading groups: set up groups, welcome members and keep discussions kind.',
        'time' => 'About an hour a week',
        'pages' => [['Groups & book clubs', 'admin/manage_groups.php']],
        'do' => [
            'Create and look after reading groups and their reading schedules.',
            'Welcome new members and encourage good discussion.',
            'Remove spam or unkind posts in groups, and tell the owner about anything serious.',
        ],
        'dont' => [
            'Remove members from a group without a good reason.',
            'Share group conversations outside the group.',
            'Change other parts of the site.',
        ],
    ],
];

const AW_INVITE_DAYS = 30; // an invitation not answered by then expires

// ===== areas ======================================================================================

function aw_staff_tables(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS staff_areas (
            user_id INTEGER NOT NULL,
            area TEXT NOT NULL,
            granted_by INTEGER,
            granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, area)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS staff_invites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            position TEXT NOT NULL,
            note TEXT,
            invited_by INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            responded_at DATETIME
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_staff_invites_user ON staff_invites(user_id, status)");
        return $ok = true;
    } catch (Throwable $e) {
        error_log('staff tables: ' . $e->getMessage());
        return $ok = false;
    }
}

/** A user's areas (position keys), e.g. ['prayer', 'events']. */
function aw_user_areas($uid) {
    static $cache = [];
    $uid = (int)$uid;
    if (isset($cache[$uid])) return $cache[$uid];
    $db = $GLOBALS['db'] ?? null;
    if ($uid <= 0 || !$db instanceof PDO || !aw_staff_tables($db)) return $cache[$uid] = [];
    try {
        $st = $db->prepare("SELECT area FROM staff_areas WHERE user_id = ? ORDER BY granted_at");
        $st->execute([$uid]);
        return $cache[$uid] = array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN), function ($a) { return isset(AW_POSITIONS[$a]); }));
    } catch (Throwable $e) {
        error_log('aw_user_areas: ' . $e->getMessage());
        return $cache[$uid] = [];
    }
}

/** The capabilities a user's areas grant. */
function aw_area_caps($uid) {
    $caps = [];
    foreach (aw_user_areas($uid) as $a) if (empty(AW_POSITIONS[$a]['role'])) $caps[] = AW_POSITIONS[$a]['cap'];
    return $caps;
}

function aw_area_grant(PDO $db, $uid, $area, $by) {
    if (!isset(AW_POSITIONS[$area]) || !empty(AW_POSITIONS[$area]['role']) || !aw_staff_tables($db)) return false;
    try {
        $db->prepare("INSERT OR IGNORE INTO staff_areas (user_id, area, granted_by) VALUES (?, ?, ?)")->execute([(int)$uid, $area, (int)$by]);
        return true;
    } catch (Throwable $e) { error_log('aw_area_grant: ' . $e->getMessage()); return false; }
}

function aw_area_revoke(PDO $db, $uid, $area) {
    if (!aw_staff_tables($db)) return false;
    try {
        $db->prepare("DELETE FROM staff_areas WHERE user_id = ? AND area = ?")->execute([(int)$uid, (string)$area]);
        if (function_exists('aw_audit')) aw_audit('staff_area_ended', 'user', (int)$uid, (string)$area);
        return true;
    } catch (Throwable $e) { error_log('aw_area_revoke: ' . $e->getMessage()); return false; }
}

/** Everyone holding an area, with their names: [['user_id','area','granted_at','name'...]]. */
function aw_area_holders(PDO $db) {
    if (!aw_staff_tables($db)) return [];
    try {
        if (function_exists('aw_profile_columns')) aw_profile_columns($db);
        return $db->query("SELECT a.user_id, a.area, a.granted_at, u.username, u.name, u.first_name, u.last_name, u.email, u.contact_number, u.whatsapp_number, u.contact_ok
                           FROM staff_areas a JOIN users u ON u.id = a.user_id ORDER BY a.granted_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('aw_area_holders: ' . $e->getMessage()); return []; }
}

// ===== invitations =================================================================================

/** The invitation, written for the position: ['subject', 'html']. Names are plain text here. */
function aw_invite_message($position, $to_name, $from_name, $note = '') {
    $p = AW_POSITIONS[$position] ?? null;
    if (!$p) return ['subject' => '', 'html' => ''];
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $li = function (array $items) use ($e) { return '<ul>' . implode('', array_map(function ($x) use ($e) { return '<li>' . $e($x) . '</li>'; }, $items)) . '</ul>'; };
    $pages = implode(', ', array_map(function ($pg) { return $pg[0]; }, $p['pages']));
    $html = '<p>Dear ' . $e($to_name) . ',</p>'
          . '<p>' . $e($from_name) . ' invites you to volunteer on the AngelWrites team as <strong>' . $e($p['title']) . '</strong>. '
          . $e($p['summary']) . '</p>'
          . (trim((string)$note) !== '' ? '<blockquote style="margin:12px 0;padding:10px 14px;border-left:3px solid #DBA1A2;background:#fdf6f6">' . nl2br($e(trim((string)$note))) . '<br>— ' . $e($from_name) . '</blockquote>' : '')
          . '<h3>What you would do</h3>' . $li($p['do'])
          . '<h3>What this role does not include</h3>' . $li($p['dont'])
          . '<h3>For everyone on the team</h3>' . $li(AW_STAFF_RULES)
          . '<p><strong>Time:</strong> ' . $e($p['time']) . '. <strong>Where:</strong> your Team desk and ' . $e($pages) . '.</p>'
          . '<p>This is a volunteer role, and saying no is completely fine. Nothing changes until you accept. The invitation stays open for ' . AW_INVITE_DAYS . ' days.</p>';
    return ['subject' => $from_name . ' invites you to the AngelWrites team: ' . $p['title'], 'html' => $html];
}

/** Pending invitation for this user and position, if any. */
function aw_invite_pending(PDO $db, $uid, $position = null) {
    if (!aw_staff_tables($db)) return null;
    try {
        $sql = "SELECT * FROM staff_invites WHERE user_id = ? AND status = 'pending' AND created_at >= datetime('now', ?)" . ($position !== null ? " AND position = ?" : '') . " ORDER BY id DESC LIMIT 1";
        $st = $db->prepare($sql);
        $args = [(int)$uid, '-' . AW_INVITE_DAYS . ' days'];
        if ($position !== null) $args[] = (string)$position;
        $st->execute($args);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('aw_invite_pending: ' . $e->getMessage()); return null; }
}

/** Does this user already hold the position? */
function aw_holds_position(PDO $db, $uid, $position) {
    $p = AW_POSITIONS[$position] ?? null;
    if (!$p) return false;
    if (!empty($p['role'])) {
        $role = function_exists('aw_role_of') ? aw_role_of((int)$uid) : '';
        return in_array($role, ['owner', 'admin', 'moderator'], true);
    }
    return in_array($position, aw_user_areas($uid), true);
}

/**
 * Send an invitation: saved, a notification on the site and an email with a button to answer.
 * Returns ['ok' => bool, 'msg' => string, 'emailed' => bool].
 */
function aw_invite_send(PDO $db, $uid, $position, $note, $by) {
    $uid = (int)$uid; $by = (int)$by; $note = trim(mb_substr((string)$note, 0, 1000));
    if (!isset(AW_POSITIONS[$position])) return ['ok' => false, 'msg' => 'Please choose a position.', 'emailed' => false];
    if (!aw_staff_tables($db)) return ['ok' => false, 'msg' => 'The invitation could not be saved. Please try again.', 'emailed' => false];
    try {
        $st = $db->prepare("SELECT id, username, name, first_name, last_name, email, role FROM users WHERE id = ?");
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('aw_invite_send: ' . $e->getMessage()); $u = false; }
    if (!$u) return ['ok' => false, 'msg' => 'That member was not found.', 'emailed' => false];
    if ($u['role'] === 'admin') return ['ok' => false, 'msg' => 'That person is already an admin and can do all of this.', 'emailed' => false];
    // safeguarding: under-18s never get roles with private contact with adults
    if (in_array($position, ['moderator', 'inbox', 'mentoring', 'community', 'prayer'], true)) {
        try { $ar = $db->prepare("SELECT age_range FROM users WHERE id = ?"); $ar->execute([$uid]); if ((string)$ar->fetchColumn() === '13-17') return ['ok' => false, 'msg' => 'This member is under 18. This role involves private contact with adults, so it cannot be offered to them.', 'emailed' => false]; }
        catch (Throwable $e) { /* no age column yet: nothing to check */ }
    }
    if (aw_holds_position($db, $uid, $position)) return ['ok' => false, 'msg' => 'That member already has this role.', 'emailed' => false];
    if (aw_invite_pending($db, $uid, $position)) return ['ok' => false, 'msg' => 'That member already has an open invitation for this role.', 'emailed' => false];
    try {
        $db->prepare("INSERT INTO staff_invites (user_id, position, note, invited_by) VALUES (?, ?, ?, ?)")->execute([$uid, $position, $note, $by]);
        $id = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log('aw_invite_send insert: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'The invitation could not be saved. Please try again.', 'emailed' => false];
    }
    $from = function_exists('awDisplayName') ? awDisplayName(aw_staff_user_row($db, $by)) : 'The AngelWrites team';
    $to = function_exists('awDisplayName') ? awDisplayName($u) : (string)$u['username'];
    $title = AW_POSITIONS[$position]['title'];
    $link = SITE_URL . '/staff_invite.php?id=' . $id;
    if (function_exists('awNotify')) awNotify($db, $uid, 'staff_invite', 'You are invited to the team: ' . $title, $from . ' invites you to volunteer as ' . $title . '. Open it to read what the role involves.', $link, ['email' => false]);
    $emailed = false;
    if (!empty($u['email']) && function_exists('sendConversationalEmail')) {
        $m = aw_invite_message($position, $to, $from, $note);
        $emailed = (bool)sendConversationalEmail($u['email'], $m['subject'], $m['html'], $link, 'Read and answer the invitation');
    }
    if (function_exists('aw_audit')) aw_audit('staff_invited', 'user', $uid, $position . ($emailed ? ' emailed' : ' not emailed'));
    return ['ok' => true, 'msg' => 'Invitation sent to ' . $to . ' as ' . $title . '.' . ($emailed ? ' They were emailed too.' : ' They will see it on the site (the email could not be sent).'), 'emailed' => $emailed, 'id' => $id];
}

function aw_staff_user_row(PDO $db, $uid) {
    try { $st = $db->prepare("SELECT id, username, name, first_name, last_name, role FROM users WHERE id = ?"); $st->execute([(int)$uid]); return $st->fetch(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { error_log('aw_staff_user_row: ' . $e->getMessage()); return []; }
}

function aw_invite_get(PDO $db, $id) {
    if (!aw_staff_tables($db)) return null;
    try { $st = $db->prepare("SELECT * FROM staff_invites WHERE id = ?"); $st->execute([(int)$id]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
    catch (Throwable $e) { error_log('aw_invite_get: ' . $e->getMessage()); return null; }
}

function aw_invite_expired(array $inv) {
    return $inv['status'] === 'pending' && strtotime((string)$inv['created_at'] . ' UTC') < time() - AW_INVITE_DAYS * 86400;
}

/**
 * The invited member answers. Only they can; only a pending, unexpired invitation.
 * Accepting gives the role or the area. Returns ['ok' => bool, 'msg' => string].
 */
function aw_invite_answer(PDO $db, $id, $uid, $accept) {
    $inv = aw_invite_get($db, $id);
    if (!$inv || (int)$inv['user_id'] !== (int)$uid) return ['ok' => false, 'msg' => 'This invitation was not found.'];
    if ($inv['status'] !== 'pending') return ['ok' => false, 'msg' => 'This invitation has already been answered or withdrawn.'];
    if (aw_invite_expired($inv)) return ['ok' => false, 'msg' => 'This invitation has expired. Ask the owner to send a new one if you would still like to help.'];
    $pos = AW_POSITIONS[$inv['position']] ?? null;
    if (!$pos) return ['ok' => false, 'msg' => 'This role no longer exists.'];
    try {
        $db->beginTransaction();
        if ($accept) {
            if (!empty($pos['role'])) {
                $db->prepare("UPDATE users SET role = 'moderator' WHERE id = ? AND role NOT IN ('admin', 'moderator')")->execute([(int)$uid]);
                if ((int)($_SESSION['user_id'] ?? 0) === (int)$uid && ($_SESSION['role'] ?? '') !== 'admin') $_SESSION['role'] = 'moderator';
            } else {
                $db->prepare("INSERT OR IGNORE INTO staff_areas (user_id, area, granted_by) VALUES (?, ?, ?)")->execute([(int)$uid, $inv['position'], (int)$inv['invited_by']]);
            }
        }
        $db->prepare("UPDATE staff_invites SET status = ?, responded_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'")->execute([$accept ? 'accepted' : 'declined', (int)$id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('aw_invite_answer: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'Your answer could not be saved. Please try again.'];
    }
    if (function_exists('aw_audit')) aw_audit($accept ? 'staff_invite_accepted' : 'staff_invite_declined', 'user', (int)$uid, $inv['position']);
    if (function_exists('awNotify')) {
        $me = function_exists('awDisplayName') ? awDisplayName(aw_staff_user_row($db, $uid)) : 'A member';
        awNotify($db, (int)$inv['invited_by'], 'staff_invite_answer', $me . ($accept ? ' accepted' : ' declined') . ' the invitation: ' . $pos['title'],
            $accept ? 'They can now use their Team desk.' : 'Thank you for asking them.', SITE_URL . '/admin/invite_staff.php', ['email' => false, 'actor' => (int)$uid]);
    }
    return ['ok' => true, 'msg' => $accept ? 'Welcome to the team! Your Team desk is ready.' : 'Thank you for letting us know. The invitation has been declined.'];
}

/** The owner or an admin withdraws an open invitation. */
function aw_invite_withdraw(PDO $db, $id) {
    if (!aw_staff_tables($db)) return false;
    try {
        $st = $db->prepare("UPDATE staff_invites SET status = 'withdrawn', responded_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'");
        $st->execute([(int)$id]);
        if ($st->rowCount() && function_exists('aw_audit')) aw_audit('staff_invite_withdrawn', 'invite', (int)$id, '');
        return $st->rowCount() > 0;
    } catch (Throwable $e) { error_log('aw_invite_withdraw: ' . $e->getMessage()); return false; }
}

/** Recent invitations for the admin page. */
function aw_invite_list(PDO $db, $limit = 50) {
    if (!aw_staff_tables($db)) return [];
    try {
        $st = $db->prepare("SELECT i.*, u.username, u.name, u.first_name, u.last_name, b.username AS by_username, b.name AS by_name, b.first_name AS by_first, b.last_name AS by_last
                            FROM staff_invites i JOIN users u ON u.id = i.user_id LEFT JOIN users b ON b.id = i.invited_by
                            ORDER BY i.id DESC LIMIT ?");
        $st->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('aw_invite_list: ' . $e->getMessage()); return []; }
}
