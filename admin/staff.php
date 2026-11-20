<?php
/**
 * K2: the owner's staff screen. Who is staff, and as what (admin or moderator).
 * Owner only. Every change is written to the audit log. The owner's own role cannot be changed here.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/components.php';
require_once '../includes/twofactor.php';
require_once '../includes/member_profile.php';

aw_require('manage_staff');

$me = (int)$_SESSION['user_id'];
$owner = aw_owner_id();
$error = ''; $notice = '';

function aw_staff_find(PDO $db, $q) {
    $q = trim((string)$q);
    if ($q === '') return null;
    $st = $db->prepare("SELECT id, name, first_name, last_name, username, email, role, is_verified FROM users WHERE email = ? COLLATE NOCASE OR username = ? COLLATE NOCASE LIMIT 2");
    $st->execute([$q, $q]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return count($rows) === 1 ? $rows[0] : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_2fa_required'])) {
    // K5: require two-step sign-in for every staff member (the owner too)
    $on = $_POST['set_2fa_required'] === '1';
    if ($on && !aw_2fa_enabled($db, $me)) {
        $error = 'Turn on two-step sign-in for your own account first (Account security), so you are not locked out.';
    } else {
        try {
            $db->prepare("INSERT INTO settings (key, value) VALUES ('staff_2fa_required', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$on ? '1' : '0']);
            aw_audit('staff_2fa_required', 'setting', null, $on ? 'on' : 'off');
            header('Location: ' . SITE_URL . '/admin/staff.php'); exit;
        } catch (Throwable $e) { error_log('2fa required: ' . $e->getMessage()); $error = 'That could not be saved.'; }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_role_names'])) {
    try {
        $ins = $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
        foreach (AW_ROLE_NAMES as $rk => $rd) {
            $v = trim(preg_replace('/\s+/', ' ', strip_tags((string)($_POST['role_name'][$rk] ?? ''))));
            $ins->execute(['role_name_' . $rk, mb_substr($v, 0, 30)]);
        }
        aw_audit('settings_changed', 'setting', null, 'role names');
        header('Location: ' . SITE_URL . '/admin/staff.php?names=1#names'); exit;
    } catch (Throwable $e) { error_log('role names: ' . $e->getMessage()); $error = 'The names could not be saved.'; }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = (string)($_POST['role'] ?? '');
    $target = null;
    if (isset($_POST['add_staff'])) {
        $target = aw_staff_find($db, $_POST['who'] ?? '');
        if (!$target) $error = 'No single account matches that email or username. Check the spelling; the person must already have an account.';
    } elseif (isset($_POST['user_id'])) {
        $st = $db->prepare("SELECT id, name, first_name, last_name, username, email, role, is_verified FROM users WHERE id = ?");
        $st->execute([(int)$_POST['user_id']]);
        $target = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$target) $error = 'That account no longer exists.';
    }
    if (!$error && $target) {
        $tid = (int)$target['id'];
        $new = ['admin' => 'admin', 'moderator' => 'moderator', 'member' => 'reader'][$role] ?? null;
        if ($new === null) {
            $error = 'Choose a role.';
        } elseif ($tid === $owner || $tid === $me) {
            $error = 'Your own role (the owner) cannot be changed here.';
        } elseif ($new === 'admin' || $new === 'moderator') {
            if ((int)$target['is_verified'] !== 1 && $target['role'] !== 'admin') $error = 'This person has not verified their email yet. Ask them to verify first.';
        }
        if (!$error) {
            try {
                $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$new, $tid]);
                // end their other sessions' "remember me", so the new role applies at their next sign-in
                try { $db->prepare("DELETE FROM remember_tokens WHERE user_id = ?")->execute([$tid]); } catch (Throwable $e) { /* optional table */ }
                $label = awDisplayName($target);
                aw_audit('role_changed', 'user', $tid, $label . ': ' . aw_role_label(aw_role_of($target)) . ' → ' . aw_role_label($new === 'reader' ? 'member' : $new));
                $notice = $new === 'reader' ? $label . ' is no longer on the team.' : $label . ' is now ' . ($new === 'admin' ? 'a ' . aw_role_label('admin') : 'part of the ' . aw_team_name()) . '. The change applies the next time they sign in.';
            } catch (Throwable $e) {
                error_log('staff change: ' . $e->getMessage());
                $error = 'The change could not be saved. Please try again.';
            }
        }
    }
}

$staff = [];
try {
    aw_profile_columns($db);
    $staff = $db->query("SELECT id, name, first_name, last_name, username, email, role, created_at, contact_number, whatsapp_number, contact_ok FROM users WHERE role IN ('admin', 'moderator') ORDER BY role = 'admin' DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('staff list: ' . $e->getMessage()); }

$pageTitle = 'Team & Roles';
require_once '../includes/header.php';
?>
<div class="admin-page">
    <div class="container" style="max-width:900px;">
        <div class="admin-header">
            <div>
                <h1>Team &amp; Roles</h1>
                <p>Who helps run AngelWrites, and what each person can do.</p>
            </div>
            <div class="admin-actions"><?php echo aw_link_btn(SITE_URL . '/admin/dashboard.php', 'Back to Dashboard', 'outline', ['icon' => 'fa-arrow-left']); ?></div>
        </div>

        <?php echo aw_alert('error', $error); echo aw_alert('success', $notice ?: (isset($_GET['names']) ? 'The team names are saved.' : '')); ?>

        <div class="card" style="margin-bottom:20px;"><div class="card-body">
            <h2 style="font-size:1.2rem;margin:0 0 6px;">What each role can do</h2>
            <p style="color:var(--text-light);margin:0 0 10px;">Everyone here serves the community, and everyone (the <?php echo htmlspecialchars(aw_role_label('owner')); ?> too) may need the community's help one day. The names describe the work, not a rank.</p>
            <ul style="margin:0 0 0 20px;line-height:1.8;">
                <li><strong><?php echo htmlspecialchars(aw_role_label('owner')); ?></strong> (you): everything, including this page.</li>
                <li><strong><?php echo htmlspecialchars(aw_role_label('admin')); ?></strong>: content, members, newsletter, payments, settings, backups, moderation and the team inbox. Can see who wrote a pseudonymous post.</li>
                <li><strong><?php echo htmlspecialchars(aw_team_name()); ?></strong>: moderators (the moderation queue, comments, community Q&amp;A, testimonials, prayer requests and the team inbox) and volunteers, who help in one area only (prayer team, events, translations…). They cannot reach settings, roles, backups, payments, members or the newsletter, and never see who is behind a pseudonym. <a href="<?php echo SITE_URL; ?>/admin/invite_staff.php">Invite to the team</a></li>
            </ul>
        </div></div>

        <div class="card" style="margin-bottom:20px;"><div class="card-body">
            <h2 style="font-size:1.2rem;margin:0 0 12px;">Current staff</h2>
            <div class="table-responsive"><table class="admin-table">
                <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Two-step sign-in</th><th scope="col">Change</th></tr></thead>
                <tbody>
                <?php foreach ($staff as $u): $r = aw_role_of($u); ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars(awDisplayName($u)); ?></strong><br><small style="color:var(--text-light);"><?php echo htmlspecialchars((string)$u['email']); ?></small><?php if ((int)$u['id'] !== $me) { $st_b = aw_contact_buttons($u); if ($st_b !== '') echo '<div style="margin-top:6px">' . $st_b . '</div>'; } ?></td>
                        <td><?php echo htmlspecialchars(aw_role_label($r)); ?></td>
                        <td><?php echo aw_2fa_enabled($db, (int)$u['id']) ? 'On' : '<span style="color:#6f4e22;">Off</span>'; ?></td>
                        <td>
                            <?php if ($r === 'owner'): ?>
                                <span style="color:var(--text-light);">—</span>
                            <?php else: ?>
                            <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                <label class="sr-only" for="role<?php echo (int)$u['id']; ?>">New role for <?php echo htmlspecialchars(awDisplayName($u)); ?></label>
                                <select name="role" id="role<?php echo (int)$u['id']; ?>" style="min-height:44px;border-radius:10px;border:1px solid var(--border);padding:6px 10px;background:var(--card-bg);color:var(--text);">
                                    <option value="moderator"<?php echo $r === 'moderator' ? ' selected' : ''; ?>><?php echo htmlspecialchars(aw_team_name()); ?> (moderator)</option>
                                    <option value="admin"<?php echo $r === 'admin' ? ' selected' : ''; ?>><?php echo htmlspecialchars(aw_role_label('admin')); ?> (admin)</option>
                                    <option value="member">Not staff (member)</option>
                                </select>
                                <?php echo aw_btn('Save', 'outline', ['type' => 'submit', 'small' => true]); ?>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div></div>

        <div class="card" style="margin-bottom:20px;" id="names"><div class="card-body">
            <h2 style="font-size:1.2rem;margin:0 0 6px;">Team names</h2>
            <p style="color:var(--text-light);margin:0 0 12px;">What the site calls each role, everywhere it is shown. Leave a box empty for the suggested name.</p>
            <form method="POST" class="admin-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0 14px;">
                <?php foreach (AW_ROLE_NAMES as $rk => $rd): ?>
                    <div class="form-group"><label for="rn_<?php echo $rk; ?>"><?php echo ['owner' => 'You (the owner)', 'admin' => 'Other admins', 'moderator' => 'Moderators and volunteers', 'member' => 'Everyone else'][$rk]; ?></label>
                        <input type="text" id="rn_<?php echo $rk; ?>" name="role_name[<?php echo $rk; ?>]" maxlength="30" placeholder="<?php echo htmlspecialchars($rd); ?>" value="<?php echo htmlspecialchars((string)aw_setting('role_name_' . $rk, '')); ?>"></div>
                <?php endforeach; ?>
                </div>
                <?php echo aw_btn('Save names', 'primary', ['type' => 'submit', 'name' => 'save_role_names', 'value' => '1', 'small' => true]); ?>
            </form>
        </div></div>

        <?php $aw_req = (string)aw_setting('staff_2fa_required', '0') === '1'; ?>
        <div class="card" style="margin-bottom:20px;"><div class="card-body">
            <h2 style="font-size:1.2rem;margin:0 0 6px;">Two-step sign-in for staff</h2>
            <p style="color:var(--text-light);margin:0 0 12px;"><?php echo $aw_req ? 'Required: staff without it are asked to set it up before any staff page opens.' : 'Optional at the moment. Each person can turn it on in Account security.'; ?>
                <a href="<?php echo SITE_URL; ?>/account_security.php">Your account security</a></p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <?php echo aw_btn($aw_req ? 'Make it optional' : 'Require it for all staff', $aw_req ? 'outline' : 'primary', ['type' => 'submit', 'name' => 'set_2fa_required', 'value' => $aw_req ? '0' : '1', 'small' => true]); ?>
            </form>
        </div></div>

        <div class="card"><div class="card-body">
            <h2 style="font-size:1.2rem;margin:0 0 6px;">Add a staff member</h2>
            <p style="color:var(--text-light);margin:0 0 14px;">The person needs an account with a verified email first.</p>
            <form method="POST" class="admin-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <input type="hidden" name="add_staff" value="1">
                <?php echo aw_field('who', 'Their email or username', 'text', ['required' => true, 'maxlength' => 190]); ?>
                <div class="form-group">
                    <label for="f_role">Role</label>
                    <select name="role" id="f_role" style="min-height:44px;border-radius:10px;border:1px solid var(--border);padding:6px 10px;background:var(--card-bg);color:var(--text);">
                        <option value="moderator"><?php echo htmlspecialchars(aw_team_name()); ?> (moderator)</option>
                        <option value="admin"><?php echo htmlspecialchars(aw_role_label('admin')); ?> (admin)</option>
                    </select>
                </div>
                <?php echo aw_btn('Add to staff', 'primary', ['type' => 'submit', 'icon' => 'fa-user-plus']); ?>
            </form>
        </div></div>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>
