<?php
/**
 * Members: find people, see who still has to verify, verify by hand, message or delete.
 *
 * Redesigned as a list of member cards (no wide table): every action, Delete included, is always in
 * view on any screen, with no sideways scrolling.
 *
 * What changed and why:
 *  - Bulk actions work. The old bulk form wrapped the table and each row's role picker was a form
 *    inside it; browsers drop nested forms, so a bulk "Apply" sent a role change instead.
 *  - Roles are no longer changed here. "Promote to admin" let any admin make admins or demote the
 *    owner; staff roles belong to the owner's Staff & roles page (admin/staff.php), linked from here.
 *  - Every change is a POST with the CSRF token (added by the staff script), deletes included, and
 *    the result is shown after the redirect.
 *  - Owner and admin accounts are never deleted here (their writing would go with them).
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/member_profile.php'; // contact buttons and details
require_once '../includes/admin_bulk.php'; // safe deletes, results after the redirect
require_once '../includes/mail_helper.php';

aw_require('manage_members'); // K1 (checks the CSRF token on every POST)

$error = '';
$success = '';
$mu_me = (int)$_SESSION['user_id'];

/** Delete one member. Returns [ok, message]. */
function aw_mu_delete(PDO $db, $id, $me) {
    $id = (int)$id;
    if ($id === (int)$me) return [false, 'You cannot delete your own account.'];
    $st = $db->prepare("SELECT id, name, first_name, last_name, username, email, role FROM users WHERE id = ?");
    $st->execute([$id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return [false, 'That account was already deleted.'];
    if (in_array(aw_role_of($u), ['owner', 'admin'], true)) return [false, awDisplayName($u) . ' is an admin. Admin and owner accounts are not deleted here: make them a member in Staff & roles first.'];
    $r = aw_safe_delete($db, 'users', $id); // tidies everything that pointed at the member
    if (empty($r['ok'])) return [false, awDisplayName($u) . ' could not be deleted just now: ' . ($r['error'] ?? 'please try again.')];
    if (function_exists('aw_audit')) aw_audit('user_delete', 'user', $id, (string)$u['email']);
    return [true, awDisplayName($u)];
}

// ===== ACTIONS (all POST) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['bulk_action'] ?? $_POST['row_action'] ?? '');
    $ids = isset($_POST['row_id']) ? aw_bulk_ids('row_id') : aw_bulk_ids();
    if (!$ids) aw_bulk_done('manage_users', '', 'Select at least one member.');

    if ($act === 'delete') {
        $done = []; $problems = [];
        foreach ($ids as $id) { [$ok, $msg] = aw_mu_delete($db, $id, $mu_me); if ($ok) $done[] = $msg; else $problems[] = $msg; }
        if ($done) {
            $body = '<h2>Member account' . (count($done) > 1 ? 's' : '') . ' deleted</h2><p>' . implode('<br>', array_map('htmlspecialchars', $done)) . '</p>';
            @sendEmail(aw_admin_email(), count($done) === 1 ? 'Member deleted: ' . $done[0] : count($done) . ' members deleted', $body, 'angelwrites@zohomail.com', 'AngelWrites');
        }
        aw_bulk_done('manage_users', $done ? (count($done) === 1 ? $done[0] . ' was deleted.' : count($done) . ' members were deleted.') : '', implode(' ', $problems));
    }
    if ($act === 'verify') {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("UPDATE users SET is_verified = 1, verification_code = NULL, verification_code_expiry = NULL, updated_at = CURRENT_TIMESTAMP WHERE is_verified = 0 AND id IN ($in)");
        $st->execute($ids);
        $n = $st->rowCount();
        aw_bulk_done('manage_users', $n ? aw_bulk_n($n, 'member') . ' marked as verified.' : 'Everyone selected was already verified.');
    }
    aw_bulk_done('manage_users', '', 'Choose an action.');
}
aw_bulk_take('manage_users', $success, $error);

// ===== THE LIST =====
$mu_q = trim((string)($_GET['search'] ?? ''));
$mu_filter = (string)($_GET['filter'] ?? '');
if (!in_array($mu_filter, ['unverified', 'staff', 'members', 'new'], true)) $mu_filter = '';
$mu_per = 20;
$mu_page = max(1, (int)($_GET['page'] ?? 1));

$mu_where = []; $mu_args = [];
if ($mu_filter === 'unverified') $mu_where[] = "is_verified = 0 AND role NOT IN ('admin', 'moderator')";
if ($mu_filter === 'staff') $mu_where[] = "role IN ('admin', 'moderator')";
if ($mu_filter === 'members') $mu_where[] = "role NOT IN ('admin', 'moderator')";
if ($mu_filter === 'new') $mu_where[] = "created_at >= datetime('now', '-30 days')";
aw_profile_columns($db); aw_staff_tables($db);
if ($mu_filter === 'staff') $mu_where[count($mu_where) - 1] = "(role IN ('admin', 'moderator') OR id IN (SELECT user_id FROM staff_areas))"; // volunteers are on the team too
if ($mu_q !== '') {
    // phone numbers are matched by their digits, so "0991 234 567" finds +265991234567
    $mu_digits = preg_replace('/\D/', '', $mu_q);
    $mu_where[] = "(name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR username LIKE ? OR country LIKE ? OR city LIKE ?"
                . (strlen($mu_digits) >= 5 ? " OR REPLACE(contact_number, '+', '') LIKE ? OR REPLACE(whatsapp_number, '+', '') LIKE ?" : '') . ")";
    array_push($mu_args, ...array_fill(0, 7, '%' . $mu_q . '%'));
    if (strlen($mu_digits) >= 5) array_push($mu_args, '%' . ltrim($mu_digits, '0') . '%', '%' . ltrim($mu_digits, '0') . '%');
}
$mu_sql_where = $mu_where ? ' WHERE ' . implode(' AND ', $mu_where) : '';
$mu_counts = ['all' => 0, 'unverified' => 0, 'staff' => 0, 'members' => 0, 'new' => 0];
$mu_total = 0; $mu_rows = [];
try {
    $c = $db->query("SELECT COUNT(*) AS a,
                            SUM(CASE WHEN is_verified = 0 AND role NOT IN ('admin','moderator') THEN 1 ELSE 0 END) AS u,
                            SUM(CASE WHEN role IN ('admin','moderator') OR id IN (SELECT user_id FROM staff_areas) THEN 1 ELSE 0 END) AS s,
                            SUM(CASE WHEN created_at >= datetime('now', '-30 days') THEN 1 ELSE 0 END) AS n FROM users")->fetch(PDO::FETCH_ASSOC);
    $mu_counts = ['all' => (int)$c['a'], 'unverified' => (int)$c['u'], 'staff' => (int)$c['s'], 'members' => (int)$c['a'] - (int)$c['s'], 'new' => (int)$c['n']];
    $st = $db->prepare("SELECT COUNT(*) FROM users" . $mu_sql_where);
    $st->execute($mu_args);
    $mu_total = (int)$st->fetchColumn();
    $mu_pages = max(1, (int)ceil($mu_total / $mu_per));
    $mu_page = min($mu_page, $mu_pages);
    aw_profile_columns($db);
    $st = $db->prepare("SELECT id, name, first_name, last_name, username, email, role, country, is_verified, created_at, last_login, avatar, profile_pic, social_provider,
                               contact_number, whatsapp_number, contact_ok, age_range, gender, city
                        FROM users" . $mu_sql_where . " ORDER BY created_at DESC, id DESC LIMIT " . $mu_per . " OFFSET " . (($mu_page - 1) * $mu_per));
    $st->execute($mu_args);
    $mu_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('manage users list: ' . $e->getMessage());
    $error = $error ?: 'The member list could not be loaded just now.';
}
$mu_pages = $mu_pages ?? 1;
$mu_link = function (array $over = []) use ($mu_q, $mu_filter, $mu_page) {
    $q = array_filter(array_merge(['search' => $mu_q, 'filter' => $mu_filter, 'page' => $mu_page > 1 ? $mu_page : ''], $over), function ($v) { return $v !== '' && $v !== null; });
    return SITE_URL . '/admin/manage_users.php' . ($q ? '?' . http_build_query($q) : '');
};
$mu_ago = function ($when) {
    $t = $when ? strtotime((string)$when) : 0;
    if (!$t) return 'never';
    $d = time() - $t;
    if ($d < 3600) return 'just now';
    if ($d < 86400) return floor($d / 3600) . ' h ago';
    if ($d < 86400 * 30) return floor($d / 86400) . ' d ago';
    return date('j M Y', $t);
};
$mu_can_staff = aw_can('manage_staff');

$pageTitle = 'Members';
require_once '../includes/header.php';
?>
<div class="mu">
    <header class="mu-head">
        <div>
            <p class="mu-kicker">Community</p>
            <h1>Members</h1>
            <p class="mu-lead"><?php echo number_format($mu_counts['all']); ?> accounts · <?php echo number_format($mu_counts['new']); ?> joined in the last 30 days</p>
        </div>
        <div class="mu-head-acts">
            <?php if ($mu_can_staff): ?><a class="mu-btn" href="<?php echo SITE_URL; ?>/admin/staff.php"><i class="fas fa-user-shield" aria-hidden="true"></i> Team &amp; roles</a><?php endif; ?>
            <a class="mu-btn" href="<?php echo SITE_URL; ?>/admin/dashboard.php"><i class="fas fa-arrow-left" aria-hidden="true"></i> Dashboard</a>
        </div>
    </header>

    <?php if ($error): ?><div class="mu-alert is-error" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="mu-alert is-ok" role="status"><i class="fas fa-circle-check" aria-hidden="true"></i> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="mu-tools">
        <form method="get" class="mu-search" role="search">
            <?php if ($mu_filter): ?><input type="hidden" name="filter" value="<?php echo htmlspecialchars($mu_filter); ?>"><?php endif; ?>
            <label class="sr-only" for="muQ">Search members</label>
            <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" id="muQ" name="search" value="<?php echo htmlspecialchars($mu_q); ?>" placeholder="Name, email, username or country">
            <button type="submit" class="mu-btn mu-primary">Search</button>
            <?php if ($mu_q !== ''): ?><a class="mu-btn" href="<?php echo htmlspecialchars($mu_link(['search' => '', 'page' => ''])); ?>">Clear</a><?php endif; ?>
        </form>
        <nav class="mu-chips" aria-label="Show">
            <?php foreach (['' => ['All', 'all'], 'members' => ['Members', 'members'], 'staff' => [aw_team_name() . ' & stewards', 'staff'], 'unverified' => ['Not verified', 'unverified'], 'new' => ['New this month', 'new']] as $f => [$label, $ck]): ?>
                <a href="<?php echo htmlspecialchars($mu_link(['filter' => $f, 'page' => ''])); ?>" class="mu-chip<?php echo $mu_filter === $f ? ' is-on' : ''; ?>"<?php echo $mu_filter === $f ? ' aria-current="page"' : ''; ?>><?php echo $label; ?> <span><?php echo number_format($mu_counts[$ck]); ?></span></a>
            <?php endforeach; ?>
        </nav>
    </div>

    <!-- the bulk form only carries the choice and the ticked ids; it does not wrap the list -->
    <form method="post" id="muBulk" class="mu-bulk" aria-label="With the selected members">
        <input type="hidden" name="selected_ids" id="muIds" value="">
        <label class="mu-check"><input type="checkbox" id="muAll"> <span>Select all on this page</span></label>
        <span class="mu-picked" id="muPicked" aria-live="polite">None selected</span>
        <span class="mu-bulk-acts">
            <button type="submit" name="bulk_action" value="verify" class="mu-btn" disabled data-bulk><i class="fas fa-user-check" aria-hidden="true"></i> Mark verified</button>
            <button type="submit" name="bulk_action" value="delete" class="mu-btn mu-danger" disabled data-bulk data-confirm="delete"><i class="fas fa-trash" aria-hidden="true"></i> Delete</button>
        </span>
    </form>

    <?php if (!$mu_rows): ?>
        <div class="mu-empty"><i class="fas fa-users" aria-hidden="true"></i><h2>No one here</h2><p><?php echo $mu_q !== '' ? 'Nobody matches "' . htmlspecialchars($mu_q) . '".' : 'No members in this view yet.'; ?></p></div>
    <?php else: ?>
    <?php if (aw_can('contact_members')): ?><p class="mu-legend"><span class="mu-lg mu-lg-wa"><i class="fab fa-whatsapp" aria-hidden="true"></i> WhatsApp</span><span class="mu-lg mu-lg-call"><i class="fas fa-phone" aria-hidden="true"></i> Call</span><span class="mu-lg mu-lg-mail"><i class="fas fa-envelope" aria-hidden="true"></i> Email</span><span class="mu-lg mu-lg-no"><span class="mu-dot" aria-hidden="true"></span> Amber dot: they have not said yes to that way (members who joined before the question was asked have not answered yet)</span></p><?php endif; ?>
    <ul class="mu-list">
        <?php foreach ($mu_rows as $i => $u):
            $role = aw_role_of($u);
            $name = awDisplayName($u);
            $pic = trim((string)($u['avatar'] ?: $u['profile_pic']));
            $pic = ($pic !== '' && strpos($pic, '..') === false) ? (preg_match('#^https?://#i', $pic) ? $pic : SITE_URL . '/' . ltrim($pic, '/')) : '';
            $is_me = (int)$u['id'] === $mu_me;
            $staff = in_array($role, ['owner', 'admin', 'moderator'], true);
            $protected = in_array($role, ['owner', 'admin'], true);
            $verified = (int)$u['is_verified'] === 1 || $staff;
        ?>
        <li class="mu-card" style="--i:<?php echo $i; ?>">
            <label class="mu-pick"><input type="checkbox" class="mu-row" value="<?php echo (int)$u['id']; ?>"<?php echo ($is_me || $protected) ? ' data-protected="1"' : ''; ?> aria-label="Select <?php echo htmlspecialchars($name); ?>"></label>
            <span class="mu-av" aria-hidden="true" data-initial="<?php echo htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1))); ?>"><?php if ($pic): ?><img src="<?php echo htmlspecialchars($pic); ?>" alt="" loading="lazy" onerror="this.parentNode.textContent=this.parentNode.dataset.initial"><?php else: echo htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1))); endif; ?></span>
            <div class="mu-who">
                <p class="mu-name"><?php echo htmlspecialchars($name); ?><?php if ($is_me): ?> <span class="mu-tag is-you">You</span><?php endif; ?>
                    <span class="mu-tag is-<?php echo $role; ?>"><?php echo htmlspecialchars(aw_role_label($role)); ?></span>
                    <?php if (!$verified): ?><span class="mu-tag is-wait"><i class="fas fa-clock" aria-hidden="true"></i> Not verified</span><?php endif; ?>
                </p>
                <p class="mu-meta">
                    <span>@<?php echo htmlspecialchars((string)$u['username']); ?></span>
                    <span class="mu-email"><?php echo htmlspecialchars((string)$u['email']); ?></span>
                    <?php if (!empty($u['country'])): ?><span><i class="fas fa-earth-africa" aria-hidden="true"></i> <?php echo htmlspecialchars((string)$u['country']); ?></span><?php endif; ?>
                    <?php echo aw_member_facts($u); ?>
                    <span>Joined <?php echo htmlspecialchars(date('j M Y', strtotime((string)$u['created_at']))); ?></span>
                    <span>Last seen <?php echo htmlspecialchars($mu_ago($u['last_login'] ?? '')); ?></span>
                    <?php if (!empty($u['social_provider'])): ?><span>Signs in with <?php echo htmlspecialchars(ucfirst((string)$u['social_provider'])); ?></span><?php endif; ?>
                </p>
            </div>
            <div class="mu-acts">
                <?php if (!$is_me) echo aw_contact_buttons($u); ?>
                <?php if (!$is_me): ?><a class="mu-icon" href="<?php echo SITE_URL; ?>/messages.php?to=<?php echo (int)$u['id']; ?>" title="Message <?php echo htmlspecialchars($name); ?>" aria-label="Message <?php echo htmlspecialchars($name); ?>"><i class="fas fa-comment-dots" aria-hidden="true"></i></a><?php endif; ?>
                <?php if (!$verified): ?>
                    <form method="post"><input type="hidden" name="row_id" value="<?php echo (int)$u['id']; ?>"><button type="submit" name="row_action" value="verify" class="mu-icon" title="Mark <?php echo htmlspecialchars($name); ?> as verified" aria-label="Mark <?php echo htmlspecialchars($name); ?> as verified"><i class="fas fa-user-check" aria-hidden="true"></i></button></form>
                <?php endif; ?>
                <?php if (aw_can('invite_staff') && $role === 'member' && (int)$u['id'] !== (int)$_SESSION['user_id']): ?><a class="mu-icon" href="<?php echo SITE_URL; ?>/admin/invite_staff.php?user=<?php echo (int)$u['id']; ?>" title="Invite <?php echo htmlspecialchars($name); ?> to the team" aria-label="Invite <?php echo htmlspecialchars($name); ?> to the team"><i class="fas fa-user-plus" aria-hidden="true"></i></a><?php endif; ?>
                <?php if (aw_can('audit_log') && $staff): ?><a class="mu-icon" href="<?php echo SITE_URL; ?>/admin/audit.php?actor=<?php echo (int)$u['id']; ?>" title="What <?php echo htmlspecialchars($name); ?> did (team log)" aria-label="What <?php echo htmlspecialchars($name); ?> did"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i></a><?php endif; ?>
                <?php if ($staff && $mu_can_staff && !$is_me): ?><a class="mu-icon" href="<?php echo SITE_URL; ?>/admin/staff.php" title="Change role in Team &amp; roles" aria-label="Change <?php echo htmlspecialchars($name); ?>'s role in Team &amp; roles"><i class="fas fa-user-shield" aria-hidden="true"></i></a><?php endif; ?>
                <?php if (!$is_me && !$protected): ?>
                    <form method="post"><input type="hidden" name="row_id" value="<?php echo (int)$u['id']; ?>"><button type="submit" name="row_action" value="delete" class="mu-icon mu-del" data-confirm="delete" data-name="<?php echo htmlspecialchars($name); ?>" title="Delete <?php echo htmlspecialchars($name); ?>" aria-label="Delete <?php echo htmlspecialchars($name); ?>"><i class="fas fa-trash" aria-hidden="true"></i><span>Delete</span></button></form>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($mu_pages > 1): ?>
    <nav class="mu-pages" aria-label="Pages">
        <?php if ($mu_page > 1): ?><a class="mu-btn" href="<?php echo htmlspecialchars($mu_link(['page' => $mu_page - 1])); ?>" rel="prev"><i class="fas fa-chevron-left" aria-hidden="true"></i> Newer</a><?php endif; ?>
        <span>Page <?php echo $mu_page; ?> of <?php echo $mu_pages; ?> · <?php echo number_format($mu_total); ?> people</span>
        <?php if ($mu_page < $mu_pages): ?><a class="mu-btn" href="<?php echo htmlspecialchars($mu_link(['page' => $mu_page + 1])); ?>" rel="next">Older <i class="fas fa-chevron-right" aria-hidden="true"></i></a><?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</div>

<dialog class="mu-dlg" id="muDlg" aria-labelledby="muDlgT">
    <form method="dialog">
        <div class="mu-dlg-ico" aria-hidden="true"><i class="fas fa-triangle-exclamation"></i></div>
        <h2 id="muDlgT">Delete?</h2>
        <p id="muDlgP"></p>
        <div class="mu-dlg-acts"><button value="no" class="mu-btn">Keep</button><button value="yes" class="mu-btn mu-danger" id="muDlgYes">Delete for good</button></div>
    </form>
</dialog>

<script>
(function () {
  var rows = [].slice.call(document.querySelectorAll('.mu-row')), all = document.getElementById('muAll'),
      ids = document.getElementById('muIds'), picked = document.getElementById('muPicked'), bulk = document.getElementById('muBulk'),
      btns = [].slice.call(bulk.querySelectorAll('[data-bulk]')), dlg = document.getElementById('muDlg');
  function sel() { return rows.filter(function (r) { return r.checked; }); }
  function update() {
    var s = sel();
    ids.value = s.map(function (r) { return r.value; }).join(',');
    picked.textContent = s.length ? s.length + ' selected' : 'None selected';
    btns.forEach(function (b) { b.disabled = !s.length; });
    if (all) { all.checked = s.length && s.length === rows.length; all.indeterminate = s.length > 0 && s.length < rows.length; }
    rows.forEach(function (r) { r.closest('.mu-card').classList.toggle('is-picked', r.checked); });
  }
  rows.forEach(function (r) { r.addEventListener('change', update); });
  if (all) all.addEventListener('change', function () { rows.forEach(function (r) { r.checked = all.checked; }); update(); });
  update();
  // a clear confirmation before anything is deleted (the form is sent only after "Delete for good")
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-confirm="delete"]');
    if (!b || b.dataset.ok) return;
    e.preventDefault();
    var s = sel(), single = b.dataset.name, prot = s.filter(function (r) { return r.dataset.protected; }).length;
    document.getElementById('muDlgT').textContent = single ? 'Delete ' + single + '?' : 'Delete ' + s.length + ' member' + (s.length === 1 ? '' : 's') + '?';
    document.getElementById('muDlgP').textContent = 'Their account, messages and activity are removed for good. This cannot be undone.'
      + (!single && prot ? ' ' + prot + ' admin or your own account will be left out.' : '');
    if (!dlg.showModal) { if (confirm(document.getElementById('muDlgT').textContent)) { b.dataset.ok = 1; b.click(); } return; }
    dlg.returnValue = ''; dlg.showModal();
    dlg.onclose = function () { if (dlg.returnValue === 'yes') { b.dataset.ok = 1; b.click(); } };
  });
})();
</script>

<style>
.mu { --mu-rose: #9c5457; --mu-rose-2: #b86b6e; --mu-ink: var(--text, #3a2629); --mu-mute: #6d5a5a; --mu-card: var(--card-bg, #fff); --mu-line: rgba(156, 84, 87, .16);
      max-width: 1100px; margin: 0 auto; padding: 28px 16px 64px; color: var(--mu-ink); }
html[data-theme="dark"] .mu { --mu-rose: #f0c4c5; --mu-rose-2: #e7aeb0; --mu-mute: #cdb8b8; --mu-line: rgba(240, 196, 197, .16); }
.mu .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.mu-head { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; justify-content: space-between; padding: 26px 26px 22px; border-radius: 24px; color: #fff;
           background: radial-gradient(120% 140% at 100% 0%, #c98d8f 0%, transparent 55%), linear-gradient(135deg, #5a2a35, #9c5457); box-shadow: 0 18px 40px rgba(92, 38, 50, .25); }
.mu-kicker { margin: 0; text-transform: uppercase; letter-spacing: .14em; font-size: .74rem; font-weight: 700; opacity: .85; }
.mu-head h1 { margin: 4px 0 4px; font-family: 'Playfair Display', Georgia, serif; font-size: clamp(1.8rem, 4vw, 2.4rem); color: #fff; }
.mu-lead { margin: 0; opacity: .92; }
.mu-head-acts { display: flex; gap: 8px; flex-wrap: wrap; }
.mu-head .mu-btn { background: rgba(255, 255, 255, .14); color: #fff; border-color: rgba(255, 255, 255, .45); }
.mu-head .mu-btn:hover { background: #fff; color: #5a2a35; }
.mu-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 16px; border-radius: 999px; border: 1.5px solid var(--mu-rose); background: transparent;
          color: var(--mu-rose); font: 600 .92rem Inter, system-ui, sans-serif; text-decoration: none; cursor: pointer; transition: background .2s, color .2s, opacity .2s; }
.mu-btn:hover { background: var(--mu-rose); color: #fff; }
html[data-theme="dark"] .mu-btn:hover { color: #2c1e1e; }
.mu-btn:disabled { opacity: .45; cursor: not-allowed; background: transparent; color: var(--mu-rose); }
.mu-primary { background: var(--mu-rose); color: #fff; }
html[data-theme="dark"] .mu-primary { color: #2c1e1e; }
.mu-danger { border-color: #b3261e; color: #b3261e; }
.mu-danger:hover { background: #b3261e; color: #fff; }
html[data-theme="dark"] .mu-danger { border-color: #ffb4ab; color: #ffb4ab; }
html[data-theme="dark"] .mu-danger:hover { background: #ffb4ab; color: #2c1e1e; }
.mu-alert { display: flex; gap: 10px; align-items: center; margin: 16px 0 0; padding: 12px 16px; border-radius: 14px; font-weight: 500; }
.mu-alert.is-ok { background: #e3efe6; color: #24452f; } .mu-alert.is-error { background: #f6e1e3; color: #6e2a31; }
.mu-tools { display: grid; gap: 12px; margin: 18px 0 12px; }
.mu-search { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; background: var(--mu-card); border: 1px solid var(--mu-line); border-radius: 18px; padding: 8px 8px 8px 16px; }
.mu-search i { color: var(--mu-rose); }
.mu-search input { flex: 1 1 200px; min-width: 0; min-height: 44px; border: 0 !important; background: transparent !important; box-shadow: none !important; color: inherit; font: inherit; outline: none; }
.mu-search:focus-within { outline: 3px solid rgba(156, 84, 87, .35); outline-offset: 2px; }
.mu-chips { display: flex; gap: 8px; flex-wrap: wrap; }
.mu-chip { display: inline-flex; align-items: center; gap: 8px; min-height: 40px; padding: 0 14px; border-radius: 999px; border: 1px solid var(--mu-line); background: var(--mu-card); color: var(--mu-ink); text-decoration: none; font-weight: 600; font-size: .9rem; }
.mu-chip span { min-width: 24px; padding: 2px 8px; border-radius: 999px; background: rgba(156, 84, 87, .12); color: var(--mu-rose); font-size: .78rem; text-align: center; }
.mu-chip:hover { border-color: var(--mu-rose); }
.mu-chip.is-on { background: var(--mu-rose); border-color: var(--mu-rose); color: #fff; }
.mu-chip.is-on span { background: rgba(255, 255, 255, .25); color: #fff; }
html[data-theme="dark"] .mu-chip.is-on { color: #2c1e1e; } html[data-theme="dark"] .mu-chip.is-on span { color: #2c1e1e; }
.mu-bulk { position: sticky; top: 76px; z-index: 5; display: flex; flex-wrap: wrap; gap: 10px 14px; align-items: center; padding: 10px 14px; margin-bottom: 12px; border-radius: 16px;
           background: color-mix(in srgb, var(--mu-card) 92%, transparent); backdrop-filter: blur(8px); border: 1px solid var(--mu-line); box-shadow: 0 8px 22px rgba(44, 30, 30, .07); }
.mu-check { display: inline-flex; gap: 8px; align-items: center; font-weight: 600; font-size: .9rem; cursor: pointer; min-height: 44px; }
.mu-picked { color: var(--mu-mute); font-size: .9rem; }
.mu-bulk-acts { display: flex; gap: 8px; margin-left: auto; flex-wrap: wrap; }
.mu input[type=checkbox] { width: 20px; height: 20px; accent-color: #9c5457; cursor: pointer; }
.mu-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.mu-card { display: grid; grid-template-columns: auto auto minmax(0, 1fr) auto; gap: 14px; align-items: center; padding: 14px 16px; border-radius: 18px; background: var(--mu-card);
           border: 1px solid var(--mu-line); box-shadow: 0 4px 14px rgba(44, 30, 30, .05); transition: border-color .2s, box-shadow .2s, transform .2s;
           animation: muIn .45s cubic-bezier(.2, .8, .2, 1) both; animation-delay: calc(var(--i) * 35ms); }
.mu-card:hover { border-color: rgba(156, 84, 87, .4); box-shadow: 0 10px 26px rgba(44, 30, 30, .09); }
.mu-card.is-picked { border-color: var(--mu-rose); box-shadow: 0 0 0 2px rgba(156, 84, 87, .25); }
@keyframes muIn { from { opacity: 0; transform: translateY(8px); } }
.mu-pick { display: grid; place-items: center; width: 32px; height: 44px; cursor: pointer; }
.mu-av { width: 48px; height: 48px; border-radius: 50%; display: grid; place-items: center; overflow: hidden; flex: none; font: 700 1.1rem 'Playfair Display', Georgia, serif; color: #fff;
         background: linear-gradient(135deg, #b86b6e, #6d3a46); box-shadow: 0 4px 10px rgba(92, 38, 50, .25); }
.mu-av img { width: 100%; height: 100%; object-fit: cover; }
.mu-who { min-width: 0; }
.mu-name { margin: 0 0 4px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; font-weight: 700; font-size: 1.02rem; }
.mu-tag { display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px; border-radius: 999px; font-size: .74rem; font-weight: 700; background: rgba(156, 84, 87, .1); color: #7d3f43; }
.mu-tag.is-owner { background: #5a2a35; color: #fff; } .mu-tag.is-admin { background: #9c5457; color: #fff; } .mu-tag.is-moderator { background: #e9d7f0; color: #4b2a5c; }
.mu-tag.is-wait { background: #fbecd4; color: #6b4310; } .mu-tag.is-you { background: #e3efe6; color: #24452f; }
html[data-theme="dark"] .mu-tag { background: rgba(240, 196, 197, .14); color: #f6dede; }
html[data-theme="dark"] .mu-tag.is-admin { background: #f0c4c5; color: #2c1e1e; } html[data-theme="dark"] .mu-tag.is-owner { background: #f6dede; color: #2c1e1e; }
html[data-theme="dark"] .mu-tag.is-wait { background: #4a3a20; color: #ffe2b0; }
.mu-meta { margin: 0; display: flex; flex-wrap: wrap; gap: 4px 14px; color: var(--mu-mute); font-size: .86rem; }
.mu-meta .mu-email { overflow-wrap: anywhere; }
.mu-legend { display: flex; flex-wrap: wrap; gap: 6px 14px; align-items: center; margin: 0 0 12px; font-size: .82rem; color: var(--text-light, #6b5a5a); }
.mu-lg { display: inline-flex; align-items: center; gap: 6px; } .mu-lg-wa i { color: #1a7a43; } .mu-lg-call i { color: #2f5f9c; } .mu-lg-mail i { color: #9c5457; }
.mu-dot { width: 9px; height: 9px; border-radius: 50%; background: #d99a2b; display: inline-block; }
.mu-acts { display: flex; gap: 6px; align-items: center; justify-content: flex-end; flex-wrap: wrap; }
.mu-acts form { margin: 0; display: contents; }
.mu-icon { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-width: 44px; height: 44px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--mu-line);
           background: transparent; color: var(--mu-rose); font: 600 .88rem Inter, system-ui, sans-serif; cursor: pointer; text-decoration: none; transition: background .2s, color .2s, border-color .2s; }
.mu-icon:hover, .mu-icon:focus-visible { background: var(--mu-rose); color: #fff; border-color: var(--mu-rose); }
html[data-theme="dark"] .mu-icon:hover { color: #2c1e1e; }
.mu-del { color: #b3261e; border-color: rgba(179, 38, 30, .3); }
.mu-del:hover, .mu-del:focus-visible { background: #b3261e; border-color: #b3261e; color: #fff; }
html[data-theme="dark"] .mu-del { color: #ffb4ab; } html[data-theme="dark"] .mu-del:hover { background: #ffb4ab; color: #2c1e1e; }
.mu-empty { text-align: center; padding: 48px 16px; border-radius: 20px; background: var(--mu-card); border: 1px dashed var(--mu-line); }
.mu-empty i { font-size: 2rem; color: var(--mu-rose); } .mu-empty h2 { font-family: 'Playfair Display', Georgia, serif; margin: 10px 0 4px; }
.mu-pages { display: flex; gap: 12px; align-items: center; justify-content: center; flex-wrap: wrap; margin-top: 18px; color: var(--mu-mute); }
.mu-dlg { border: 0; border-radius: 22px; padding: 26px; width: min(440px, calc(100vw - 32px)); background: var(--mu-card, #fff); color: var(--text, #3a2629); text-align: center; box-shadow: 0 30px 70px rgba(30, 18, 18, .35); }
.mu-dlg::backdrop { background: rgba(30, 18, 18, .5); backdrop-filter: blur(3px); }
.mu-dlg-ico { width: 56px; height: 56px; margin: 0 auto 8px; border-radius: 50%; display: grid; place-items: center; background: #fbe3e1; color: #b3261e; font-size: 1.4rem; }
.mu-dlg h2 { margin: 0 0 8px; font-family: 'Playfair Display', Georgia, serif; font-size: 1.3rem; }
.mu-dlg p { margin: 0 0 18px; line-height: 1.55; }
.mu-dlg-acts { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
/* phones: the actions sit under the details, always in view */
@media (max-width: 720px) {
    .mu-card { grid-template-columns: auto auto minmax(0, 1fr); }
    .mu-acts { grid-column: 1 / -1; justify-content: flex-start; padding-left: 46px; }
    .mu-bulk { top: 64px; } .mu-bulk-acts { margin-left: 0; width: 100%; } .mu-bulk-acts .mu-btn { flex: 1; white-space: nowrap; padding: 0 10px; font-size: .88rem; }
    .mu-head { padding: 22px 18px; }
}
@media (prefers-reduced-motion: reduce) { .mu-card { animation: none; } }
</style>
<?php require_once '../includes/footer.php'; ?>
