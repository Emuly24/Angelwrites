<?php
/**
 * Invite a member to volunteer on the team. The owner or an admin chooses the member and the
 * position; the invitation explains what that position does and does not do, and changes with the
 * position chosen (includes/staff_positions.php). The member is told on the site and by email and
 * answers on staff_invite.php. Also lists the volunteers and recent invitations. Every action is audited.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/components.php';
require_once '../includes/mail_helper.php';
require_once '../includes/notifications.php';
require_once '../includes/staff_positions.php';
require_once '../includes/member_profile.php'; // contact buttons, age range

aw_require('invite_staff'); // owner and admins; checks the CSRF token on every POST

$me = (int)$_SESSION['user_id'];
$error = ''; $notice = '';

function aw_is_find(PDO $db, $q) {
    $q = trim((string)$q);
    if ($q === '') return null;
    try {
        $st = $db->prepare("SELECT id, name, first_name, last_name, username, email, role, is_verified FROM users WHERE email = ? COLLATE NOCASE OR username = ? COLLATE NOCASE LIMIT 2");
        $st->execute([$q, ltrim($q, '@')]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return count($rows) === 1 ? $rows[0] : null;
    } catch (Throwable $e) { error_log('invite find: ' . $e->getMessage()); return null; }
}
function aw_is_user(PDO $db, $id) {
    aw_profile_columns($db);
    try { $st = $db->prepare("SELECT id, name, first_name, last_name, username, email, role, is_verified, contact_number, whatsapp_number, contact_ok, age_range, gender, city FROM users WHERE id = ?"); $st->execute([(int)$id]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
    catch (Throwable $e) { error_log('invite user: ' . $e->getMessage()); return null; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_invite'])) {
        $target = !empty($_POST['user_id']) ? aw_is_user($db, $_POST['user_id']) : aw_is_find($db, $_POST['who'] ?? '');
        $pos = (string)($_POST['position'] ?? '');
        if (!$target) $error = 'No single account matches that email or username. Check the spelling; the person must already have an account.';
        elseif ((int)$target['id'] === $me) $error = 'You cannot invite yourself.';
        elseif ((int)$target['is_verified'] !== 1) $error = 'This person has not verified their email yet. Ask them to verify first.';
        elseif (!isset(AW_POSITIONS[$pos])) $error = 'Please choose a position.';
        elseif (!empty(AW_POSITIONS[$pos]['role']) && !aw_can('manage_staff')) $error = 'Only the owner can invite someone to be a moderator (it changes their role).';
        else {
            $r = aw_invite_send($db, (int)$target['id'], $pos, (string)($_POST['note'] ?? ''), $me);
            if ($r['ok']) { $_SESSION['aw_is_flash'] = $r['msg']; header('Location: ' . SITE_URL . '/admin/invite_staff.php'); exit; }
            $error = $r['msg'];
        }
    } elseif (isset($_POST['withdraw'])) {
        if (aw_invite_withdraw($db, (int)($_POST['invite_id'] ?? 0))) { $_SESSION['aw_is_flash'] = 'The invitation was withdrawn.'; header('Location: ' . SITE_URL . '/admin/invite_staff.php'); exit; }
        $error = 'That invitation is no longer open.';
    } elseif (isset($_POST['end_area'])) {
        $uid = (int)($_POST['user_id'] ?? 0); $area = (string)($_POST['area'] ?? '');
        if (in_array($area, aw_user_areas($uid), true) && aw_area_revoke($db, $uid, $area)) {
            if (function_exists('awNotify')) awNotify($db, $uid, 'staff_area_ended', 'Your role as ' . AW_POSITIONS[$area]['title'] . ' has ended', 'Thank you for everything you did for the community.', SITE_URL . '/dashboard.php', ['email' => false]);
            $_SESSION['aw_is_flash'] = 'The role has ended.'; header('Location: ' . SITE_URL . '/admin/invite_staff.php'); exit;
        }
        $error = 'That role could not be ended. It may already have ended.';
    }
}
if (!empty($_SESSION['aw_is_flash'])) { $notice = (string)$_SESSION['aw_is_flash']; unset($_SESSION['aw_is_flash']); }

$is_pre = !empty($_GET['user']) ? aw_is_user($db, (int)$_GET['user']) : null; // "Invite to the team" from Members
$is_pos = isset(AW_POSITIONS[$_GET['position'] ?? '']) ? (string)$_GET['position'] : (isset(AW_POSITIONS[$_POST['position'] ?? '']) ? (string)$_POST['position'] : 'prayer');
if (!empty(AW_POSITIONS[$is_pos]['role']) && !aw_can('manage_staff')) $is_pos = 'prayer';
$is_from = awDisplayName(aw_staff_user_row($db, $me));
$is_to = $is_pre ? awDisplayName($is_pre) : 'their name';
$is_holders = aw_area_holders($db);
$is_invites = aw_invite_list($db, 40);

$pageTitle = 'Invite to the team';
require_once '../includes/header.php';
?>
<div class="admin-page">
    <div class="container" style="max-width:1100px;">
        <div class="admin-header">
            <div>
                <h1>Invite to the team</h1>
                <p>Ask a member to volunteer in one part of AngelWrites. The invitation explains exactly what the role does and does not include.</p>
            </div>
            <div class="admin-actions"><?php echo aw_link_btn(SITE_URL . '/admin/team.php', 'Team desk', 'outline', ['icon' => 'fa-hands-holding-circle']); ?><?php if (aw_can('manage_staff')) echo ' ' . aw_link_btn(SITE_URL . '/admin/staff.php', 'Staff & roles', 'outline', ['icon' => 'fa-user-shield']); ?></div>
        </div>

        <?php echo aw_alert('error', $error); echo aw_alert('success', $notice); ?>

        <div class="is-grid">
            <div class="card"><div class="card-body">
                <h2 class="is-h2">Send an invitation</h2>
                <form method="POST" class="admin-form" autocomplete="off" id="isForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <?php if ($is_pre): ?>
                        <input type="hidden" name="user_id" value="<?php echo (int)$is_pre['id']; ?>">
                        <div class="form-group"><span class="is-label">Member</span>
                            <p class="is-who"><strong><?php echo htmlspecialchars($is_to); ?></strong> · @<?php echo htmlspecialchars((string)$is_pre['username']); ?> <a href="<?php echo SITE_URL; ?>/admin/invite_staff.php">Choose someone else</a></p>
                            <p class="is-who is-facts"><?php echo aw_member_facts($is_pre); ?></p>
                            <?php echo aw_contact_buttons($is_pre); ?>
                            <?php if (($is_pre['age_range'] ?? '') === '13-17'): ?><p class="is-help" style="color:#a8432f"><strong>Under 18.</strong> Think carefully before giving a young person a team role, and never one that involves private contact with adults (mentoring, the inbox).</p><?php endif; ?></div>
                    <?php else: ?>
                        <?php echo aw_field('who', 'Their email or username', 'text', ['required' => true, 'maxlength' => 190, 'value' => (string)($_POST['who'] ?? '')]); ?>
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="isPos">Position</label>
                        <select name="position" id="isPos" class="is-select" aria-describedby="isPosHelp">
                            <?php foreach (AW_POSITIONS as $k => $p): if (!empty($p['role']) && !aw_can('manage_staff')) continue; ?>
                                <option value="<?php echo htmlspecialchars($k); ?>"<?php echo $k === $is_pos ? ' selected' : ''; ?>><?php echo htmlspecialchars($p['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small id="isPosHelp" class="is-help">The invitation on the right changes to match.</small>
                    </div>
                    <div class="form-group">
                        <label for="isNote">A personal note (optional)</label>
                        <textarea name="note" id="isNote" rows="4" maxlength="1000" placeholder="Why you thought of them, in your own words."><?php echo htmlspecialchars((string)($_POST['note'] ?? '')); ?></textarea>
                    </div>
                    <?php echo aw_btn('Send invitation', 'primary', ['type' => 'submit', 'name' => 'send_invite', 'value' => '1', 'icon' => 'fa-paper-plane']); ?>
                    <p class="is-help" style="margin-top:10px;">They get a notification on the site and an email. Nothing changes until they accept, and the invitation expires after <?php echo AW_INVITE_DAYS; ?> days.</p>
                </form>
            </div></div>

            <div class="card is-preview-card"><div class="card-body">
                <h2 class="is-h2">The invitation they will receive</h2>
                <?php foreach (AW_POSITIONS as $k => $p): $m = aw_invite_message($k, $is_to, $is_from, ''); ?>
                    <div class="is-preview" data-pos="<?php echo htmlspecialchars($k); ?>"<?php echo $k === $is_pos ? '' : ' hidden'; ?>>
                        <p class="is-subject"><span>Subject:</span> <?php echo htmlspecialchars($m['subject']); ?></p>
                        <div class="is-mail">
                            <?php echo $m['html']; /* built from fixed text and escaped names in aw_invite_message() */ ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <blockquote class="is-note-preview" id="isNotePreview" hidden></blockquote>
            </div></div>
        </div>

        <div class="card" style="margin-top:20px;"><div class="card-body">
            <h2 class="is-h2">Volunteers</h2>
            <?php if (!$is_holders): ?>
                <p class="no-items">Nobody holds a volunteer role yet. Moderators are listed on the Staff page.</p>
            <?php else: ?>
                <div class="table-responsive"><table class="admin-table">
                    <thead><tr><th scope="col">Member</th><th scope="col">Role</th><th scope="col">Since</th><th scope="col">Activity</th><th scope="col">End</th></tr></thead>
                    <tbody>
                    <?php foreach ($is_holders as $h): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars(awDisplayName($h)); ?></strong><div style="margin-top:6px"><?php echo aw_contact_buttons($h); ?></div></td>
                            <td><i class="fas <?php echo AW_POSITIONS[$h['area']]['icon'] ?? 'fa-circle'; ?>" aria-hidden="true"></i> <?php echo htmlspecialchars(AW_POSITIONS[$h['area']]['title'] ?? $h['area']); ?></td>
                            <td><?php echo htmlspecialchars(date('j M Y', strtotime($h['granted_at'] . ' UTC'))); ?></td>
                            <td><?php if (aw_can('audit_log')): ?><a href="<?php echo SITE_URL; ?>/admin/audit.php?actor=<?php echo (int)$h['user_id']; ?>">What they did</a><?php endif; ?></td>
                            <td><form method="POST" onsubmit="return confirm('End this role?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                <input type="hidden" name="user_id" value="<?php echo (int)$h['user_id']; ?>">
                                <input type="hidden" name="area" value="<?php echo htmlspecialchars($h['area']); ?>">
                                <?php echo aw_btn('End role', 'outline', ['type' => 'submit', 'name' => 'end_area', 'value' => '1', 'small' => true]); ?>
                            </form></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div></div>

        <div class="card" style="margin-top:20px;"><div class="card-body">
            <h2 class="is-h2">Invitations</h2>
            <?php if (!$is_invites): ?>
                <p class="no-items">No invitations sent yet.</p>
            <?php else: ?>
                <div class="table-responsive"><table class="admin-table">
                    <thead><tr><th scope="col">Member</th><th scope="col">Position</th><th scope="col">Sent</th><th scope="col">By</th><th scope="col">Answer</th></tr></thead>
                    <tbody>
                    <?php foreach ($is_invites as $i): $st = aw_invite_expired($i) ? 'expired' : $i['status']; ?>
                        <tr>
                            <td><?php echo htmlspecialchars(awDisplayName($i)); ?></td>
                            <td><?php echo htmlspecialchars(AW_POSITIONS[$i['position']]['title'] ?? $i['position']); ?></td>
                            <td><?php echo htmlspecialchars(date('j M Y', strtotime($i['created_at'] . ' UTC'))); ?></td>
                            <td><?php echo htmlspecialchars(awDisplayName(['username' => $i['by_username'], 'name' => $i['by_name'], 'first_name' => $i['by_first'], 'last_name' => $i['by_last']])); ?></td>
                            <td><span class="is-status is-<?php echo htmlspecialchars($st); ?>"><?php echo htmlspecialchars(['pending' => 'Waiting', 'accepted' => 'Accepted', 'declined' => 'Declined', 'withdrawn' => 'Withdrawn', 'expired' => 'Expired'][$st] ?? $st); ?></span>
                                <?php if ($st === 'pending'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                        <input type="hidden" name="invite_id" value="<?php echo (int)$i['id']; ?>">
                                        <button type="submit" name="withdraw" value="1" class="is-linkbtn">Withdraw</button>
                                    </form>
                                <?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div></div>
    </div>
</div>
<style>
.is-grid{display:grid;grid-template-columns:minmax(0,5fr) minmax(0,7fr);gap:20px;align-items:start}
.is-h2{font-size:1.2rem;margin:0 0 14px}
#isForm textarea{display:block;width:100%;float:none;min-height:110px;border-radius:10px;border:1px solid var(--border);padding:10px 12px;background:var(--card-bg);color:var(--text);font:inherit;box-sizing:border-box}
#isForm label{display:block}
.is-label{display:block;font-weight:600;margin-bottom:6px}
.is-who{margin:0}.is-facts{display:flex;gap:12px;flex-wrap:wrap;color:var(--text-light);font-size:.9rem;margin:4px 0 8px}
.is-select{width:100%;min-height:44px;border-radius:10px;border:1px solid var(--border);padding:8px 10px;background:var(--card-bg);color:var(--text);font-size:1rem}
.is-help{color:var(--text-light);display:block;margin-top:4px}
.is-preview-card{position:sticky;top:90px}
.is-subject{margin:0 0 10px;font-weight:600}.is-subject span{color:var(--text-light);font-weight:400}
.is-mail{border:1px solid var(--border);border-radius:14px;padding:18px 20px;background:var(--card-bg);line-height:1.6;max-height:70vh;overflow:auto}
.is-mail h3{font-size:1rem;margin:16px 0 6px}
.is-mail ul{margin:0;padding-left:20px}
.is-note-preview{margin:12px 0 0;padding:10px 14px;border-left:3px solid #DBA1A2;background:rgba(219,161,162,.12);border-radius:0 10px 10px 0;white-space:pre-wrap}
.is-status{display:inline-block;padding:2px 10px;border-radius:999px;font-size:.85rem;font-weight:600;background:#eee;color:#3d2e2e}
.is-status.is-pending{background:#fff4e5;color:#6b4100}.is-status.is-accepted{background:#eaf6ee;color:#1d5130}
.is-status.is-declined,.is-status.is-withdrawn,.is-status.is-expired{background:#f1ecec;color:#5b4747}
.is-linkbtn{background:none;border:0;color:var(--primary-dark,#9c5457);text-decoration:underline;cursor:pointer;font:inherit;min-height:32px}
[data-theme="dark"] .is-status{background:#3a2e2f;color:#f3e6e6}
[data-theme="dark"] .is-status.is-pending{background:#33260f;color:#f6deb0}[data-theme="dark"] .is-status.is-accepted{background:#16301f;color:#cdeed7}
@media (max-width:860px){.is-grid{grid-template-columns:1fr}.is-preview-card{position:static}}
</style>
<script>
(function () {
    var sel = document.getElementById('isPos'), note = document.getElementById('isNote'), np = document.getElementById('isNotePreview');
    function show() { document.querySelectorAll('.is-preview').forEach(function (el) { el.hidden = el.getAttribute('data-pos') !== sel.value; }); }
    function shownote() { var t = (note.value || '').trim(); np.hidden = !t; np.textContent = t ? t + '\n— ' + <?php echo json_encode($is_from, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> : ''; }
    if (sel) sel.addEventListener('change', show);
    if (note) { note.addEventListener('input', shownote); shownote(); }
})();
</script>
<?php require_once '../includes/footer.php'; ?>
