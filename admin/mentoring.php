<?php
/**
 * Admin > Mentoring (includes/mentoring.php): match people who asked for a mentor with approved
 * mentors, review offers to mentor, and see the pairs. Owner and admins (it shows who is who).
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/messaging.php';
require_once '../includes/mentoring.php';
require_once '../includes/notifications.php';

aw_require('mentoring_team'); // owner and admins; checks the CSRF token on every POST
aw_mentor_tables($db);
$ma_back = SITE_URL . '/admin/mentoring.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $msg = ['error', 'That did not work.'];
    if ($act === 'propose') { $r = aw_mentor_propose($db, (int)$_POST['request_id'], (int)$_POST['mentor_id']); $msg = !empty($r['ok']) ? ['ok', 'The mentor has been asked. You will see here when they answer.'] : ['error', $r['error']]; }
    elseif ($act === 'approve' || $act === 'decline' || $act === 'pause') {
        $uid = (int)$_POST['user_id'];
        $db->prepare("UPDATE mentors SET status = ?, reviewed_by = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?")->execute([['approve' => 'approved', 'decline' => 'declined', 'pause' => 'paused'][$act], (int)$_SESSION['user_id'], $uid]);
        if ($act === 'approve') awNotify($db, $uid, 'mentor_approved', 'You are now an AngelWrites mentor', 'Thank you for offering your time. We will ask you when someone could use your help.', SITE_URL . '/mentoring.php', ['email' => true]);
        if (function_exists('aw_audit')) aw_audit('mentor_' . $act, 'user', $uid, '');
        $msg = ['ok', $act === 'approve' ? 'Approved.' : ($act === 'pause' ? 'Paused.' : 'Declined.')];
    } elseif ($act === 'close') { $msg = aw_mentor_close($db, (int)$_POST['request_id'], (int)$_SESSION['user_id'], (string)($_POST['note'] ?? ''), true) ? ['ok', 'Closed.'] : $msg; }
    $_SESSION['aw_ma_flash'] = $msg;
    header('Location: ' . $ma_back); exit;
}
$ma_flash = $_SESSION['aw_ma_flash'] ?? null; unset($_SESSION['aw_ma_flash']);
$ma_rows = function ($status) use ($db) {
    $st = $db->prepare("SELECT r.*, a.name, a.first_name, a.last_name, a.username, a.gender, a.country, m.name AS m_name, m.first_name AS m_first, m.last_name AS m_last, m.username AS m_user
                        FROM mentor_requests r JOIN users a ON a.id = r.user_id LEFT JOIN users m ON m.id = r.mentor_id WHERE r.status IN ($status) ORDER BY r.created_at");
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$ma_waiting = $ma_rows("'waiting', 'proposed'");
$ma_active = $ma_rows("'active'");
$ma_mentors = aw_mentor_list($db, 'approved');
$ma_pending = aw_mentor_list($db, 'pending');
$ma_paused = aw_mentor_list($db, 'paused');
$ma_mname = function ($r) { return awDisplayName(['name' => $r['m_name'], 'first_name' => $r['m_first'], 'last_name' => $r['m_last'], 'username' => $r['m_user']]); };

$pageTitle = 'Mentoring';
require_once '../includes/header.php';
?>
<div class="ma">
    <header class="ma-head"><div><h1>Mentoring</h1><p>Match members who asked for a mentor with approved mentors. Mentors accept before anything starts; conversations happen in AngelWrites messages.</p></div>
        <a class="btn btn-outline" href="<?php echo SITE_URL; ?>/mentoring.php">The members' page</a></header>
    <?php if ($ma_flash): ?><div class="alert <?php echo $ma_flash[0] === 'ok' ? 'alert-success' : 'alert-error'; ?>" role="status"><?php echo htmlspecialchars($ma_flash[1]); ?></div><?php endif; ?>

    <section class="ma-card"><h2>Waiting for a mentor <span class="ma-n"><?php echo count($ma_waiting); ?></span></h2>
        <?php if (!$ma_waiting): ?><p class="ma-none">Nobody is waiting.</p><?php endif; ?>
        <?php foreach ($ma_waiting as $r): ?>
        <div class="ma-item">
            <div class="ma-who"><strong><?php echo htmlspecialchars(awDisplayName($r)); ?></strong>
                <small><?php echo htmlspecialchars(AW_MENTOR_AREAS[$r['area']] ?? ''); ?> · <?php echo htmlspecialchars(AW_MENTOR_FREQ[$r['frequency']] ?? ''); ?> · asked <?php echo date('j M', strtotime($r['created_at'])); ?><?php echo $r['country'] ? ' · ' . htmlspecialchars($r['country']) : ''; ?><?php echo $r['gender'] ? ' · ' . htmlspecialchars($r['gender']) : ''; ?></small>
                <p>“<?php echo nl2br(htmlspecialchars($r['hopes'])); ?>”</p>
                <?php if ($r['preference']): ?><p class="ma-pref"><b>Preference:</b> <?php echo htmlspecialchars($r['preference']); ?></p><?php endif; ?>
                <?php if ($r['status'] === 'proposed'): ?><p class="ma-pref"><i class="fas fa-hourglass-half" aria-hidden="true"></i> Waiting for <?php echo htmlspecialchars($ma_mname($r)); ?> to answer (asked <?php echo date('j M', strtotime($r['proposed_at'])); ?>).</p><?php endif; ?>
            </div>
            <form method="post" class="ma-acts">
                <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                <label class="sr-only" for="mm<?php echo (int)$r['id']; ?>">Mentor</label>
                <select id="mm<?php echo (int)$r['id']; ?>" name="mentor_id" required><option value="">Choose a mentor…</option>
                    <?php foreach ($ma_mentors as $m): if ((int)$m['user_id'] === (int)$r['user_id']) continue; $fits = in_array($r['area'], explode(',', (string)$m['areas']), true); $full = (int)$m['load'] >= (int)$m['max_mentees']; ?>
                    <option value="<?php echo (int)$m['user_id']; ?>"<?php echo $full ? ' disabled' : ''; ?>><?php echo htmlspecialchars(awDisplayName($m)) . ($fits ? ' ★' : '') . ' · ' . (int)$m['load'] . '/' . (int)$m['max_mentees'] . ($full ? ' (full)' : ''); ?></option>
                    <?php endforeach; ?></select>
                <button class="btn btn-sm btn-primary" name="act" value="propose"><?php echo $r['status'] === 'proposed' ? 'Ask someone else' : 'Ask this mentor'; ?></button>
                <button class="btn btn-sm btn-outline" name="act" value="close" onclick="return confirm('Close this request?');">Close</button>
            </form>
        </div>
        <?php endforeach; ?>
        <?php if ($ma_waiting): ?><p class="ma-none">★ = offers help in this area. Numbers show how many people each mentor walks with now, and how many they can.</p><?php endif; ?>
    </section>

    <section class="ma-card"><h2>Offers to mentor <span class="ma-n"><?php echo count($ma_pending); ?></span></h2>
        <?php if (!$ma_pending): ?><p class="ma-none">No new offers.</p><?php endif; ?>
        <?php foreach ($ma_pending as $m): ?>
        <div class="ma-item"><div class="ma-who"><strong><?php echo htmlspecialchars(awDisplayName($m)); ?></strong>
            <small><?php echo htmlspecialchars(implode(', ', array_map(function ($a) { return AW_MENTOR_AREAS[$a] ?? $a; }, explode(',', (string)$m['areas'])))); ?> · up to <?php echo (int)$m['max_mentees']; ?></small>
            <p><?php echo nl2br(htmlspecialchars((string)$m['bio'])); ?></p></div>
            <form method="post" class="ma-acts"><input type="hidden" name="user_id" value="<?php echo (int)$m['user_id']; ?>">
                <button class="btn btn-sm btn-primary" name="act" value="approve">Approve</button><button class="btn btn-sm btn-outline" name="act" value="decline">Not now</button></form></div>
        <?php endforeach; ?>
    </section>

    <section class="ma-card"><h2>Walking together <span class="ma-n"><?php echo count($ma_active); ?></span></h2>
        <?php if (!$ma_active): ?><p class="ma-none">No active mentoring yet.</p><?php endif; ?>
        <?php foreach ($ma_active as $r): ?>
        <div class="ma-item"><div class="ma-who"><strong><?php echo htmlspecialchars($ma_mname($r)); ?> → <?php echo htmlspecialchars(awDisplayName($r)); ?></strong>
            <small><?php echo htmlspecialchars(AW_MENTOR_AREAS[$r['area']] ?? ''); ?> · since <?php echo date('j M Y', strtotime($r['started_at'])); ?></small></div>
            <form method="post" class="ma-acts"><input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>"><button class="btn btn-sm btn-outline" name="act" value="close" onclick="return confirm('End this mentoring? Both will be told gently.');">End</button></form></div>
        <?php endforeach; ?>
    </section>

    <section class="ma-card"><h2>Mentors <span class="ma-n"><?php echo count($ma_mentors) + count($ma_paused); ?></span></h2>
        <?php foreach (array_merge($ma_mentors, $ma_paused) as $m): ?>
        <div class="ma-item"><div class="ma-who"><strong><?php echo htmlspecialchars(awDisplayName($m)); ?></strong> <?php echo $m['status'] === 'paused' ? '<span class="ma-tag">paused</span>' : ''; ?>
            <small><?php echo htmlspecialchars(implode(', ', array_map(function ($a) { return AW_MENTOR_AREAS[$a] ?? $a; }, explode(',', (string)$m['areas'])))); ?> · <?php echo (int)$m['load']; ?>/<?php echo (int)$m['max_mentees']; ?></small></div>
            <form method="post" class="ma-acts"><input type="hidden" name="user_id" value="<?php echo (int)$m['user_id']; ?>"><?php if ($m['status'] === 'paused'): ?><button class="btn btn-sm btn-outline" name="act" value="approve">Resume</button><?php else: ?><button class="btn btn-sm btn-outline" name="act" value="pause">Pause</button><?php endif; ?></form></div>
        <?php endforeach; ?>
        <?php if (!$ma_mentors && !$ma_paused): ?><p class="ma-none">No approved mentors yet. Members can offer on the mentoring page.</p><?php endif; ?>
    </section>
</div>
<style>
.ma { max-width: 1000px; margin: 0 auto; padding: 24px 16px 64px; color: var(--text); }
.ma .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.ma-head { display: flex; justify-content: space-between; gap: 14px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 16px; } .ma-head h1 { margin: 0 0 4px; font-family: 'Playfair Display', Georgia, serif; } .ma-head p { margin: 0; color: var(--text-light); max-width: 46em; }
.ma-card { background: var(--card-bg, #fff); border: 1px solid var(--border); border-radius: 20px; padding: 18px; margin-bottom: 14px; }
.ma-card h2 { margin: 0 0 10px; font-size: 1.1rem; display: flex; gap: 10px; align-items: center; } .ma-n { min-width: 28px; padding: 2px 10px; border-radius: 999px; background: #9c5457; color: #fff; font-size: .82rem; text-align: center; }
.ma-item { display: flex; justify-content: space-between; gap: 14px; flex-wrap: wrap; padding: 12px 0; border-top: 1px solid var(--border); } .ma-item:first-of-type { border-top: 0; }
.ma-who { flex: 1 1 360px; } .ma-who small { display: block; color: var(--text-light); } .ma-who p { margin: 8px 0 0; line-height: 1.55; } .ma-pref { font-size: .9rem; color: var(--text-light); }
.ma-acts { display: flex; gap: 6px; flex-wrap: wrap; align-items: flex-start; } .ma-acts select { min-height: 38px; border-radius: 10px; padding: 0 10px; max-width: 260px; }
.ma-none { color: var(--text-light); font-size: .9rem; margin: 6px 0 0; } .ma-tag { font-size: .74rem; padding: 2px 8px; border-radius: 999px; background: #f1ebe4; color: #6b5a48; }
</style>
<?php require_once '../includes/footer.php'; ?>
