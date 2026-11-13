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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                $notice = $new === 'reader' ? $label . ' is no longer staff.' : $label . ' is now ' . ($new === 'admin' ? 'an admin' : 'a moderator') . '. The change applies the next time they sign in.';
            } catch (Throwable $e) {
                error_log('staff change: ' . $e->getMessage());
                $error = 'The change could not be saved. Please try again.';
            }
        }
    }
}

$staff = [];
try {
    $staff = $db->query("SELECT id, name, first_name, last_name, username, email, role, created_at FROM users WHERE role IN ('admin', 'moderator') ORDER BY role = 'admin' DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('staff list: ' . $e->getMessage()); }

$pageTitle = 'Staff & Roles';
require_once '../includes/header.php';
?>
<div class="admin-page">
    <div class="container" style="max-width:900px;">
        <div class="admin-header">
            <div>
                <h1>Staff &amp; Roles</h1>
                <p>Who helps run AngelWrites, and what each person can do.</p>
            </div>
            <div class="admin-actions"><?php echo aw_link_btn(SITE_URL . '/admin/dashboard.php', 'Back to Dashboard', 'outline', ['icon' => 'fa-arrow-left']); ?></div>
        </div>

        <?php echo aw_alert('error', $error); echo aw_alert('success', $notice); ?>

        <div class="card" style="margin-bottom:20px;"><div class="card-body">
            <h2 style="font-size:1.2rem;margin:0 0 12px;">What each role can do</h2>
            <ul style="margin:0 0 0 20px;line-height:1.8;">
                <li><strong>Owner</strong> (you): everything, including this page.</li>
                <li><strong>Admin</strong>: content, members, newsletter, payments, settings, backups, moderation and the team inbox. Can see who wrote a pseudonymous post.</li>
                <li><strong>Moderator</strong>: the moderation queue, comments, community Q&amp;A, testimonials, prayer requests and the team inbox. Cannot reach settings, roles, backups, payments, members or the newsletter, and never sees who is behind a pseudonym.</li>
            </ul>
        </div></div>

        <div class="card" style="margin-bottom:20px;"><div class="card-body">
            <h2 style="font-size:1.2rem;margin:0 0 12px;">Current staff</h2>
            <div class="table-responsive"><table class="admin-table">
                <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Change</th></tr></thead>
                <tbody>
                <?php foreach ($staff as $u): $r = aw_role_of($u); ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars(awDisplayName($u)); ?></strong><br><small style="color:var(--text-light);"><?php echo htmlspecialchars((string)$u['email']); ?></small></td>
                        <td><?php echo htmlspecialchars(aw_role_label($r)); ?></td>
                        <td>
                            <?php if ($r === 'owner'): ?>
                                <span style="color:var(--text-light);">—</span>
                            <?php else: ?>
                            <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                <label class="sr-only" for="role<?php echo (int)$u['id']; ?>">New role for <?php echo htmlspecialchars(awDisplayName($u)); ?></label>
                                <select name="role" id="role<?php echo (int)$u['id']; ?>" style="min-height:44px;border-radius:10px;border:1px solid var(--border);padding:6px 10px;background:var(--card-bg);color:var(--text);">
                                    <option value="moderator"<?php echo $r === 'moderator' ? ' selected' : ''; ?>>Moderator</option>
                                    <option value="admin"<?php echo $r === 'admin' ? ' selected' : ''; ?>>Admin</option>
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
                        <option value="moderator">Moderator</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <?php echo aw_btn('Add to staff', 'primary', ['type' => 'submit', 'icon' => 'fa-user-plus']); ?>
            </form>
        </div></div>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>
