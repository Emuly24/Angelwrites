<?php
/**
 * Events (includes/events.php): what is coming up, sign up, add to calendar, join online.
 *   events.php          upcoming events (and recent past ones)
 *   events.php?id=4     one event
 *   events.php?ics=4    its calendar file
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/events.php';
if (is_file(__DIR__ . '/includes/notifications.php')) require_once 'includes/notifications.php';

$ev_uid = (int)($_SESSION['user_id'] ?? 0);
$ev_self = SITE_URL . '/events.php';
aw_event_tables($db);
$ev_team = function_exists('aw_can') && aw_can('events_team');

if (isset($_GET['ics'])) {
    $e = aw_event_get($db, (int)$_GET['ics']);
    if ($e && ($e['status'] === 'published' || $ev_team)) {
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="angelwrites-event-' . (int)$e['id'] . '.ics"');
        echo aw_event_ics($e); exit;
    }
    header('Location: ' . $ev_self); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$ev_uid) { header('Location: ' . SITE_URL . '/login.php?redirect=' . urlencode('/events.php?id=' . $id)); exit; }
    $msg = ['error', 'This page had been open a long time. Please try again.'];
    if (validate_csrf_token($_POST['csrf_token'] ?? '')) {
        if (($_POST['action'] ?? '') === 'join') { $r = aw_event_join($db, $ev_uid, $id); $msg = [$r['ok'] ? 'ok' : 'error', $r['message']]; }
        elseif (($_POST['action'] ?? '') === 'leave') { $msg = aw_event_leave($db, $ev_uid, $id) ? ['ok', 'You are no longer signed up. Your place has gone to the next person.'] : ['error', 'That did not work.']; }
    }
    $_SESSION['aw_ev_flash'] = $msg;
    header('Location: ' . $ev_self . '?id=' . $id); exit;
}
$ev_flash = $_SESSION['aw_ev_flash'] ?? null; unset($_SESSION['aw_ev_flash']);
$ev_one = isset($_GET['id']) ? aw_event_get($db, (int)$_GET['id'], $ev_uid) : null;
if ($ev_one && $ev_one['status'] === 'draft' && !$ev_team) $ev_one = null;
$ev_kind = (string)($_GET['kind'] ?? '');

/** The date block and both times (site time, and the reader's own, filled in by the script below). */
function aw_ev_when(array $e) {
    $d = aw_event_local($e['starts_at']);
    $end = (clone $d)->modify('+' . (int)$e['duration_min'] . ' minutes');
    return '<time class="ev-time" datetime="' . htmlspecialchars(gmdate('c', strtotime($e['starts_at'] . ' UTC'))) . '" data-utc="' . htmlspecialchars(gmdate('c', strtotime($e['starts_at'] . ' UTC'))) . '" data-min="' . (int)$e['duration_min'] . '">'
         . htmlspecialchars($d->format('l j F, H:i') . '–' . $end->format('H:i') . ' ' . aw_event_tz_label()) . '</time><span class="ev-local" hidden></span>';
}
function aw_ev_badge(array $e) {
    $d = aw_event_local($e['starts_at']);
    return '<span class="ev-date" aria-hidden="true"><b>' . $d->format('j') . '</b><small>' . $d->format('M') . '</small></span>';
}

$pageTitle = $ev_one ? $ev_one['title'] : 'Events';
require_once 'includes/header.php';
$token = $ev_uid ? generate_csrf_token() : '';
?>
<main class="ev">
<?php if ($ev_one): $e = $ev_one; $k = AW_EVENT_KINDS[$e['kind']] ?? AW_EVENT_KINDS['other']; $past = strtotime($e['starts_at'] . ' UTC') + 60 * (int)$e['duration_min'] < time();
      $full = (int)$e['capacity'] > 0 && (int)$e['going'] >= (int)$e['capacity']; ?>
    <a class="ev-back" href="<?php echo $ev_self; ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> All events</a>
    <article class="ev-detail">
        <header class="ev-hero">
            <?php echo aw_ev_badge($e); ?>
            <div><p class="ev-kicker"><i class="fas <?php echo $k[1]; ?>" aria-hidden="true"></i> <?php echo htmlspecialchars($k[0]); ?><?php echo $e['status'] === 'cancelled' ? ' · <b>Cancelled</b>' : ($e['status'] === 'draft' ? ' · Draft' : ''); ?></p>
                <h1><?php echo htmlspecialchars($e['title']); ?></h1>
                <p class="ev-when"><i class="far fa-clock" aria-hidden="true"></i> <?php echo aw_ev_when($e); ?></p>
                <p class="ev-where"><i class="fas <?php echo trim((string)$e['place']) !== '' ? 'fa-location-dot' : 'fa-video'; ?>" aria-hidden="true"></i> <?php echo htmlspecialchars(trim((string)$e['place']) !== '' ? $e['place'] : 'Online'); ?></p></div>
        </header>
        <?php if ($ev_flash): ?><div class="ev-alert <?php echo $ev_flash[0] === 'ok' ? 'is-ok' : 'is-error'; ?>" role="status"><?php echo htmlspecialchars($ev_flash[1]); ?></div><?php endif; ?>
        <div class="ev-body">
            <div class="ev-text"><?php echo nl2br(htmlspecialchars((string)$e['description'])); ?></div>
            <aside class="ev-side">
                <p class="ev-count"><b><?php echo (int)$e['going']; ?></b> going<?php echo (int)$e['capacity'] > 0 ? ' · ' . max(0, (int)$e['capacity'] - (int)$e['going']) . ' places left' : ''; ?><?php echo (int)$e['waiting'] ? ' · ' . (int)$e['waiting'] . ' waiting' : ''; ?></p>
                <?php if ($e['status'] === 'cancelled'): ?><p class="ev-note">This event was cancelled. We are sorry for the change.</p>
                <?php elseif ($past): ?><p class="ev-note">This event has taken place. Thank you to everyone who came.</p>
                <?php elseif (!$ev_uid): ?><a class="ev-btn ev-btn-primary" href="<?php echo SITE_URL; ?>/login.php?redirect=<?php echo urlencode('/events.php?id=' . (int)$e['id']); ?>">Log in to sign up</a>
                <?php else: ?>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="id" value="<?php echo (int)$e['id']; ?>">
                    <?php if ($e['mine'] === 'going'): ?><p class="ev-going"><i class="fas fa-circle-check" aria-hidden="true"></i> You are going</p><button class="ev-btn" name="action" value="leave" onclick="return confirm('Give up your place?');">I can no longer come</button>
                    <?php elseif ($e['mine'] === 'waitlist'): ?><p class="ev-going is-wait"><i class="fas fa-hourglass-half" aria-hidden="true"></i> You are on the waitlist</p><button class="ev-btn" name="action" value="leave">Leave the waitlist</button>
                    <?php else: ?><button class="ev-btn ev-btn-primary" name="action" value="join"><?php echo $full ? 'Join the waitlist' : 'I will come'; ?></button><?php endif; ?>
                    </form>
                <?php endif; ?>
                <?php if (!$past && $e['status'] !== 'cancelled'): ?><a class="ev-btn" href="<?php echo $ev_self; ?>?ics=<?php echo (int)$e['id']; ?>"><i class="far fa-calendar-plus" aria-hidden="true"></i> Add to my calendar</a><?php endif; ?>
                <?php if (trim((string)$e['online_link']) !== '' && ($e['mine'] === 'going' || $ev_team) && !$past && $e['status'] !== 'cancelled'): ?>
                    <div class="ev-join"><p><b>How to join</b></p><a class="ev-btn ev-btn-primary" href="<?php echo htmlspecialchars($e['online_link']); ?>" target="_blank" rel="noopener"><i class="fas fa-video" aria-hidden="true"></i> Join online</a><small>Only people who are going see this link.</small></div>
                <?php elseif (trim((string)$e['online_link']) !== '' && !$past): ?><p class="ev-note">The link to join appears here once you sign up.</p><?php endif; ?>
                <?php if ($ev_team): ?><a class="ev-link" href="<?php echo SITE_URL; ?>/admin/events.php?id=<?php echo (int)$e['id']; ?>"><i class="fas fa-pen" aria-hidden="true"></i> Edit or see who is coming</a><?php endif; ?>
            </aside>
        </div>
    </article>
<?php else: $up = aw_event_list($db, $ev_uid, 'upcoming', $ev_team); $past = aw_event_list($db, $ev_uid, 'past');
      if (isset(AW_EVENT_KINDS[$ev_kind])) { $up = array_values(array_filter($up, function ($x) use ($ev_kind) { return $x['kind'] === $ev_kind; })); } ?>
    <header class="ev-head">
        <p class="ev-kicker"><i class="fas fa-calendar-days" aria-hidden="true"></i> Events</p>
        <h1>Gather with us</h1>
        <p class="ev-lead">Live readings, prayer meetings, workshops and book club meetings, online and in person. Times are shown in <?php echo htmlspecialchars(aw_event_tz_label()); ?> and in your own time.</p>
    </header>
    <nav class="ev-kinds" aria-label="Show"><a href="<?php echo $ev_self; ?>"<?php echo $ev_kind === '' ? ' class="is-on"' : ''; ?>>All</a><?php foreach (AW_EVENT_KINDS as $kk => $kv): ?><a href="?kind=<?php echo $kk; ?>"<?php echo $ev_kind === $kk ? ' class="is-on"' : ''; ?>><?php echo htmlspecialchars($kv[0]); ?></a><?php endforeach; ?></nav>
    <?php if (!$up): ?><div class="ev-empty"><i class="fas fa-calendar-plus" aria-hidden="true"></i><p>No events planned right now. New ones appear here (and in your notifications if you are signed up for one).</p></div><?php endif; ?>
    <div class="ev-list">
        <?php foreach ($up as $i => $e): $k = AW_EVENT_KINDS[$e['kind']] ?? AW_EVENT_KINDS['other']; ?>
        <a class="ev-card<?php echo $e['status'] !== 'published' ? ' is-' . htmlspecialchars($e['status']) : ''; ?>" href="<?php echo $ev_self; ?>?id=<?php echo (int)$e['id']; ?>" style="--i:<?php echo min($i, 10); ?>">
            <?php echo aw_ev_badge($e); ?>
            <span class="ev-card-main"><span class="ev-kicker"><i class="fas <?php echo $k[1]; ?>" aria-hidden="true"></i> <?php echo htmlspecialchars($k[0]); ?><?php echo $e['status'] === 'cancelled' ? ' · Cancelled' : ($e['status'] === 'draft' ? ' · Draft' : ''); ?></span>
                <strong><?php echo htmlspecialchars($e['title']); ?></strong>
                <span class="ev-meta"><?php echo aw_ev_when($e); ?></span>
                <span class="ev-meta"><?php echo htmlspecialchars(trim((string)$e['place']) !== '' ? $e['place'] : 'Online'); ?> · <?php echo (int)$e['going']; ?> going<?php echo $e['mine'] === 'going' ? ' · <b>you are going</b>' : ($e['mine'] === 'waitlist' ? ' · you are on the waitlist' : ''); ?></span></span>
            <i class="fas fa-chevron-right ev-go" aria-hidden="true"></i>
        </a>
        <?php endforeach; ?>
    </div>
    <?php if ($past): ?><h2 class="ev-past-h">Recent events</h2><ul class="ev-past"><?php foreach (array_slice($past, 0, 8) as $e): ?><li><a href="<?php echo $ev_self; ?>?id=<?php echo (int)$e['id']; ?>"><?php echo htmlspecialchars($e['title']); ?></a> <small><?php echo aw_event_local($e['starts_at'])->format('j M Y'); ?></small></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($ev_team): ?><p><a class="ev-link" href="<?php echo SITE_URL; ?>/admin/events.php"><i class="fas fa-plus" aria-hidden="true"></i> Plan an event</a></p><?php endif; ?>
<?php endif; ?>
</main>
<script>
// each reader's own time, next to the site's time (only when it differs)
(function () {
  try {
    var site = <?php echo json_encode(aw_event_tz()->getName()); ?>, mine = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (!mine || mine === site) return;
    document.querySelectorAll('.ev-time[data-utc]').forEach(function (t) {
      var d = new Date(t.dataset.utc), e = new Date(d.getTime() + 60000 * (+t.dataset.min || 60)), s = t.nextElementSibling;
      var f = new Intl.DateTimeFormat(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
      var g = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' });
      if (s) { s.hidden = false; s.textContent = ' · ' + f.format(d) + '–' + g.format(e) + ' your time'; }
    });
  } catch (e) {}
})();
</script>
<style>
.ev { --ev-rose: #9c5457; --ev-card: var(--card-bg, #fff); --ev-line: rgba(156, 84, 87, .16); --ev-mute: #6d5a5a; max-width: 1000px; margin: 0 auto; padding: 26px 16px 64px; color: var(--text, #3a2629); }
html[data-theme="dark"] .ev { --ev-rose: #f0c4c5; --ev-mute: #cdb8b8; --ev-line: rgba(240, 196, 197, .16); }
.ev-head, .ev-hero { padding: 30px 28px; border-radius: 28px; color: #fff; background: radial-gradient(100% 140% at 100% 0%, rgba(255, 220, 160, .4), transparent 55%), linear-gradient(140deg, #26324a, #6a3d58 60%, #c08a73); box-shadow: 0 20px 44px rgba(38, 50, 74, .25); }
.ev-kicker { margin: 0; text-transform: uppercase; letter-spacing: .1em; font-size: .76rem; font-weight: 700; opacity: .92; }
.ev-head h1, .ev-hero h1 { margin: 6px 0; font-family: 'Playfair Display', Georgia, serif; font-size: clamp(1.8rem, 4.4vw, 2.5rem); color: #fff; } .ev-lead { margin: 0; max-width: 44em; line-height: 1.6; opacity: .95; }
.ev-hero { display: flex; gap: 20px; align-items: flex-start; } .ev-when, .ev-where { margin: 6px 0 0; opacity: .95; } .ev-local { opacity: .85; }
.ev-date { width: 64px; height: 70px; border-radius: 18px; display: grid; place-items: center; align-content: center; background: #fff; color: #5a2a45; flex: none; box-shadow: 0 8px 20px rgba(0, 0, 0, .15); }
.ev-date b { font-size: 1.6rem; line-height: 1; font-family: 'Playfair Display', Georgia, serif; } .ev-date small { text-transform: uppercase; font-weight: 700; letter-spacing: .08em; font-size: .72rem; }
.ev-kinds { display: flex; gap: 8px; flex-wrap: wrap; margin: 16px 0; } .ev-kinds a { min-height: 40px; display: inline-flex; align-items: center; padding: 0 14px; border-radius: 999px; border: 1px solid var(--ev-line); background: var(--ev-card); color: inherit; text-decoration: none; font-weight: 600; font-size: .88rem; }
.ev-kinds a.is-on { background: var(--ev-rose); border-color: var(--ev-rose); color: #fff; } html[data-theme="dark"] .ev-kinds a.is-on { color: #2c1e1e; }
.ev-list { display: grid; gap: 10px; }
.ev-card { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 16px; align-items: center; padding: 16px 18px; border-radius: 22px; background: var(--ev-card); border: 1px solid var(--ev-line); color: inherit; text-decoration: none; box-shadow: 0 6px 18px rgba(44, 30, 30, .05); transition: transform .2s, border-color .2s; animation: evIn .45s ease both; animation-delay: calc(var(--i) * 40ms); }
@keyframes evIn { from { opacity: 0; transform: translateY(8px); } }
.ev-card:hover { transform: translateY(-2px); border-color: rgba(156, 84, 87, .45); } .ev-card .ev-date { background: linear-gradient(160deg, #f6e4e4, #ead1d2); box-shadow: none; }
html[data-theme="dark"] .ev-card .ev-date { background: linear-gradient(160deg, #4a3036, #3a262b); color: #f6dede; }
.ev-card.is-cancelled { opacity: .65; } .ev-card .ev-kicker { color: var(--ev-mute); opacity: 1; }
.ev-card-main { display: grid; gap: 3px; min-width: 0; } .ev-card-main strong { font-size: 1.12rem; font-family: 'Playfair Display', Georgia, serif; } .ev-meta { color: var(--ev-mute); font-size: .9rem; }
.ev-go { color: var(--ev-rose); }
.ev-empty { text-align: center; padding: 40px 16px; border: 1px dashed var(--ev-line); border-radius: 22px; color: var(--ev-mute); } .ev-empty i { font-size: 2rem; color: var(--ev-rose); }
.ev-past-h { margin: 28px 0 8px; font-size: 1rem; text-transform: uppercase; letter-spacing: .1em; color: var(--ev-mute); } .ev-past { margin: 0; padding-left: 18px; line-height: 1.9; } .ev-past a { color: var(--ev-rose); font-weight: 600; } .ev-past small { color: var(--ev-mute); }
.ev-back { display: inline-flex; gap: 8px; align-items: center; min-height: 40px; color: var(--ev-rose); font-weight: 600; text-decoration: none; margin-bottom: 8px; }
.ev-alert { margin: 14px 0 0; padding: 12px 16px; border-radius: 14px; font-weight: 500; } .ev-alert.is-ok { background: #e3efe6; color: #24452f; } .ev-alert.is-error { background: #f6e1e3; color: #6e2a31; }
.ev-body { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: 18px; margin-top: 18px; align-items: start; }
.ev-text { background: var(--ev-card); border: 1px solid var(--ev-line); border-radius: 22px; padding: 22px; line-height: 1.75; font-size: 1.04rem; }
.ev-side { position: sticky; top: 84px; display: grid; gap: 10px; background: var(--ev-card); border: 1px solid var(--ev-line); border-radius: 22px; padding: 20px; }
.ev-count { margin: 0; color: var(--ev-mute); } .ev-count b { font-size: 1.4rem; color: var(--text); }
.ev-going { margin: 0; color: #2f6b45; font-weight: 700; } .ev-going.is-wait { color: #6b4310; } html[data-theme="dark"] .ev-going { color: #8fd1a5; }
.ev-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 18px; border-radius: 999px; border: 1.5px solid var(--ev-rose); background: transparent; color: var(--ev-rose); font: 600 .92rem Inter, system-ui, sans-serif; text-decoration: none; cursor: pointer; width: 100%; }
.ev-btn:hover { background: var(--ev-rose); color: #fff; } html[data-theme="dark"] .ev-btn:hover { color: #2c1e1e; }
.ev-btn-primary { background: var(--ev-rose); color: #fff; } html[data-theme="dark"] .ev-btn-primary { color: #2c1e1e; }
.ev-side form { display: grid; gap: 8px; } .ev-join { display: grid; gap: 6px; padding: 12px; border-radius: 14px; background: rgba(156, 84, 87, .06); } .ev-join p { margin: 0; } .ev-join small { color: var(--ev-mute); }
.ev-note { margin: 0; color: var(--ev-mute); font-size: .9rem; } .ev-link { color: var(--ev-rose); font-weight: 600; }
@media (max-width: 800px) { .ev-body { grid-template-columns: 1fr; } .ev-side { position: static; } .ev-hero { flex-direction: column; } }
@media (prefers-reduced-motion: reduce) { .ev-card { animation: none; } }
</style>
<?php require_once 'includes/footer.php'; ?>
