<?php
/**
 * K4: the team inbox. Moderators, admins and the owner answer contact messages as "AngelWrites Team".
 * The owner and admins see who wrote each reply; moderators see "Team".
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/components.php';
require_once '../includes/mail_helper.php';
require_once '../includes/team_inbox.php';

aw_require('team_inbox');
aw_inbox_tables($db);
$me = (int)$_SESSION['user_id'];
$seeAuthors = aw_can('see_identities');
$error = ''; $notice = '';
$id = (int)($_GET['id'] ?? 0);

$staff = [];
try { $staff = $db->query("SELECT id, name, first_name, last_name, username, role FROM users WHERE role IN ('admin', 'moderator') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mid = (int)($_POST['message_id'] ?? 0);
    if (isset($_POST['send_reply'])) {
        $r = aw_inbox_reply($db, $mid, (string)($_POST['reply'] ?? ''));
        $r['ok'] ? $notice = $r['message'] : $error = $r['message'];
        if (!$r['ok']) $_SESSION['inbox_draft'][$mid] = (string)($_POST['reply'] ?? '');
    } elseif (isset($_POST['set_state'])) {
        $set = [];
        if (isset($_POST['status'])) $set['status'] = (string)$_POST['status'];
        if (isset($_POST['assigned_to'])) $set['assigned_to'] = (int)$_POST['assigned_to'];
        $notice = aw_inbox_set_state($db, $mid, $set) ? 'Updated.' : '';
        if ($notice === '') $error = 'That could not be saved.';
    } elseif (isset($_POST['save_canned'])) {
        $title = trim(strip_tags((string)($_POST['c_title'] ?? ''))); $body = trim(strip_tags((string)($_POST['c_body'] ?? '')));
        if ($title === '' || $body === '') $error = 'A saved reply needs a name and some text.';
        else {
            try {
                $cid = (int)($_POST['c_id'] ?? 0);
                if ($cid) $db->prepare("UPDATE canned_responses SET title = ?, body = ? WHERE id = ?")->execute([$title, $body, $cid]);
                else $db->prepare("INSERT INTO canned_responses (title, body, created_by) VALUES (?, ?, ?)")->execute([$title, $body, $me]);
                $notice = 'Saved reply stored.';
            } catch (Throwable $e) { error_log('canned save: ' . $e->getMessage()); $error = 'That could not be saved.'; }
        }
    } elseif (isset($_POST['delete_canned'])) {
        try { $db->prepare("DELETE FROM canned_responses WHERE id = ?")->execute([(int)$_POST['delete_canned']]); $notice = 'Saved reply deleted.'; } catch (Throwable $e) { $error = 'That could not be deleted.'; }
    }
    if ($error === '') { $_SESSION['inbox_notice'] = $notice; header('Location: ' . SITE_URL . '/admin/inbox.php' . ($mid ? '?id=' . $mid : (isset($_POST['save_canned']) || isset($_POST['delete_canned']) ? '?canned=1' : ''))); exit; }
}
if (!empty($_SESSION['inbox_notice'])) { $notice = $_SESSION['inbox_notice']; unset($_SESSION['inbox_notice']); }

$canned = [];
try { $canned = $db->query("SELECT * FROM canned_responses ORDER BY title")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

$filter = (string)($_GET['f'] ?? 'open');
$pageTitle = 'Team Inbox';
require_once '../includes/header.php';
?>
<div class="admin-page"><div class="container" style="max-width:1000px;">
    <div class="admin-header">
        <div><h1>Team Inbox</h1><p>Messages sent through Contact. Replies go out as “AngelWrites Team”.</p></div>
        <div class="admin-actions">
            <?php echo aw_link_btn(SITE_URL . '/admin/inbox.php?canned=1', 'Saved replies', 'outline', ['small' => true, 'icon' => 'fa-bookmark']); ?>
            <?php echo aw_link_btn(SITE_URL . '/admin/dashboard.php', 'Dashboard', 'outline', ['small' => true, 'icon' => 'fa-arrow-left']); ?>
        </div>
    </div>
    <?php echo aw_alert('error', $error); echo aw_alert('success', $notice); ?>

<?php if (isset($_GET['canned'])): ?>
    <h2 class="ib-h2">Saved replies</h2>
    <p class="ib-sub">Write {first_name} where the person's first name should go. Every saved reply can be changed before it is sent.</p>
    <?php foreach (array_merge($canned, [['id' => 0, 'title' => '', 'body' => '']]) as $c): ?>
        <form method="POST" class="ib-card">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
            <input type="hidden" name="c_id" value="<?php echo (int)$c['id']; ?>">
            <h3 class="ib-h3"><?php echo $c['id'] ? htmlspecialchars($c['title']) : 'Add a saved reply'; ?></h3>
            <label for="ct<?php echo (int)$c['id']; ?>">Name</label>
            <input type="text" id="ct<?php echo (int)$c['id']; ?>" name="c_title" maxlength="80" value="<?php echo htmlspecialchars($c['title']); ?>">
            <label for="cb<?php echo (int)$c['id']; ?>">Text</label>
            <textarea id="cb<?php echo (int)$c['id']; ?>" name="c_body" rows="5"><?php echo htmlspecialchars($c['body']); ?></textarea>
            <div class="ib-row">
                <button type="submit" name="save_canned" value="1" class="btn btn-primary btn-sm"><?php echo $c['id'] ? 'Save changes' : 'Add'; ?></button>
                <?php if ($c['id']): ?><button type="submit" name="delete_canned" value="<?php echo (int)$c['id']; ?>" class="btn btn-outline btn-sm" onclick="return confirm('Delete this saved reply?');">Delete</button><?php endif; ?>
            </div>
        </form>
    <?php endforeach; ?>

<?php elseif ($id > 0):
    $st = $db->prepare("SELECT * FROM contact_messages WHERE id = ?"); $st->execute([$id]); $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m): echo aw_empty('Message not found', 'It may have been deleted.', [SITE_URL . '/admin/inbox.php', 'Back to the inbox']);
    else:
        if (!(int)$m['is_read']) { try { $db->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = ?")->execute([$id]); } catch (Throwable $e) {} }
        $state = aw_inbox_state($db, $id);
        $rep = $db->prepare("SELECT t.*, u.name, u.first_name, u.last_name, u.username FROM team_replies t LEFT JOIN users u ON u.id = t.author_id WHERE t.message_id = ? ORDER BY t.id");
        $rep->execute([$id]); $replies = $rep->fetchAll(PDO::FETCH_ASSOC);
        $draft = (string)($_SESSION['inbox_draft'][$id] ?? ''); unset($_SESSION['inbox_draft'][$id]);
?>
    <p><a href="<?php echo SITE_URL; ?>/admin/inbox.php" class="ib-back"><i class="fas fa-arrow-left" aria-hidden="true"></i> All messages</a></p>
    <article class="ib-card">
        <p class="ib-meta"><strong><?php echo htmlspecialchars($m['name']); ?></strong> · <?php echo htmlspecialchars($m['email']); ?><?php echo !empty($m['user_id']) ? ' · member' : ''; ?> · <?php echo htmlspecialchars(date('M j, Y g:i a', strtotime($m['created_at']))); ?></p>
        <h2 class="ib-h2"><?php echo htmlspecialchars($m['subject'] ?: '(no subject)'); ?></h2>
        <div class="ib-body"><?php echo nl2br(htmlspecialchars($m['message'])); ?></div>
    </article>
    <?php foreach ($replies as $r): ?>
        <article class="ib-card ib-reply">
            <p class="ib-meta"><strong>AngelWrites Team</strong><?php echo $seeAuthors ? ' <span class="ib-author">(written by ' . htmlspecialchars($r['author_id'] ? awDisplayName($r) : 'unknown') . ')</span>' : ''; ?> · <?php echo htmlspecialchars(date('M j, Y g:i a', strtotime($r['created_at']))); ?><?php echo (int)$r['emailed'] ? '' : ' · <em>email not sent</em>'; ?></p>
            <div class="ib-body"><?php echo nl2br(htmlspecialchars($r['body'])); ?></div>
        </article>
    <?php endforeach; ?>

    <form method="POST" class="ib-card" id="replyForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
        <input type="hidden" name="message_id" value="<?php echo $id; ?>">
        <h2 class="ib-h3">Reply as AngelWrites Team</h2>
        <?php if ($canned): ?>
        <label for="cannedPick">Start from a saved reply</label>
        <select id="cannedPick">
            <option value="">Choose…</option>
            <?php foreach ($canned as $c): ?><option value="<?php echo htmlspecialchars($c['body']); ?>"><?php echo htmlspecialchars($c['title']); ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <label for="replyText">Your reply</label>
        <textarea id="replyText" name="reply" rows="8" required><?php echo htmlspecialchars($draft); ?></textarea>
        <p class="ib-sub">It is emailed to <?php echo htmlspecialchars($m['email']); ?> and signed “AngelWrites Team”.<?php echo !empty($m['user_id']) ? ' They will also see it on their messages page.' : ''; ?></p>
        <button type="submit" name="send_reply" value="1" class="btn btn-primary"><i class="fas fa-paper-plane" aria-hidden="true"></i> Send reply</button>
    </form>

    <form method="POST" class="ib-card ib-row">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
        <input type="hidden" name="message_id" value="<?php echo $id; ?>">
        <input type="hidden" name="set_state" value="1">
        <label for="assign">Assigned to</label>
        <select id="assign" name="assigned_to">
            <option value="0">Nobody</option>
            <?php foreach ($staff as $s): ?><option value="<?php echo (int)$s['id']; ?>"<?php echo (int)$state['assigned_to'] === (int)$s['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars(awDisplayName($s)); ?><?php echo (int)$s['id'] === $me ? ' (me)' : ''; ?></option><?php endforeach; ?>
        </select>
        <label for="status">Status</label>
        <select id="status" name="status">
            <?php foreach (['open' => 'Open', 'answered' => 'Answered', 'closed' => 'Closed'] as $k => $l): ?><option value="<?php echo $k; ?>"<?php echo $state['status'] === $k ? ' selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline btn-sm">Save</button>
    </form>
    <script>
    (function () { var p = document.getElementById('cannedPick'), t = document.getElementById('replyText');
      if (p) p.addEventListener('change', function () { if (!p.value) return; if (t.value.trim() && !confirm('Replace what you have written?')) { p.value = ''; return; } t.value = p.value; t.focus(); p.value = ''; }); })();
    </script>
    <?php endif; ?>

<?php else:
    $where = ['open' => "COALESCE(s.status, 'open') = 'open'", 'mine' => "s.assigned_to = $me AND COALESCE(s.status, 'open') <> 'closed'", 'answered' => "s.status = 'answered'", 'closed' => "s.status = 'closed'", 'all' => '1'][$filter] ?? "COALESCE(s.status, 'open') = 'open'";
    $list = [];
    try {
        $list = $db->query("SELECT m.id, m.name, m.subject, m.message, m.is_read, m.created_at, s.status, s.assigned_to,
                                   (SELECT COUNT(*) FROM team_replies t WHERE t.message_id = m.id) AS replies
                            FROM contact_messages m LEFT JOIN team_inbox_state s ON s.message_id = m.id
                            WHERE $where ORDER BY m.created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('inbox list: ' . $e->getMessage()); }
    $names = []; foreach ($staff as $s) $names[(int)$s['id']] = awDisplayName($s);
?>
    <nav class="ib-tabs" aria-label="Inbox views">
        <?php foreach (['open' => 'Open', 'mine' => 'Assigned to me', 'answered' => 'Answered', 'closed' => 'Closed', 'all' => 'All'] as $k => $l): ?>
            <a href="?f=<?php echo $k; ?>"<?php echo $filter === $k ? ' aria-current="page"' : ''; ?>><?php echo $l; ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if (!$list): echo aw_empty('Nothing here', $filter === 'open' ? 'When someone writes through Contact, their message appears here to answer.' : 'No messages in this view.', null, 'fa-inbox');
    else: ?>
    <ul class="ib-list">
        <?php foreach ($list as $l): ?>
            <li class="<?php echo (int)$l['is_read'] ? '' : 'is-new'; ?>">
                <a href="?id=<?php echo (int)$l['id']; ?>">
                    <span class="ib-from"><?php echo htmlspecialchars($l['name']); ?><?php echo (int)$l['is_read'] ? '' : ' <span class="ib-new">New</span>'; ?></span>
                    <span class="ib-subj"><?php echo htmlspecialchars($l['subject'] ?: mb_substr($l['message'], 0, 60)); ?></span>
                    <span class="ib-when"><?php echo htmlspecialchars(date('M j', strtotime($l['created_at']))); ?><?php echo (int)$l['replies'] ? ' · ' . (int)$l['replies'] . ' repl' . ((int)$l['replies'] === 1 ? 'y' : 'ies') : ''; ?><?php echo $l['assigned_to'] ? ' · ' . htmlspecialchars($names[(int)$l['assigned_to']] ?? 'assigned') : ''; ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
<?php endif; ?>
</div></div>
<style>
.ib-h2 { font-family:'Playfair Display',Georgia,serif; font-size:1.3rem; margin:0 0 8px; } .ib-h3 { font-size:1.1rem; margin:0 0 10px; }
.ib-sub { color:var(--text-light); font-size:.9rem; }
.ib-card { background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:18px 20px; margin-bottom:14px; }
.ib-card label { display:block; font-weight:600; margin:10px 0 4px; }
.ib-card input[type=text], .ib-card textarea, .ib-card select { width:100%; min-height:44px; border:1px solid var(--border); border-radius:10px; padding:8px 12px; background:var(--card-bg); color:var(--text); font:inherit; }
.ib-reply { border-left:4px solid var(--rose); margin-left:clamp(0px, 4vw, 40px); }
.ib-meta { color:var(--text-light); font-size:.88rem; margin:0 0 8px; } .ib-author { font-style:italic; }
.ib-body { line-height:1.65; overflow-wrap:anywhere; }
.ib-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; } .ib-row label { margin:0; } .ib-row select { width:auto; }
.ib-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
.ib-tabs a { padding:10px 16px; min-height:44px; border-radius:999px; border:1px solid var(--border); text-decoration:none; color:var(--text); background:var(--card-bg); }
.ib-tabs a[aria-current] { background:var(--rose-dark, #a8676a); color:#fff; border-color:transparent; }
.ib-list { list-style:none; margin:0; padding:0; background:var(--card-bg); border:1px solid var(--border); border-radius:16px; overflow:hidden; }
.ib-list li + li { border-top:1px solid var(--border); }
.ib-list a { display:grid; grid-template-columns:minmax(120px, 200px) 1fr auto; gap:12px; padding:14px 18px; text-decoration:none; color:var(--text); align-items:center; }
.ib-list a:hover, .ib-list a:focus-visible { background:rgba(219,161,162,.08); }
.ib-list .is-new .ib-from, .ib-list .is-new .ib-subj { font-weight:700; }
.ib-new { background:var(--rose-dark, #a8676a); color:#fff; border-radius:999px; padding:1px 8px; font-size:.72rem; margin-left:4px; }
.ib-when { color:var(--text-light); font-size:.85rem; white-space:nowrap; }
.ib-subj { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
@media (max-width:640px) { .ib-list a { grid-template-columns:1fr; gap:2px; } }
.ib-back { color:var(--text-light); }
</style>
<?php require_once '../includes/footer.php'; ?>
