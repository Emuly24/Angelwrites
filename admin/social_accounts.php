<?php
/**
 * Social accounts (see includes/social_accounts.php).
 * Owner and admins add each AngelWrites account once it exists: its public link, whether it shows in
 * the footer, the team members who look after it, and (optionally) its sign-in details, encrypted.
 * Team members who look after a platform see that platform here and can reveal its sign-in details.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/social_accounts.php';

aw_require('moderate'); // overseers may be moderators; editing needs manage_settings (checked below)
aw_social_accounts_table($db);
$sa_manage = aw_social_can_manage();
$sa_me = (int)$_SESSION['user_id'];
$sa_notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pf = (string)($_POST['platform'] ?? '');
    $act = (string)($_POST['action'] ?? '');
    if (!isset(AW_SOCIAL_PLATFORMS[$pf])) {
        $sa_notice = ['error', 'Unknown platform.'];
    } elseif ($act === 'save' && $sa_manage) {
        $url = trim((string)($_POST['url'] ?? ''));
        $live = !empty($_POST['is_live']) ? 1 : 0;
        if ($url !== '' && !preg_match('#^https://[^\s<>"]+$#i', $url)) {
            $sa_notice = ['error', 'The link must be a full address starting with https://'];
        } elseif ($live && $url === '') {
            $sa_notice = ['error', 'Add the link to the account before showing it as live.'];
        } else {
            $over = array_values(array_unique(array_map('intval', (array)($_POST['overseers'] ?? []))));
            $cur = $db->prepare("SELECT secret_enc FROM social_accounts WHERE platform = ?");
            $cur->execute([$pf]);
            $enc = (string)$cur->fetchColumn();
            $newpw = (string)($_POST['secret'] ?? '');
            if (!empty($_POST['clear_secret'])) $enc = '';
            elseif ($newpw !== '') { $e = aw_social_encrypt($newpw); if ($e === null) $sa_notice = ['error', 'Sign-in details could not be encrypted on this server, so they were not saved.']; else $enc = $e; }
            if (!$sa_notice) {
                try {
                    $db->prepare("INSERT INTO social_accounts (platform, url, handle, is_live, show_in_footer, overseers, login_name, secret_enc, notes, updated_by, updated_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                                  ON CONFLICT(platform) DO UPDATE SET url = excluded.url, handle = excluded.handle, is_live = excluded.is_live, show_in_footer = excluded.show_in_footer,
                                  overseers = excluded.overseers, login_name = excluded.login_name, secret_enc = excluded.secret_enc, notes = excluded.notes, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP")
                       ->execute([$pf, $url, trim(mb_substr((string)($_POST['handle'] ?? ''), 0, 80)), $live, !empty($_POST['show_in_footer']) ? 1 : 0, json_encode($over),
                                  trim(mb_substr((string)($_POST['login_name'] ?? ''), 0, 160)), $enc, trim(mb_substr((string)($_POST['notes'] ?? ''), 0, 1000)), $sa_me]);
                    if (function_exists('aw_audit')) aw_audit('social_account_save', 'social', 0, $pf . ($newpw !== '' ? ' (sign-in details changed)' : ''));
                    if (function_exists('awNotify')) foreach ($over as $o) if ($o !== $sa_me) awNotify($db, $o, 'team_reply', 'You look after AngelWrites on ' . AW_SOCIAL_PLATFORMS[$pf][0], 'Its details are in Social accounts.', SITE_URL . '/admin/social_accounts.php#sa-' . $pf, ['email' => false]);
                    $sa_notice = ['ok', AW_SOCIAL_PLATFORMS[$pf][0] . ' saved.' . ($live ? ' Its icon in the footer now links to the account.' : ' Until it is live, its icon shows the "coming soon" note.')];
                } catch (Throwable $e) {
                    error_log('social accounts save: ' . $e->getMessage());
                    $sa_notice = ['error', 'That could not be saved. Please try again.'];
                }
            }
        }
    }
}

// reveal sign-in details (owner, admins, or this platform's overseers), logged
$sa_reveal = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reveal') {
    $all = aw_social_accounts($db);
    $pf = (string)($_POST['platform'] ?? '');
    if (isset($all[$pf]) && aw_social_can_see($all[$pf]) && $all[$pf]['has_secret']) {
        $st = $db->prepare("SELECT secret_enc FROM social_accounts WHERE platform = ?");
        $st->execute([$pf]);
        $plain = aw_social_decrypt((string)$st->fetchColumn());
        $sa_reveal[$pf] = $plain === null ? false : $plain;
        if (function_exists('aw_audit')) aw_audit('social_secret_reveal', 'social', 0, $pf);
    }
}

$sa_all = aw_social_accounts($db);
$sa_staff = [];
try { $sa_staff = $db->query("SELECT id, username, display_name, first_name, last_name, role FROM users WHERE role IN ('owner', 'admin', 'moderator') ORDER BY role, username")->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $e) { error_log('social accounts staff: ' . $e->getMessage()); }
$sa_name = function ($u) { return trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: (trim((string)($u['display_name'] ?? '')) ?: (string)$u['username']); };
$sa_visible = array_filter($sa_all, function ($a) use ($sa_manage) { return $sa_manage || aw_social_can_see($a); });

$pageTitle = 'Social accounts';
require_once '../includes/header.php';
?>
<style>
.sa { max-width: 1060px; margin: 0 auto; padding: 24px 16px 64px; color: var(--text); }
.sa h1 { font-family: 'Playfair Display', Georgia, serif; font-size: clamp(1.6rem, 3vw, 2.1rem); margin: 0 0 6px; }
.sa-lead { color: var(--text-light); max-width: 48em; line-height: 1.6; margin: 0 0 18px; }
.sa-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)); gap: 16px; align-items: start; }
.sa-card { background: var(--card-bg, #fff); border: 1px solid var(--border); border-radius: 18px; padding: 18px; box-shadow: 0 6px 20px rgba(44,30,30,.06); }
.sa-card h2 { display: flex; align-items: center; gap: 10px; font-size: 1.1rem; margin: 0 0 10px; }
.sa-card h2 i { width: 38px; height: 38px; border-radius: 12px; display: grid; place-items: center; background: var(--bg, #f7f3ed); color: #9c5457; }
.sa-state { margin-left: auto; font-size: .76rem; font-weight: 700; padding: 3px 10px; border-radius: 999px; background: #f1ece6; color: #5c4a3a; }
.sa-state.live { background: #e3efe6; color: #2d5039; }
.sa-card label { display: block; font-weight: 600; font-size: .88rem; margin: 10px 0 4px; }
.sa-card input[type=text], .sa-card input[type=url], .sa-card input[type=password], .sa-card textarea, .sa-card select { width: 100%; min-height: 42px; padding: 8px 12px; border-radius: 10px; border: 1px solid var(--border); background: var(--card-bg, #fff); color: var(--text); font: inherit; }
.sa-card select[multiple] { min-height: 92px; }
.sa-checks { display: flex; flex-wrap: wrap; gap: 14px; margin: 12px 0 4px; }
.sa-checks label { display: inline-flex; gap: 6px; align-items: center; margin: 0; font-weight: 500; }
.sa-hint { color: var(--text-light); font-size: .82rem; margin: 4px 0 0; line-height: 1.5; }
.sa-secret { background: var(--bg, #f7f3ed); border-radius: 10px; padding: 8px 12px; font-family: ui-monospace, Menlo, monospace; word-break: break-all; }
.sa-card .btn { min-height: 42px; margin-top: 12px; }
.sa-alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 16px; font-weight: 500; }
.sa-alert.ok { background: #e3efe6; color: #2d5039; } .sa-alert.error { background: #f6e1e3; color: #6e2a31; }
.sa-ro p { margin: 6px 0; }
</style>
<div class="sa">
    <h1>Social accounts</h1>
    <p class="sa-lead"><?php if ($sa_manage): ?>Add each AngelWrites account once it exists. Until an account is marked live, its icon in the footer tells visitors honestly that it is coming soon and points them to messages in the app or <?php echo htmlspecialchars(AW_CONTACT_EMAIL); ?>. Choose the team members who look after each one: they see it here and can reveal its sign-in details.<?php else: ?>These are the AngelWrites accounts you look after.<?php endif; ?></p>
    <?php if ($sa_notice): ?><div class="sa-alert <?php echo $sa_notice[0] === 'ok' ? 'ok' : 'error'; ?>" role="status"><?php echo htmlspecialchars($sa_notice[1]); ?></div><?php endif; ?>
    <?php if (!$sa_visible): ?><p class="sa-hint">You do not look after any accounts yet.</p><?php endif; ?>
    <div class="sa-grid">
    <?php foreach ($sa_visible as $k => $a): ?>
        <section class="sa-card" id="sa-<?php echo $k; ?>" aria-labelledby="sa-h-<?php echo $k; ?>">
            <h2 id="sa-h-<?php echo $k; ?>"><i class="<?php echo $a['icon']; ?>" aria-hidden="true"></i><?php echo htmlspecialchars($a['label']); ?><span class="sa-state<?php echo $a['is_live'] ? ' live' : ''; ?>"><?php echo $a['is_live'] ? 'Live' : 'Coming soon'; ?></span></h2>
            <?php if ($sa_manage): ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="platform" value="<?php echo $k; ?>"><input type="hidden" name="action" value="save">
                <label for="sa-url-<?php echo $k; ?>">Public link</label>
                <input type="url" id="sa-url-<?php echo $k; ?>" name="url" placeholder="https://" value="<?php echo htmlspecialchars($a['url']); ?>">
                <label for="sa-h2-<?php echo $k; ?>">Handle or name</label>
                <input type="text" id="sa-h2-<?php echo $k; ?>" name="handle" placeholder="@angelwrites" value="<?php echo htmlspecialchars($a['handle']); ?>">
                <div class="sa-checks">
                    <label><input type="checkbox" name="is_live" value="1"<?php echo $a['is_live'] ? ' checked' : ''; ?>> Live (the icon links here)</label>
                    <label><input type="checkbox" name="show_in_footer" value="1"<?php echo $a['show_in_footer'] ? ' checked' : ''; ?>> Show in the footer</label>
                </div>
                <label for="sa-o-<?php echo $k; ?>">Looked after by</label>
                <select id="sa-o-<?php echo $k; ?>" name="overseers[]" multiple>
                    <?php foreach ($sa_staff as $u): ?><option value="<?php echo (int)$u['id']; ?>"<?php echo in_array((int)$u['id'], $a['overseers'], true) ? ' selected' : ''; ?>><?php echo htmlspecialchars($sa_name($u) . ' (' . $u['role'] . ')'); ?></option><?php endforeach; ?>
                </select>
                <p class="sa-hint">Hold Ctrl (or Cmd) to choose more than one. Team members are added in Staff &amp; roles.</p>
                <label for="sa-l-<?php echo $k; ?>">Sign-in email or username</label>
                <input type="text" id="sa-l-<?php echo $k; ?>" name="login_name" value="<?php echo htmlspecialchars($a['login_name']); ?>" autocomplete="off">
                <label for="sa-p-<?php echo $k; ?>">Password <?php echo $a['has_secret'] ? '(saved; type a new one to replace it)' : ''; ?></label>
                <input type="password" id="sa-p-<?php echo $k; ?>" name="secret" autocomplete="new-password" placeholder="<?php echo $a['has_secret'] ? '••••••••' : ''; ?>">
                <?php if ($a['has_secret']): ?><div class="sa-checks"><label><input type="checkbox" name="clear_secret" value="1"> Remove the saved password</label></div><?php endif; ?>
                <p class="sa-hint">Encrypted on the server; only you, the admins and the people looking after this account can reveal it. A password manager is safest of all, and turn on two-step sign-in on the platform.</p>
                <label for="sa-n-<?php echo $k; ?>">Notes for the team</label>
                <textarea id="sa-n-<?php echo $k; ?>" name="notes" rows="2" maxlength="1000"><?php echo htmlspecialchars($a['notes']); ?></textarea>
                <button type="submit" class="btn btn-primary">Save <?php echo htmlspecialchars($a['label']); ?></button>
            </form>
            <?php else: ?>
            <div class="sa-ro">
                <p><strong>Link:</strong> <?php echo $a['url'] !== '' ? '<a href="' . htmlspecialchars($a['url']) . '" target="_blank" rel="noopener">' . htmlspecialchars($a['url']) . '</a>' : 'not added yet'; ?></p>
                <?php if ($a['handle'] !== ''): ?><p><strong>Handle:</strong> <?php echo htmlspecialchars($a['handle']); ?></p><?php endif; ?>
                <?php if ($a['login_name'] !== ''): ?><p><strong>Sign in as:</strong> <?php echo htmlspecialchars($a['login_name']); ?></p><?php endif; ?>
                <?php if ($a['notes'] !== ''): ?><p class="sa-hint"><?php echo nl2br(htmlspecialchars($a['notes'])); ?></p><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($a['has_secret'] && aw_social_can_see($a)): ?>
                <?php if (array_key_exists($k, $sa_reveal)): ?>
                    <p class="sa-hint" style="margin-top:12px;">Password (shown once; this was logged):</p>
                    <div class="sa-secret"><?php echo $sa_reveal[$k] === false ? 'It could not be decrypted (was the key changed?).' : htmlspecialchars($sa_reveal[$k]); ?></div>
                <?php else: ?>
                <form method="post"><input type="hidden" name="platform" value="<?php echo $k; ?>"><input type="hidden" name="action" value="reveal"><button type="submit" class="btn btn-outline"><i class="fas fa-eye" aria-hidden="true"></i> Reveal the password</button></form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>
