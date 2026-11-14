<?php
/**
 * K1: roles and capabilities (PROGRAM_PLAN.md Phase 2, wish K).
 *
 * Roles (users.role):
 *   owner      the admin account named in settings.owner_user_id, or the first admin if none is set.
 *              Everything, plus the staff screen (who is staff, and as what).
 *   admin      content, members, newsletter, payments, settings, backups, moderation, team inbox.
 *   moderator  the moderation queue, comments, community Q&A, testimonials, prayer requests and the
 *              team inbox. Never settings, roles, backups, payments, members or the newsletter.
 *   (anything else is a member)
 *
 * Owner's answers (PROGRAM_PLAN.md §6): moderators are Angella Bottoman (owner) and Blessings Emulyn;
 * real identities behind pseudonyms are visible to the owner and admins only (capability 'see_identities').
 *
 * The switch: settings.roles_enabled = '0' turns this off, and every staff page falls back to the old
 * rule (admins only). Default is on.
 *
 * Every function returns a value and logs on failure; nothing throws.
 */

const AW_CAPS = [
    'staff_dashboard'  => ['owner', 'admin', 'moderator'],
    'moderate'         => ['owner', 'admin', 'moderator'], // reports, comments, Q&A, testimonials, prayer requests
    'team_inbox'       => ['owner', 'admin', 'moderator'],
    'manage_content'   => ['owner', 'admin'],              // books, poems, blog, reflections, videos, groups, picks
    'manage_members'   => ['owner', 'admin'],
    'manage_newsletter'=> ['owner', 'admin'],
    'manage_payments'  => ['owner', 'admin'],
    'manage_settings'  => ['owner', 'admin'],
    'backup'           => ['owner', 'admin'],
    'see_identities'   => ['owner', 'admin'],
    'audit_log'        => ['owner', 'admin'],
    'manage_staff'     => ['owner'],
];

function aw_roles_enabled() {
    return function_exists('aw_setting') ? (string)aw_setting('roles_enabled', '1') !== '0' : true;
}

/** The owner's user id: settings.owner_user_id, else the first admin. 0 if there is none. */
function aw_owner_id() {
    static $id = null;
    if ($id !== null) return $id;
    $id = 0;
    $set = function_exists('aw_setting') ? (int)aw_setting('owner_user_id', 0) : 0;
    $db = $GLOBALS['db'] ?? null;
    if (!$db instanceof PDO) return $id = $set;
    try {
        if ($set > 0) {
            $st = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin'");
            $st->execute([$set]);
            if ($st->fetchColumn()) return $id = $set;
        }
        $id = (int)$db->query("SELECT MIN(id) FROM users WHERE role = 'admin'")->fetchColumn();
    } catch (Throwable $e) {
        error_log('aw_owner_id: ' . $e->getMessage());
    }
    return $id;
}

/** The effective role of a user row or id: owner, admin, moderator or member. */
function aw_role_of($user) {
    $role = is_array($user) ? (string)($user['role'] ?? '') : '';
    $uid = is_array($user) ? (int)($user['id'] ?? 0) : (int)$user;
    if (!is_array($user)) {
        if ($uid === (int)($_SESSION['user_id'] ?? -1)) {
            $role = (string)($_SESSION['role'] ?? '');
        } else {
            $db = $GLOBALS['db'] ?? null;
            try {
                if ($db instanceof PDO) { $st = $db->prepare("SELECT role FROM users WHERE id = ?"); $st->execute([$uid]); $role = (string)$st->fetchColumn(); }
            } catch (Throwable $e) { error_log('aw_role_of: ' . $e->getMessage()); }
        }
    }
    if ($role === 'admin') return ($uid > 0 && $uid === aw_owner_id()) ? 'owner' : 'admin';
    if ($role === 'moderator') return 'moderator';
    return 'member';
}

/** The signed-in person's role. */
function aw_my_role() {
    if (empty($_SESSION['user_id'])) return 'guest';
    return aw_role_of((int)$_SESSION['user_id']);
}

/** Can the signed-in person do this? With roles switched off, only admins can do anything staff-only. */
function aw_can($cap) {
    if (empty($_SESSION['user_id'])) return false;
    if (!aw_roles_enabled()) return ($_SESSION['role'] ?? '') === 'admin';
    $role = aw_my_role();
    return in_array($role, AW_CAPS[$cap] ?? [], true);
}

function aw_is_staff() { return aw_can('staff_dashboard'); }

/**
 * For staff pages: sign-in required, then the capability. Members and guests are sent away;
 * a staff member without the capability gets a plain "not for your role" page (HTTP 403).
 * Also applies the staff request guard (aw_staff_request_guard).
 */
function aw_require($cap) {
    if (function_exists('redirectIfNotLoggedIn')) redirectIfNotLoggedIn();
    if (!aw_is_staff()) {
        header('Location: ' . SITE_URL . '/index.php');
        exit;
    }
    if (!aw_can($cap)) {
        aw_audit('access_denied', 'page', 0, $cap . ' ' . ($_SERVER['REQUEST_URI'] ?? ''));
        http_response_code(403);
        $pageTitle = 'Not available';
        if (!headers_sent()) header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Not available</title>'
           . '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:15vh auto;padding:0 16px;text-align:center;color:#3d2e2e">'
           . '<h1 style="font-family:Georgia,serif">This page is not part of your role</h1>'
           . '<p>Your account can moderate and answer the team inbox, but this page is for admins. If you need it, ask the owner.</p>'
           . '<p><a href="' . htmlspecialchars(SITE_URL) . '/admin/dashboard.php" style="color:#a8676a">Back to the staff dashboard</a></p></div>';
        exit;
    }
    aw_staff_request_guard();
    require_once __DIR__ . '/twofactor.php';
    aw_2fa_enforce(); // K5: when the owner requires two-step sign-in for staff
}

/**
 * K3: a guard for every staff request.
 *  - A POST must carry this session's CSRF token (form field csrf_token, or header X-CSRF-Token,
 *    which the staff pages' script adds to every form and same-site fetch).
 *  - A GET that would change something (?delete=, ?approve= …) must not come from another site
 *    (Sec-Fetch-Site), so a link on another page cannot act on an admin's behalf.
 */
function aw_staff_request_guard() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        $sent = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if ($sent === '' || !hash_equals((string)$_SESSION['csrf_token'], $sent)) {
            aw_audit('csrf_refused', 'page', 0, $_SERVER['REQUEST_URI'] ?? '');
            http_response_code(403);
            $json = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
            if ($json) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Your session expired. Please reload the page.']); }
            else echo '<!doctype html><meta charset="utf-8"><p style="font-family:system-ui;max-width:520px;margin:15vh auto;padding:0 16px">Your session expired, so nothing was changed. <a href="javascript:history.back()">Go back</a>, reload the page and try again.</p>';
            exit;
        }
        aw_audit_request('POST', $_POST);
    } elseif ($method === 'GET') {
        $acting = array_intersect(array_keys($_GET), ['delete', 'publish', 'unpublish', 'approve', 'reject', 'toggle', 'remove', 'ban', 'unban',
                                                     'verify', 'feature', 'unfeature', 'mark_read', 'archive', 'restore', 'resolve', 'dismiss', 'confirm', 'cancel']);
        $site = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
        if ($acting && ($site === 'cross-site')) {
            aw_audit('cross_site_refused', 'page', 0, $_SERVER['REQUEST_URI'] ?? '');
            http_response_code(403);
            echo '<!doctype html><meta charset="utf-8"><p style="font-family:system-ui;max-width:520px;margin:15vh auto;padding:0 16px">That link came from another website, so nothing was changed. Open the admin page directly and try again.</p>';
            exit;
        }
        if ($acting) aw_audit_request('GET', $_GET);
    }
}

/**
 * Every staff request that changes something is logged: the page, which actions were sent and the
 * ids involved. Never the text that was typed (messages, poems, passwords stay out of the log).
 */
function aw_audit_request($method, array $params) {
    $page = basename(parse_url((string)($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH) ?: '');
    $skip = ['csrf_token', 'password', 'current_password', 'new_password', 'confirm_password', 'content', 'body', 'message', 'reply',
             'comment', 'intro', 'description', 'html', 'text', 'subject', 'testimony', 'notes', 'excerpt', 'bio'];
    $bits = [];
    foreach ($params as $k => $v) {
        if (in_array($k, $skip, true)) continue;
        if (is_array($v)) { $ids = array_filter($v, 'is_numeric'); $bits[] = $k . '=[' . implode(',', array_slice($ids, 0, 30)) . ']'; continue; }
        $v = (string)$v;
        // ids, short action words and statuses only
        if (preg_match('/^(id|.*_id|ids|action|bulk_action|row_action|status|role|tab|delete|approve|reject|publish|unpublish|toggle|.*_action)$/', $k) || preg_match('/^[a-z_]{1,24}$/', $k) && strlen($v) <= 24 && preg_match('/^[A-Za-z0-9_:,.-]*$/', $v)) {
            $bits[] = $k . '=' . substr($v, 0, 40);
        }
    }
    aw_audit('staff_' . strtolower($method), 'page', null, $page . ($bits ? ' ' . implode(' ', $bits) : ''));
}

/** The script staff pages load so every POST form and same-site fetch carries the CSRF token. */
function aw_staff_csrf_script() {
    if (!aw_is_staff() || empty($_SESSION['csrf_token'])) return '';
    $t = htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES);
    return '<meta name="aw-csrf" content="' . $t . '"><script>(function(){var t=document.querySelector(\'meta[name="aw-csrf"]\').content;'
        . 'function add(f){if(!f||(f.method||"").toLowerCase()!=="post"||f.querySelector(\'input[name="csrf_token"]\'))return;var i=document.createElement("input");i.type="hidden";i.name="csrf_token";i.value=t;f.appendChild(i);}'
        . 'document.addEventListener("submit",function(e){add(e.target);},true);'
        . 'var sub=HTMLFormElement.prototype.submit;HTMLFormElement.prototype.submit=function(){add(this);return sub.call(this);};'
        . 'var f=window.fetch;if(f)window.fetch=function(u,o){o=o||{};try{var url=new URL(u instanceof Request?u.url:u,location.href);if(url.origin===location.origin){o.headers=new Headers(o.headers||{});if(!o.headers.has("X-CSRF-Token"))o.headers.set("X-CSRF-Token",t);}}catch(e){}return f.call(this,u,o);};'
        . 'var x=XMLHttpRequest.prototype.open,s=XMLHttpRequest.prototype.send;XMLHttpRequest.prototype.open=function(m,u){this._aw=new URL(u,location.href).origin===location.origin;return x.apply(this,arguments);};'
        . 'XMLHttpRequest.prototype.send=function(){if(this._aw)try{this.setRequestHeader("X-CSRF-Token",t);}catch(e){}return s.apply(this,arguments);};})();</script>';
}

// ===== AUDIT LOG ====================================================================

function aw_audit_table(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER,
            actor_role TEXT,
            action TEXT NOT NULL,
            target_type TEXT,
            target_id INTEGER,
            details TEXT,
            ip TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_created ON audit_log(created_at)");
        return $ok = true;
    } catch (Throwable $e) {
        error_log('audit table: ' . $e->getMessage());
        return $ok = false;
    }
}

/** Record who did what. Returns true when written. */
function aw_audit($action, $target_type = null, $target_id = null, $details = '') {
    $db = $GLOBALS['db'] ?? null;
    if (!$db instanceof PDO || !aw_audit_table($db)) return false;
    try {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $db->prepare("INSERT INTO audit_log (actor_id, actor_role, action, target_type, target_id, details, ip) VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([$uid ?: null, $uid ? aw_my_role() : 'guest', (string)$action, $target_type, $target_id === null ? null : (int)$target_id,
                      function_exists('mb_substr') ? mb_substr((string)$details, 0, 1000) : substr((string)$details, 0, 1000),
                      substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        return true;
    } catch (Throwable $e) {
        error_log('aw_audit: ' . $e->getMessage());
        return false;
    }
}

/** A role's name for people. */
function aw_role_label($role) {
    return ['owner' => 'Owner', 'admin' => 'Admin', 'moderator' => 'Moderator', 'member' => 'Member', 'guest' => 'Guest'][$role] ?? 'Member';
}
