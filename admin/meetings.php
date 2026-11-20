<?php
/**
 * admin/meetings.php: team meetings (training and catch-ups) and, for the owner and admins, the Care
 * Team's check-ins and the weekly summary switch (includes/team_care.php).
 *  - Everyone on the Care Team: their meetings, with Yes / Maybe / No.
 *  - Owner and admins (invite_staff): schedule a meeting for the whole Care Team or one role, cancel
 *    one, see who is coming, read check-in answers, turn the weekly summary on or off, send it now.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/components.php';
require_once '../includes/mail_helper.php';
require_once '../includes/notifications.php';
require_once '../includes/team_care.php';

aw_require('staff_dashboard'); // the Care Team, stewards and the founder; checks the CSRF token on every POST

$me = (int)$_SESSION['user_id'];
$lead = aw_can('invite_staff');
$error = ''; $notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['rsvp'])) {
        $m = aw_meeting_get($db, (int)($_POST['meeting_id'] ?? 0));
        if ($m && empty($m['cancelled_at']) && aw_meeting_rsvp($db, (int)$m['id'], $me, (string)$_POST['rsvp'])) $_SESSION['aw_mt_flash'] = 'Thank you, your answer is saved.';
        header('Location: ' . SITE_URL . '/admin/meetings.php#m' . (int)($_POST['meeting_id'] ?? 0)); exit;
    } elseif ($lead && isset($_POST['create'])) {
        $r = aw_meeting_create($db, $_POST, $me);
        if ($r['ok']) { $_SESSION['aw_mt_flash'] = $r['msg']; header('Location: ' . SITE_URL . '/admin/meetings.php#m' . $r['id']); exit; }
        $error = $r['msg'];
    } elseif ($lead && isset($_POST['cancel'])) {
        $_SESSION['aw_mt_flash'] = aw_meeting_cancel($db, (int)($_POST['meeting_id'] ?? 0)) ? 'The meeting was cancelled and everyone invited was told.' : 'That meeting could not be cancelled.';
        header('Location: ' . SITE_URL . '/admin/meetings.php'); exit;
    } elseif ($lead && isset($_POST['summary_switch'])) {
        try { $db->prepare("INSERT INTO settings (key, value) VALUES ('weekly_summary_enabled', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$_POST['summary_switch'] === '1' ? '1' : '0']); aw_audit('settings_changed', 'setting', null, 'weekly summary ' . ($_POST['summary_switch'] === '1' ? 'on' : 'off')); }
        catch (Throwable $e) { error_log('summary switch: ' . $e->getMessage()); }
        header('Location: ' . SITE_URL . '/admin/meetings.php#summary'); exit;
    } elseif ($lead && isset($_POST['summary_now'])) {
        $_SESSION['aw_mt_flash'] = aw_weekly_summary_send($db, true) ? 'This week’s summary was emailed to the owner.' : 'The summary could not be emailed. Check Admin > Settings > Email.';
        header('Location: ' . SITE_URL . '/admin/meetings.php#summary'); exit;
    }
}
if (!empty($_SESSION['aw_mt_flash'])) { $notice = (string)$_SESSION['aw_mt_flash']; unset($_SESSION['aw_mt_flash']); }

$upcoming = aw_meetings_for($db, $me, $lead, false);
$past = $lead ? aw_meetings_for($db, $me, true, true) : [];
$checkins = $lead ? aw_checkins_recent($db, 60) : [];
$kinds = ['training' => 'Training', 'catchup' => 'Catch-up', 'prayer' => 'Prayer time'];
$aud_label = function ($a) { return $a === 'all' ? 'Whole ' . aw_team_name() : (AW_POSITIONS[$a]['title'] ?? $a); };

$pageTitle = 'Team meetings';
require_once '../includes/header.php';
?>
<div class="admin-page">
<div class="container" style="max-width:1000px;">
    <div class="admin-header">
        <div><h1>Team meetings</h1><p>Training and time together for the <?php echo htmlspecialchars(aw_team_name()); ?>.<?php echo $lead ? ' Check-ins and the weekly summary are below.' : ''; ?></p></div>
        <div class="admin-actions"><?php echo aw_link_btn(SITE_URL . '/admin/team.php', 'Team desk', 'outline', ['icon' => 'fa-hands-holding-circle']); ?></div>
    </div>
    <?php echo aw_alert('error', $error); echo aw_alert('success', $notice); ?>

    <section class="card" style="margin-bottom:20px;"><div class="card-body">
        <h2 class="mt-h2">Coming up</h2>
        <?php if (!$upcoming): ?><p class="no-items">No meetings planned.<?php echo $lead ? ' Schedule one below.' : ''; ?></p><?php endif; ?>
        <?php foreach ($upcoming as $m): $when = aw_event_local($m['starts_at']); $cx = !empty($m['cancelled_at']);
            $yes = array_values(array_filter($m['rsvps'], function ($r) { return $r['answer'] === 'yes'; })); ?>
            <article class="mt-item<?php echo $cx ? ' is-cancelled' : ''; ?>" id="m<?php echo (int)$m['id']; ?>">
                <div class="mt-date" aria-hidden="true"><span><?php echo $when->format('M'); ?></span><strong><?php echo $when->format('j'); ?></strong></div>
                <div class="mt-body">
                    <h3><?php echo htmlspecialchars($m['title']); ?> <span class="mt-tag"><?php echo htmlspecialchars($kinds[$m['kind']] ?? ''); ?></span><?php if ($cx): ?> <span class="mt-tag is-x">Cancelled</span><?php endif; ?></h3>
                    <p class="mt-meta"><?php echo htmlspecialchars($when->format('l j F Y, H:i') . ' (' . aw_event_tz_label() . ') · ' . (int)$m['minutes'] . ' min · ' . $aud_label($m['audience'])); ?></p>
                    <?php if (trim((string)$m['place']) !== ''): ?><p class="mt-meta"><i class="fas fa-location-dot" aria-hidden="true"></i> <?php echo htmlspecialchars($m['place']); ?></p><?php endif; ?>
                    <?php if (trim((string)$m['link']) !== '' && !$cx): ?><p class="mt-meta"><i class="fas fa-video" aria-hidden="true"></i> <a href="<?php echo htmlspecialchars($m['link']); ?>" target="_blank" rel="noopener">Join online</a></p><?php endif; ?>
                    <?php if (trim((string)$m['agenda']) !== ''): ?><details class="mt-agenda"><summary>Agenda</summary><p><?php echo nl2br(htmlspecialchars($m['agenda'])); ?></p></details><?php endif; ?>
                    <?php if (!$cx): ?>
                    <form method="POST" class="mt-rsvp">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                        <input type="hidden" name="meeting_id" value="<?php echo (int)$m['id']; ?>">
                        <span>Can you come?</span>
                        <?php foreach (['yes' => 'Yes', 'maybe' => 'Maybe', 'no' => 'No'] as $k => $l): ?>
                            <button type="submit" name="rsvp" value="<?php echo $k; ?>" class="mt-pill<?php echo $m['my'] === $k ? ' is-on' : ''; ?>" aria-pressed="<?php echo $m['my'] === $k ? 'true' : 'false'; ?>"><?php echo $l; ?></button>
                        <?php endforeach; ?>
                    </form>
                    <?php endif; ?>
                    <?php if ($lead): ?>
                        <p class="mt-meta"><strong><?php echo count($yes); ?> coming</strong><?php echo $yes ? ': ' . htmlspecialchars(implode(', ', array_map('awDisplayName', $yes))) : ''; ?> · <?php echo count($m['rsvps']); ?> of <?php echo count(aw_meeting_invitees($db, $m['audience'])); ?> answered</p>
                        <?php if (!$cx): ?><form method="POST" onsubmit="return confirm('Cancel this meeting and tell everyone invited?');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>"><input type="hidden" name="meeting_id" value="<?php echo (int)$m['id']; ?>"><?php echo aw_btn('Cancel meeting', 'outline', ['type' => 'submit', 'name' => 'cancel', 'value' => '1', 'small' => true]); ?></form><?php endif; ?>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div></section>

    <?php if ($lead): ?>
    <section class="card" style="margin-bottom:20px;" id="new"><div class="card-body">
        <h2 class="mt-h2">Schedule a meeting</h2>
        <form method="POST" class="admin-form mt-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
            <div class="mt-grid">
                <div class="form-group mt-wide"><label for="mtTitle">Title</label><input type="text" id="mtTitle" name="title" maxlength="120" required placeholder="e.g. Welcome training for new prayer team members" value="<?php echo htmlspecialchars((string)($_POST['title'] ?? '')); ?>"></div>
                <div class="form-group"><label for="mtKind">Kind</label><select id="mtKind" name="kind"><?php foreach ($kinds as $k => $l): ?><option value="<?php echo $k; ?>"<?php echo ($_POST['kind'] ?? '') === $k ? ' selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label for="mtAud">Who is invited</label><select id="mtAud" name="audience"><option value="all">Whole <?php echo htmlspecialchars(aw_team_name()); ?></option><?php foreach (AW_POSITIONS as $k => $p): ?><option value="<?php echo $k; ?>"<?php echo ($_POST['audience'] ?? '') === $k ? ' selected' : ''; ?>><?php echo htmlspecialchars($p['title']); ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label for="mtDate">Date</label><input type="date" id="mtDate" name="date" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars((string)($_POST['date'] ?? '')); ?>"></div>
                <div class="form-group"><label for="mtTime">Time (<?php echo htmlspecialchars(aw_event_tz_label()); ?>)</label><input type="time" id="mtTime" name="time" required value="<?php echo htmlspecialchars((string)($_POST['time'] ?? '18:00')); ?>"></div>
                <div class="form-group"><label for="mtMin">Length (minutes)</label><input type="number" id="mtMin" name="minutes" min="15" max="480" step="15" value="<?php echo (int)($_POST['minutes'] ?? 60); ?>"></div>
                <div class="form-group"><label for="mtPlace">Place <small>(or leave empty for online only)</small></label><input type="text" id="mtPlace" name="place" maxlength="200" value="<?php echo htmlspecialchars((string)($_POST['place'] ?? '')); ?>"></div>
                <div class="form-group mt-wide"><label for="mtLink">Online link <small>(Google Meet, Zoom, WhatsApp group call…)</small></label><input type="url" id="mtLink" name="link" placeholder="https://" value="<?php echo htmlspecialchars((string)($_POST['link'] ?? '')); ?>"></div>
                <div class="form-group mt-wide"><label for="mtAgenda">Agenda <small>(optional)</small></label><textarea id="mtAgenda" name="agenda" rows="4" maxlength="3000" placeholder="Welcome and prayer&#10;Safeguarding: what to do if someone may be at risk&#10;Your role, questions"><?php echo htmlspecialchars((string)($_POST['agenda'] ?? '')); ?></textarea></div>
            </div>
            <?php echo aw_btn('Schedule and invite', 'primary', ['type' => 'submit', 'name' => 'create', 'value' => '1', 'icon' => 'fa-calendar-plus']); ?>
            <p class="mt-meta" style="margin-top:8px;">Everyone invited is told on the site and by email (with a calendar file), and reminded the day before.</p>
        </form>
    </div></section>

    <section class="card" style="margin-bottom:20px;" id="checkins"><div class="card-body">
        <h2 class="mt-h2">Check-ins</h2>
        <p class="mt-meta">Every 30 days each person on the <?php echo htmlspecialchars(aw_team_name()); ?> is asked how their role is going.</p>
        <?php if (!$checkins): ?><p class="no-items">No check-ins in the last two months.</p><?php else: ?>
        <div class="table-responsive"><table class="admin-table">
            <thead><tr><th scope="col">Person</th><th scope="col">Asked</th><th scope="col">Answer</th></tr></thead>
            <tbody><?php foreach ($checkins as $c): ?>
                <tr><td><?php echo htmlspecialchars(awDisplayName($c)); ?></td><td><?php echo htmlspecialchars(date('j M', strtotime($c['sent_at'] . ' UTC'))); ?></td>
                    <td><?php echo $c['answer'] ? '<strong>' . htmlspecialchars(AW_CHECKIN_ANSWERS[$c['answer']] ?? $c['answer']) . '</strong>' . ($c['note'] ? '<br><small>“' . nl2br(htmlspecialchars($c['note'])) . '”</small>' : '') : '<span class="mt-meta">Not answered yet</span>'; ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </div></section>

    <section class="card" style="margin-bottom:20px;" id="summary"><div class="card-body">
        <h2 class="mt-h2">Weekly summary email</h2>
        <?php $sum_on = (string)aw_setting('weekly_summary_enabled', '1') !== '0'; $sum_last = (string)aw_setting('weekly_summary_last', ''); ?>
        <p class="mt-meta">Once a week the owner gets one email: new members, what is waiting, what the team did, check-in answers and coming meetings. <?php echo $sum_on ? 'On.' : 'Off.'; ?><?php echo $sum_last !== '' ? ' Last sent ' . htmlspecialchars(date('j M Y', strtotime($sum_last))) . '.' : ''; ?></p>
        <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
            <?php echo aw_btn($sum_on ? 'Turn off' : 'Turn on', 'outline', ['type' => 'submit', 'name' => 'summary_switch', 'value' => $sum_on ? '0' : '1', 'small' => true]); ?>
            <?php echo aw_btn('Email this week’s summary now', 'primary', ['type' => 'submit', 'name' => 'summary_now', 'value' => '1', 'small' => true]); ?>
        </form>
    </div></section>

    <?php if ($past): ?>
    <section class="card"><div class="card-body">
        <h2 class="mt-h2">Past meetings</h2>
        <ul class="mt-past"><?php foreach ($past as $m): $y = count(array_filter($m['rsvps'], function ($r) { return $r['answer'] === 'yes'; })); ?>
            <li><?php echo htmlspecialchars(aw_event_local($m['starts_at'])->format('j M Y') . ' · ' . $m['title']); ?><?php echo !empty($m['cancelled_at']) ? ' (cancelled)' : ' · ' . $y . ' said yes'; ?></li>
        <?php endforeach; ?></ul>
    </div></section>
    <?php endif; ?>
    <?php endif; ?>
</div>
</div>
<style>
.mt-h2{font-size:1.2rem;margin:0 0 12px}
.mt-item{display:flex;gap:16px;padding:16px 0;border-top:1px solid var(--border)}
.mt-item:first-of-type{border-top:0}
.mt-item.is-cancelled{opacity:.7}
.mt-date{flex:none;width:58px;height:62px;border-radius:14px;background:#9c5457;color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;line-height:1.1}
.mt-date span{font-size:.75rem;text-transform:uppercase;letter-spacing:.05em}.mt-date strong{font-size:1.5rem}
.mt-body{flex:1;min-width:0}.mt-body h3{margin:0 0 4px;font-size:1.08rem}
.mt-tag{display:inline-block;font-size:.75rem;font-weight:600;padding:2px 8px;border-radius:999px;background:rgba(219,161,162,.25);color:#7a3e41;vertical-align:middle}
.mt-tag.is-x{background:#f1ecec;color:#5b4747}
.mt-meta{color:var(--text-light);margin:2px 0;font-size:.92rem}
.mt-agenda summary{cursor:pointer;font-weight:600;margin:6px 0}
.mt-rsvp{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0 6px}
.mt-pill{min-height:40px;padding:0 16px;border-radius:999px;border:1px solid #9c5457;background:transparent;color:#9c5457;font-weight:600;cursor:pointer;font:inherit}
.mt-pill.is-on{background:#9c5457;color:#fff}
.mt-pill:focus-visible{outline:3px solid #3d2e2e;outline-offset:2px}
.mt-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 14px}.mt-wide{grid-column:1/-1}
.mt-form input,.mt-form select,.mt-form textarea{width:100%;box-sizing:border-box;min-height:44px}
.mt-past{margin:0;padding-left:20px;line-height:1.7}
[data-theme="dark"] .mt-tag{background:#4a3133;color:#f3d6d7}[data-theme="dark"] .mt-pill{border-color:#f0b9ba;color:#f0b9ba}[data-theme="dark"] .mt-pill.is-on{background:#c97c7f;color:#1f1516}
</style>
<?php require_once '../includes/footer.php'; ?>
