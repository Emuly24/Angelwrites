<?php
/**
 * Admin > Events (includes/events.php): plan events, publish or cancel them, see who is coming and
 * send everyone going a short note.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/events.php';
require_once '../includes/notifications.php';

aw_require('events_team'); // checks the CSRF token on every POST
aw_event_tables($db);
$ea_back = SITE_URL . '/admin/events.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $act = (string)($_POST['act'] ?? 'save');
    $flash = ['ok', 'Saved.'];
    try {
        if ($act === 'save') {
            $title = trim(mb_substr(strip_tags((string)($_POST['title'] ?? '')), 0, 140));
            $when = aw_event_to_utc(trim((string)($_POST['date'] ?? '')) . ' ' . trim((string)($_POST['time'] ?? '')));
            $link = trim((string)($_POST['online_link'] ?? ''));
            if ($link !== '' && !preg_match('#^https://#i', $link)) $link = '';
            $vals = [$title, isset(AW_EVENT_KINDS[$_POST['kind'] ?? '']) ? $_POST['kind'] : 'other', trim(mb_substr(strip_tags(str_replace("\r\n", "\n", (string)($_POST['description'] ?? ''))), 0, 5000)),
                     $when, max(15, min(600, (int)($_POST['duration_min'] ?? 60))), trim(mb_substr(strip_tags((string)($_POST['place'] ?? '')), 0, 200)), $link, max(0, (int)($_POST['capacity'] ?? 0))];
            if ($title === '' || $when === '') $flash = ['error', 'An event needs a title, a date and a time.'];
            elseif ($id) {
                $old = aw_event_get($db, $id);
                $db->prepare("UPDATE events SET title = ?, kind = ?, description = ?, starts_at = ?, duration_min = ?, place = ?, online_link = ?, capacity = ?, updated_at = CURRENT_TIMESTAMP" . ($old && $old['starts_at'] !== $when ? ", reminded_at = NULL" : "") . " WHERE id = ?")->execute(array_merge($vals, [$id]));
                if ($old && $old['starts_at'] !== $when && $old['status'] === 'published') {
                    // a new time: everyone signed up is told
                    $st = $db->prepare("SELECT user_id FROM event_signups WHERE event_id = ?"); $st->execute([$id]);
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) awNotify($db, (int)$u, 'event_changed', 'New time for "' . $title . '"', aw_event_local($when)->format('l j F, H:i') . ' ' . aw_event_tz_label(), SITE_URL . '/events.php?id=' . $id, ['email' => true]);
                }
                if ($old) { $e2 = aw_event_get($db, $id); if ($e2) aw_event_promote($db, $e2); } // more places: the waitlist moves up
            } else { $db->prepare("INSERT INTO events (title, kind, description, starts_at, duration_min, place, online_link, capacity, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute(array_merge($vals, [!empty($_POST['publish']) ? 'published' : 'draft', (int)$_SESSION['user_id']])); $id = (int)$db->lastInsertId(); }
        } elseif ($act === 'publish' || $act === 'draft') { $db->prepare("UPDATE events SET status = ? WHERE id = ?")->execute([$act === 'publish' ? 'published' : 'draft', $id]); $flash = ['ok', $act === 'publish' ? 'Published.' : 'Back to draft.']; }
        elseif ($act === 'cancel') {
            $e = aw_event_get($db, $id);
            $db->prepare("UPDATE events SET status = 'cancelled' WHERE id = ?")->execute([$id]);
            $st = $db->prepare("SELECT user_id FROM event_signups WHERE event_id = ?"); $st->execute([$id]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) awNotify($db, (int)$u, 'event_cancelled', 'Cancelled: ' . ($e['title'] ?? 'an event'), trim((string)($_POST['note'] ?? '')) ?: 'We are sorry: this event will not take place.', SITE_URL . '/events.php?id=' . $id, ['email' => true]);
            $flash = ['ok', 'Cancelled; everyone signed up has been told.'];
        } elseif ($act === 'note') {
            $note = trim(mb_substr(strip_tags((string)($_POST['note'] ?? '')), 0, 500));
            $e = aw_event_get($db, $id);
            if ($note !== '' && $e) { $st = $db->prepare("SELECT user_id FROM event_signups WHERE event_id = ? AND status = 'going'"); $st->execute([$id]); $n = 0;
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) { awNotify($db, (int)$u, 'event_note', 'About "' . $e['title'] . '"', $note, SITE_URL . '/events.php?id=' . $id, ['email' => !empty($_POST['email'])]); $n++; }
                $flash = ['ok', 'Sent to ' . $n . ' ' . ($n === 1 ? 'person' : 'people') . '.']; }
        } elseif ($act === 'delete') { $db->prepare("DELETE FROM events WHERE id = ?")->execute([$id]); $flash = ['ok', 'Deleted.']; $id = 0; }
        if (function_exists('aw_audit')) aw_audit('event_' . $act, 'event', $id, '');
    } catch (Throwable $e) { error_log('events admin: ' . $e->getMessage()); $flash = ['error', 'That could not be saved.']; }
    $_SESSION['aw_ea_flash'] = $flash;
    header('Location: ' . $ea_back . ($id ? '?id=' . $id : '')); exit;
}
$ea_flash = $_SESSION['aw_ea_flash'] ?? null; unset($_SESSION['aw_ea_flash']);
$ea_one = isset($_GET['id']) ? aw_event_get($db, (int)$_GET['id']) : null;
$ea_new = isset($_GET['new']) || (!$ea_one && !aw_event_list($db, 0, 'all', true));
$ea_people = [];
if ($ea_one) { $st = $db->prepare("SELECT s.status, s.created_at, u.name, u.first_name, u.last_name, u.username, u.country FROM event_signups s JOIN users u ON u.id = s.user_id WHERE s.event_id = ? ORDER BY s.status, s.created_at"); $st->execute([(int)$ea_one['id']]); $ea_people = $st->fetchAll(PDO::FETCH_ASSOC); }

$pageTitle = 'Events';
require_once '../includes/header.php';
$form = function ($e) {
    $d = $e ? aw_event_local($e['starts_at']) : new DateTime('next saturday 17:00', aw_event_tz());
    ob_start(); ?>
    <form method="post" class="ea-form"><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?php echo (int)($e['id'] ?? 0); ?>">
        <div class="ea-row"><label>Title<input type="text" name="title" maxlength="140" required value="<?php echo htmlspecialchars((string)($e['title'] ?? '')); ?>" placeholder="e.g. Poetry evening: readings from The Beautiful Broken Vessel"></label>
            <label>Kind<select name="kind"><?php foreach (AW_EVENT_KINDS as $k => $v): ?><option value="<?php echo $k; ?>"<?php echo ($e['kind'] ?? '') === $k ? ' selected' : ''; ?>><?php echo htmlspecialchars($v[0]); ?></option><?php endforeach; ?></select></label></div>
        <div class="ea-row ea-row-4"><label>Date<input type="date" name="date" required value="<?php echo $d->format('Y-m-d'); ?>"></label><label>Time (<?php echo htmlspecialchars(aw_event_tz_label()); ?>)<input type="time" name="time" required value="<?php echo $d->format('H:i'); ?>"></label>
            <label>Length (minutes)<input type="number" name="duration_min" min="15" max="600" step="15" value="<?php echo (int)($e['duration_min'] ?? 60); ?>"></label><label>Places <small>(0 = no limit)</small><input type="number" name="capacity" min="0" value="<?php echo (int)($e['capacity'] ?? 0); ?>"></label></div>
        <div class="ea-row"><label>Where <small>(a place; leave empty for online)</small><input type="text" name="place" maxlength="200" value="<?php echo htmlspecialchars((string)($e['place'] ?? '')); ?>"></label>
            <label>Online link <small>(https://…, shown only to people going)</small><input type="url" name="online_link" maxlength="400" value="<?php echo htmlspecialchars((string)($e['online_link'] ?? '')); ?>" placeholder="https://meet.google.com/…"></label></div>
        <label>About the event<textarea name="description" rows="5" maxlength="5000"><?php echo htmlspecialchars((string)($e['description'] ?? '')); ?></textarea></label>
        <?php if (!$e): ?><label class="ea-check"><input type="checkbox" name="publish" value="1" checked> Publish now</label><?php endif; ?>
        <button class="btn btn-primary"><?php echo $e ? 'Save changes' : 'Create the event'; ?></button>
    </form>
    <?php return ob_get_clean();
};
?>
<div class="ea">
    <header class="ea-head"><div><h1>Events</h1><p>Plan live readings, prayer meetings, workshops and book club meetings. Members sign up, get a calendar file and a reminder the day before; the online link is shown only to people going.</p></div>
        <div><?php if ($ea_one || $ea_new): ?><a class="btn btn-outline" href="<?php echo $ea_back; ?>">All events</a><?php else: ?><a class="btn btn-primary" href="?new=1"><i class="fas fa-plus" aria-hidden="true"></i> New event</a><?php endif; ?></div></header>
    <?php if ($ea_flash): ?><div class="alert <?php echo $ea_flash[0] === 'ok' ? 'alert-success' : 'alert-error'; ?>" role="status"><?php echo htmlspecialchars($ea_flash[1]); ?></div><?php endif; ?>

    <?php if ($ea_new): ?><section class="ea-card"><h2>A new event</h2><?php echo $form(null); ?></section>
    <?php elseif ($ea_one): $e = $ea_one; ?>
        <section class="ea-card"><h2><?php echo htmlspecialchars($e['title']); ?> <span class="ea-badge is-<?php echo htmlspecialchars($e['status']); ?>"><?php echo ucfirst($e['status']); ?></span></h2>
            <p class="ea-sub"><?php echo htmlspecialchars(aw_event_local($e['starts_at'])->format('l j F Y, H:i') . ' ' . aw_event_tz_label()); ?> · <?php echo (int)$e['going']; ?> going<?php echo (int)$e['waiting'] ? ' · ' . (int)$e['waiting'] . ' waiting' : ''; ?> · <a href="<?php echo SITE_URL; ?>/events.php?id=<?php echo (int)$e['id']; ?>" target="_blank" rel="noopener">Open the event page</a></p>
            <?php echo $form($e); ?>
            <form method="post" class="ea-acts"><input type="hidden" name="id" value="<?php echo (int)$e['id']; ?>">
                <?php if ($e['status'] !== 'cancelled'): ?><button class="btn btn-outline btn-sm" name="act" value="<?php echo $e['status'] === 'published' ? 'draft' : 'publish'; ?>"><?php echo $e['status'] === 'published' ? 'Back to draft' : 'Publish'; ?></button><?php endif; ?>
                <?php if ($e['status'] === 'published'): ?><button class="btn btn-outline btn-sm" name="act" value="cancel" onclick="return confirm('Cancel this event? Everyone signed up will be told.');">Cancel the event</button><?php endif; ?>
                <button class="btn btn-danger btn-sm" name="act" value="delete" onclick="return confirm('Delete this event for good?');">Delete</button>
            </form>
        </section>
        <section class="ea-card"><h2>Who is coming</h2>
            <?php if (!$ea_people): ?><p class="ea-sub">Nobody yet.</p><?php else: ?><ol class="ea-people"><?php foreach ($ea_people as $p): ?><li><?php echo htmlspecialchars(awDisplayName($p)); ?><?php echo $p['country'] ? ' <small>' . htmlspecialchars($p['country']) . '</small>' : ''; ?><?php echo $p['status'] === 'waitlist' ? ' <span class="ea-badge">waitlist</span>' : ''; ?></li><?php endforeach; ?></ol><?php endif; ?>
            <form method="post" class="ea-form" style="margin-top:12px;"><input type="hidden" name="id" value="<?php echo (int)$e['id']; ?>"><input type="hidden" name="act" value="note">
                <label>A note to everyone going<textarea name="note" rows="2" maxlength="500" placeholder="e.g. We start 10 minutes early for prayer."></textarea></label>
                <label class="ea-check"><input type="checkbox" name="email" value="1"> Also send it by email</label><button class="btn btn-sm btn-primary">Send</button></form>
        </section>
    <?php else: ?>
        <?php foreach ([['upcoming', 'Coming up'], ['past', 'Past']] as [$w, $label]): $rows = aw_event_list($db, 0, $w, true); if (!$rows) continue; ?>
        <section class="ea-card"><h2><?php echo $label; ?></h2>
            <?php foreach ($rows as $e): ?><a class="ea-item" href="?id=<?php echo (int)$e['id']; ?>"><span class="ea-badge is-<?php echo htmlspecialchars($e['status']); ?>"><?php echo ucfirst($e['status']); ?></span> <strong><?php echo htmlspecialchars($e['title']); ?></strong> <small><?php echo htmlspecialchars(aw_event_local($e['starts_at'])->format('D j M Y, H:i')); ?> · <?php echo (int)$e['going']; ?> going</small></a><?php endforeach; ?>
        </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<style>
.ea { max-width: 980px; margin: 0 auto; padding: 24px 16px 64px; color: var(--text); }
.ea-head { display: flex; justify-content: space-between; gap: 14px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 16px; } .ea-head h1 { margin: 0 0 4px; font-family: 'Playfair Display', Georgia, serif; } .ea-head p { margin: 0; color: var(--text-light); max-width: 46em; }
.ea-card { background: var(--card-bg, #fff); border: 1px solid var(--border); border-radius: 20px; padding: 18px; margin-bottom: 14px; } .ea-card h2 { margin: 0 0 10px; font-size: 1.15rem; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.ea-sub { color: var(--text-light); margin: 0 0 12px; } .ea-sub a { color: #9c5457; }
.ea-form { display: grid; gap: 12px; } .ea-form label { display: grid; gap: 6px; font-weight: 600; font-size: .92rem; } .ea-form small { font-weight: 400; color: var(--text-light); }
.ea-form input:not([type=checkbox]), .ea-form textarea, .ea-form select { width: 100%; padding: 10px 12px; border-radius: 12px; font: inherit; font-weight: 400; }
.ea-row { display: grid; grid-template-columns: 2fr 1fr; gap: 10px; } .ea-row-4 { grid-template-columns: repeat(4, 1fr); }
.ea-check { display: flex !important; gap: 8px; align-items: center; font-weight: 500 !important; } .ea-check input { accent-color: #9c5457; width: 18px; height: 18px; }
.ea-acts { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 12px; }
.ea-badge { padding: 2px 10px; border-radius: 999px; background: #f1ebe4; color: #6b5a48; font-size: .74rem; font-weight: 700; } .ea-badge.is-published { background: #e3efe6; color: #24452f; } .ea-badge.is-cancelled { background: #f6e1e3; color: #6e2a31; }
.ea-item { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 10px 0; border-top: 1px solid var(--border); color: inherit; text-decoration: none; } .ea-item:first-of-type { border-top: 0; } .ea-item small { color: var(--text-light); } .ea-item:hover strong { color: #9c5457; }
.ea-people { margin: 0; padding-left: 20px; line-height: 1.9; } .ea-people small { color: var(--text-light); }
@media (max-width: 700px) { .ea-row, .ea-row-4 { grid-template-columns: 1fr; } }
</style>
<?php require_once '../includes/footer.php'; ?>
