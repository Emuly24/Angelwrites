<?php
/**
 * The prayer wall (includes/prayer.php): ask for prayer, pray for others, share the good news.
 * Reading the wall is for members (requests are personal); guests see what it is and are invited in.
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/prayer.php';
if (is_file(__DIR__ . '/includes/notifications.php')) require_once 'includes/notifications.php';
if (is_file(__DIR__ . '/includes/moderation.php')) require_once 'includes/moderation.php';

$pw_uid = (int)($_SESSION['user_id'] ?? 0);
$pw_self = SITE_URL . '/prayer.php';
aw_prayer_tables($db);
$pw_admin_eye = function_exists('aw_can') && aw_can('see_identities');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    $out = ['ok' => false, 'message' => 'Please sign in first.'];
    if ($pw_uid && validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $act = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($act === 'new') {
            $r = aw_prayer_create($db, $pw_uid, $_POST);
            $out = !empty($r['ok']) ? ['ok' => true, 'message' => (($_POST['visibility'] ?? '') === 'team' ? 'Thank you for trusting us. The AngelWrites team will pray for you.' : 'Your request is on the wall. You are not alone.'), 'id' => $r['id']] : ['ok' => false, 'message' => $r['error']];
            if (empty($r['ok'])) $_SESSION['aw_pw_form'] = $_POST;
        } elseif ($act === 'pray') {
            $out = aw_prayer_toggle($db, $pw_uid, $id) + ['message' => ''];
        } else {
            $out = aw_prayer_act($db, $pw_uid, $id, $act, (string)($_POST['text'] ?? ''));
        }
    } elseif ($pw_uid) {
        $out = ['ok' => false, 'message' => 'This page had been open a long time. Please reload and try again.'];
    }
    if ($json) { header('Content-Type: application/json'); echo json_encode($out); exit; }
    $_SESSION['aw_pw_flash'] = [$out['ok'] ? 'ok' : 'error', (string)($out['message'] ?? '')];
    header('Location: ' . $pw_self . (!empty($out['id']) && ($_POST['visibility'] ?? '') !== 'team' ? '#p' . (int)$out['id'] : (isset($_POST['back']) ? (string)preg_replace('/[^a-z0-9=&#?_-]/i', '', (string)$_POST['back']) : '')));
    exit;
}
$pw_flash = $_SESSION['aw_pw_flash'] ?? null; unset($_SESSION['aw_pw_flash']);
$pw_form = $_SESSION['aw_pw_form'] ?? []; unset($_SESSION['aw_pw_form']);

$pw_show = (string)($_GET['show'] ?? '');
if ($pw_show !== 'answered' && $pw_show !== 'mine' && !isset(AW_PRAYER_CATEGORIES[$pw_show])) $pw_show = '';
$pw_list = $pw_uid ? aw_prayer_wall($db, $pw_uid, $pw_show, 60) : [];
$pw_updates = aw_prayer_updates($db, array_column($pw_list, 'id'));
$pw_counts = ['open' => 0, 'answered' => 0, 'prayers' => 0];
try {
    $pw_counts['open'] = (int)$db->query("SELECT COUNT(*) FROM prayer_requests WHERE visibility = 'public' AND status NOT IN ('removed', 'answered') AND COALESCE(renewed_at, created_at) >= datetime('now', '-" . AW_PRAYER_DAYS . " days')")->fetchColumn();
    $pw_counts['answered'] = (int)$db->query("SELECT COUNT(*) FROM prayer_requests WHERE visibility = 'public' AND status = 'answered'")->fetchColumn();
    $pw_counts['prayers'] = (int)$db->query("SELECT COUNT(*) FROM prayer_prayed")->fetchColumn();
} catch (Throwable $e) { error_log('prayer counts: ' . $e->getMessage()); }
$pw_ago = function ($w) { $d = time() - (int)strtotime((string)$w); return $d < 3600 ? 'just now' : ($d < 86400 ? floor($d / 3600) . ' h ago' : ($d < 86400 * 14 ? floor($d / 86400) . ' d ago' : date('j M', strtotime($w)))); };
$pw_token = $pw_uid ? generate_csrf_token() : '';

$pageTitle = 'Prayer wall';
require_once 'includes/header.php';
if (function_exists('aw_report_assets')) echo aw_report_assets();
?>
<main class="pw">
    <header class="pw-hero">
        <div class="pw-hero-text">
            <p class="pw-kicker"><i class="fas fa-hands-praying" aria-hidden="true"></i> Prayer wall</p>
            <h1>We pray for one another</h1>
            <p class="pw-lead">Share what is on your heart, and carry others in prayer. When you pray for someone, tap “I prayed”: they will know they are not alone.</p>
            <p class="pw-stats"><span><b><?php echo number_format($pw_counts['open']); ?></b> requests</span><span><b><?php echo number_format($pw_counts['prayers']); ?></b> prayers said</span><span><b><?php echo number_format($pw_counts['answered']); ?></b> answered</span></p>
        </div>
        <?php if ($pw_uid): ?><a href="#pwAsk" class="pw-btn pw-btn-light"><i class="fas fa-plus" aria-hidden="true"></i> Ask for prayer</a><?php endif; ?>
    </header>

    <?php if ($pw_flash && $pw_flash[1] !== ''): ?><div class="pw-alert <?php echo $pw_flash[0] === 'ok' ? 'is-ok' : 'is-error'; ?>" role="status"><?php echo htmlspecialchars($pw_flash[1]); ?></div><?php endif; ?>

    <?php if (!$pw_uid): ?>
    <section class="pw-card pw-guest">
        <h2>Join to pray with us</h2>
        <p>Prayer requests are personal, so the wall is open to members of the AngelWrites family. Joining is free.</p>
        <p><a class="pw-btn pw-btn-primary" href="<?php echo SITE_URL; ?>/register.php">Join free</a> <a class="pw-btn" href="<?php echo SITE_URL; ?>/login.php?redirect=<?php echo urlencode('/prayer.php'); ?>">Log in</a></p>
    </section>
    <?php else: ?>

    <div class="pw-layout">
        <section class="pw-wall" aria-labelledby="pwWallT">
            <h2 id="pwWallT" class="sr-only">Requests</h2>
            <nav class="pw-tabs" aria-label="Show">
                <?php foreach (['' => 'Praying now', 'answered' => 'Answered 🎉', 'mine' => 'My requests'] as $k => $l): ?><a href="<?php echo $pw_self . ($k !== '' ? '?show=' . $k : ''); ?>"<?php echo $pw_show === $k ? ' class="is-on" aria-current="page"' : ''; ?>><?php echo $l; ?></a><?php endforeach; ?>
                <label class="pw-cat"><span class="sr-only">Topic</span><select onchange="location.href='<?php echo $pw_self; ?>' + (this.value ? '?show=' + this.value : '')"><option value="">All topics</option><?php foreach (AW_PRAYER_CATEGORIES as $k => $l): ?><option value="<?php echo $k; ?>"<?php echo $pw_show === $k ? ' selected' : ''; ?>><?php echo htmlspecialchars($l); ?></option><?php endforeach; ?></select></label>
            </nav>
            <?php if (!$pw_list): ?>
                <div class="pw-empty"><i class="fas fa-dove" aria-hidden="true"></i><p><?php echo $pw_show === 'mine' ? 'You have not asked for prayer yet. Whatever you carry, you can share it here.' : ($pw_show === 'answered' ? 'Answered prayers will be celebrated here.' : 'No requests right now. Be the first to share, or come back soon.'); ?></p></div>
            <?php endif; ?>
            <?php foreach ($pw_list as $i => $r): $mine = (int)$r['user_id'] === $pw_uid; $ups = $pw_updates[(int)$r['id']] ?? []; $answered = $r['status'] === 'answered';
                $stale = !$answered && strtotime((string)($r['renewed_at'] ?: $r['created_at'])) < strtotime('-' . AW_PRAYER_DAYS . ' days'); ?>
            <article class="pw-item<?php echo $answered ? ' is-answered' : ''; ?>" id="p<?php echo (int)$r['id']; ?>" style="--i:<?php echo min($i, 12); ?>">
                <header>
                    <span class="pw-av" aria-hidden="true"><?php echo !empty($r['anonymous']) && !$mine ? '<i class="fas fa-user"></i>' : htmlspecialchars(mb_strtoupper(mb_substr(aw_prayer_name($r), 0, 1))); ?></span>
                    <div class="pw-who"><strong><?php echo htmlspecialchars($mine ? 'You' . (!empty($r['anonymous']) ? ' (anonymous to others)' : '') : aw_prayer_name($r, $pw_admin_eye)); ?></strong>
                        <small><?php echo htmlspecialchars(AW_PRAYER_CATEGORIES[$r['category']] ?? 'Prayer'); ?> · <?php echo $pw_ago($r['created_at']); ?><?php echo $r['visibility'] === 'team' ? ' · private, only the team' : ''; ?></small></div>
                    <?php if ($answered): ?><span class="pw-badge">Answered</span><?php elseif ($stale): ?><span class="pw-badge is-rest">Resting</span><?php endif; ?>
                </header>
                <h3><?php echo htmlspecialchars((string)$r['title']); ?></h3>
                <p class="pw-text"><?php echo nl2br(htmlspecialchars((string)$r['message'])); ?></p>
                <?php foreach ($ups as $u): ?>
                    <div class="pw-up is-<?php echo htmlspecialchars($u['kind']); ?>">
                        <b><?php echo $u['kind'] === 'praise' ? '🎉 Answered' : ($u['kind'] === 'update' ? 'Update' : 'Encouragement'); ?></b>
                        <?php if ($u['kind'] === 'encourage'): ?><span class="pw-up-who"> from <?php echo htmlspecialchars((int)$u['user_id'] === $pw_uid ? 'you' : aw_prayer_name(['anonymous' => 0] + $u)); ?></span><?php endif; ?>
                        <span class="pw-up-when"> · <?php echo $pw_ago($u['created_at']); ?></span>
                        <p><?php echo nl2br(htmlspecialchars($u['body'])); ?></p>
                        <?php if ($u['kind'] === 'encourage' && function_exists('aw_report_controls')) echo aw_report_controls('prayer_update', (int)$u['id'], (int)$u['user_id'], 'this member'); ?>
                    </div>
                <?php endforeach; ?>
                <footer class="pw-acts">
                    <?php if ($r['visibility'] === 'public'): ?>
                    <button type="button" class="pw-pray<?php echo !empty($r['i_prayed']) ? ' is-on' : ''; ?>" data-pray="<?php echo (int)$r['id']; ?>" aria-pressed="<?php echo !empty($r['i_prayed']) ? 'true' : 'false'; ?>"<?php echo $mine ? ' disabled title="Others pray for your request"' : ''; ?>>
                        <span aria-hidden="true">🙏</span> <span class="pw-pray-l"><?php echo !empty($r['i_prayed']) ? 'You prayed' : 'I prayed'; ?></span> <span class="pw-pray-n"><?php echo (int)$r['prayer_count']; ?></span>
                    </button>
                    <?php endif; ?>
                    <?php if ($mine): ?>
                        <details class="pw-more"><summary>Update or close</summary>
                            <form method="post" class="pw-mini-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($pw_token); ?>"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="back" value="?show=mine">
                                <label class="sr-only" for="up<?php echo (int)$r['id']; ?>">Your words</label><textarea id="up<?php echo (int)$r['id']; ?>" name="text" rows="2" maxlength="800" placeholder="Share an update, or how God answered"></textarea>
                                <div class="pw-mini-acts">
                                    <button class="pw-btn pw-sm" name="action" value="update">Add update</button>
                                    <?php if (!$answered): ?><button class="pw-btn pw-sm pw-btn-primary" name="action" value="answered">It was answered 🎉</button><?php endif; ?>
                                    <?php if ($stale || $answered): ?><button class="pw-btn pw-sm" name="action" value="renew">Still praying</button><?php endif; ?>
                                    <button class="pw-btn pw-sm pw-del" name="action" value="delete" onclick="return confirm('Remove your request?');">Remove</button>
                                </div>
                            </form>
                        </details>
                    <?php elseif ($r['visibility'] === 'public'): ?>
                        <details class="pw-more"><summary>Encourage</summary>
                            <form method="post" class="pw-mini-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($pw_token); ?>"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                <label class="sr-only" for="en<?php echo (int)$r['id']; ?>">A few words of encouragement</label><textarea id="en<?php echo (int)$r['id']; ?>" name="text" rows="2" maxlength="300" placeholder="A short word of hope or a verse (no advice, please)"></textarea>
                                <div class="pw-mini-acts"><button class="pw-btn pw-sm pw-btn-primary" name="action" value="encourage">Send</button></div>
                            </form>
                        </details>
                        <?php if (function_exists('aw_report_controls')) { $rc = aw_report_controls('prayer', (int)$r['id'], (int)$r['user_id'], aw_prayer_name($r)); if (!empty($r['anonymous'])) $rc = preg_replace('#<button[^>]*data-act="block"[^>]*>.*?</button>#', '', $rc); echo $rc; } ?>
                    <?php endif; ?>
                </footer>
            </article>
            <?php endforeach; ?>
        </section>

        <aside class="pw-side">
            <section class="pw-card" id="pwAsk" aria-labelledby="pwAskT">
                <h2 id="pwAskT">Ask for prayer</h2>
                <form method="post" class="pw-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($pw_token); ?>"><input type="hidden" name="action" value="new">
                    <label class="pw-f"><span>In a few words</span><input type="text" name="title" maxlength="120" required placeholder="e.g. Healing for my mother" value="<?php echo htmlspecialchars((string)($pw_form['title'] ?? '')); ?>"></label>
                    <label class="pw-f"><span>Tell us more</span><textarea name="message" rows="5" maxlength="2000" required placeholder="Share as much or as little as you like."><?php echo htmlspecialchars((string)($pw_form['message'] ?? '')); ?></textarea></label>
                    <label class="pw-f"><span>Topic</span><select name="category"><?php foreach (AW_PRAYER_CATEGORIES as $k => $l): ?><option value="<?php echo $k; ?>"<?php echo ($pw_form['category'] ?? '') === $k ? ' selected' : ''; ?>><?php echo htmlspecialchars($l); ?></option><?php endforeach; ?></select></label>
                    <fieldset class="pw-who-f"><legend>Who may see it?</legend>
                        <label><input type="radio" name="visibility" value="public"<?php echo ($pw_form['visibility'] ?? 'public') !== 'team' ? ' checked' : ''; ?>> <span><strong>The prayer wall</strong><small>Members pray with you.</small></span></label>
                        <label><input type="radio" name="visibility" value="team"<?php echo ($pw_form['visibility'] ?? '') === 'team' ? ' checked' : ''; ?>> <span><strong>Only the AngelWrites team</strong><small>Private; the team prays for you.</small></span></label>
                    </fieldset>
                    <label class="pw-check"><input type="checkbox" name="anonymous" value="1"<?php echo !empty($pw_form['anonymous']) ? ' checked' : ''; ?>> <span>Show me as “A member” (the team can still see who you are, to care for you)</span></label>
                    <p class="pw-note"><i class="fas fa-shield-heart" aria-hidden="true"></i> Please do not share other people's private details. If you are in danger, call your local emergency number or find a helpline at <a href="https://findahelpline.com" target="_blank" rel="noopener">findahelpline.com</a>.</p>
                    <button type="submit" class="pw-btn pw-btn-primary"><i class="fas fa-paper-plane" aria-hidden="true"></i> Share my request</button>
                </form>
            </section>
            <section class="pw-card pw-verse">
                <p>“Carry each other's burdens, and in this way you will fulfil the law of Christ.”</p><small>Galatians 6:2</small>
            </section>
        </aside>
    </div>
    <?php endif; ?>
</main>
<?php if ($pw_uid): ?>
<script>
(function () {
  var token = <?php echo json_encode($pw_token); ?>;
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-pray]'); if (!b || b.disabled) return;
    var fd = new FormData(); fd.append('action', 'pray'); fd.append('id', b.dataset.pray); fd.append('csrf_token', token);
    b.disabled = true;
    fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
      b.disabled = false; if (!d.ok) return;
      b.classList.toggle('is-on', d.prayed); b.setAttribute('aria-pressed', d.prayed ? 'true' : 'false');
      b.querySelector('.pw-pray-l').textContent = d.prayed ? 'You prayed' : 'I prayed'; b.querySelector('.pw-pray-n').textContent = d.count;
      if (d.prayed) { b.classList.add('is-pop'); setTimeout(function () { b.classList.remove('is-pop'); }, 700); }
    }).catch(function () { b.disabled = false; });
  });
})();
</script>
<?php endif; ?>
<style>
.pw { --pw-rose: #9c5457; --pw-card: var(--card-bg, #fff); --pw-line: rgba(156, 84, 87, .16); --pw-mute: #6d5a5a; max-width: 1150px; margin: 0 auto; padding: 26px 16px 64px; color: var(--text, #3a2629); }
html[data-theme="dark"] .pw { --pw-rose: #f0c4c5; --pw-mute: #cdb8b8; --pw-line: rgba(240, 196, 197, .16); }
.pw .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.pw-hero { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px; padding: 30px 28px; border-radius: 28px; color: #fff; position: relative; overflow: hidden;
           background: radial-gradient(90% 120% at 100% 0%, rgba(255, 223, 170, .45), transparent 55%), linear-gradient(140deg, #4d2b3c, #8a4a5a 55%, #c08a73); box-shadow: 0 20px 44px rgba(77, 43, 60, .3); }
.pw-kicker { margin: 0; text-transform: uppercase; letter-spacing: .12em; font-size: .78rem; font-weight: 700; opacity: .92; }
.pw-hero h1 { margin: 6px 0; font-family: 'Playfair Display', Georgia, serif; font-size: clamp(1.8rem, 4.4vw, 2.6rem); color: #fff; }
.pw-lead { margin: 0; max-width: 42em; opacity: .95; line-height: 1.6; }
.pw-stats { display: flex; gap: 18px; flex-wrap: wrap; margin: 14px 0 0; opacity: .95; } .pw-stats b { font-size: 1.25rem; margin-right: 4px; }
.pw-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 18px; border-radius: 999px; border: 1.5px solid var(--pw-rose); background: transparent; color: var(--pw-rose); font: 600 .92rem Inter, system-ui, sans-serif; text-decoration: none; cursor: pointer; }
.pw-btn:hover { background: var(--pw-rose); color: #fff; } html[data-theme="dark"] .pw-btn:hover { color: #2c1e1e; }
.pw-btn-primary { background: var(--pw-rose); color: #fff; } html[data-theme="dark"] .pw-btn-primary { color: #2c1e1e; }
.pw-hero .pw-btn-light { background: #fff; border-color: #fff; color: #5a2a3c; } .pw-sm { min-height: 38px; padding: 0 12px; font-size: .84rem; }
.pw-del { border-color: rgba(179, 38, 30, .35); color: #b3261e; } .pw-del:hover { background: #b3261e; border-color: #b3261e; color: #fff; }
.pw-alert { margin: 16px 0 0; padding: 12px 16px; border-radius: 14px; font-weight: 500; } .pw-alert.is-ok { background: #e3efe6; color: #24452f; } .pw-alert.is-error { background: #f6e1e3; color: #6e2a31; }
.pw-layout { display: grid; grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr); gap: 18px; margin-top: 18px; align-items: start; }
.pw-side { position: sticky; top: 84px; display: grid; gap: 14px; }
.pw-card { background: var(--pw-card); border: 1px solid var(--pw-line); border-radius: 22px; padding: 20px; box-shadow: 0 8px 24px rgba(44, 30, 30, .06); }
.pw-card h2 { margin: 0 0 12px; font-family: 'Playfair Display', Georgia, serif; font-size: 1.3rem; }
.pw-guest { margin-top: 18px; text-align: center; }
.pw-tabs { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
.pw-tabs a { min-height: 40px; display: inline-flex; align-items: center; padding: 0 16px; border-radius: 999px; border: 1px solid var(--pw-line); background: var(--pw-card); color: inherit; text-decoration: none; font-weight: 600; font-size: .9rem; }
.pw-tabs a.is-on { background: var(--pw-rose); border-color: var(--pw-rose); color: #fff; } html[data-theme="dark"] .pw-tabs a.is-on { color: #2c1e1e; }
.pw-cat { margin-left: auto; } .pw-cat select { min-height: 40px; border-radius: 999px; padding: 0 34px 0 14px; }
.pw-empty { text-align: center; padding: 40px 16px; border-radius: 22px; border: 1px dashed var(--pw-line); background: var(--pw-card); color: var(--pw-mute); } .pw-empty i { font-size: 2rem; color: var(--pw-rose); }
.pw-item { background: var(--pw-card); border: 1px solid var(--pw-line); border-radius: 22px; padding: 18px 20px; margin-bottom: 12px; box-shadow: 0 6px 18px rgba(44, 30, 30, .05); animation: pwIn .45s ease both; animation-delay: calc(var(--i) * 40ms); }
@keyframes pwIn { from { opacity: 0; transform: translateY(8px); } }
.pw-item.is-answered { border-color: #c8dfcc; background: linear-gradient(180deg, rgba(227, 239, 230, .55), var(--pw-card) 45%); }
html[data-theme="dark"] .pw-item.is-answered { border-color: #3e5c46; background: linear-gradient(180deg, rgba(47, 107, 69, .2), var(--pw-card) 45%); }
.pw-item header { display: flex; gap: 12px; align-items: center; }
.pw-av { width: 42px; height: 42px; border-radius: 50%; display: grid; place-items: center; flex: none; color: #fff; font: 700 1rem 'Playfair Display', Georgia, serif; background: linear-gradient(135deg, #c98d8f, #7d3f4f); }
.pw-who { display: grid; min-width: 0; } .pw-who small { color: var(--pw-mute); }
.pw-badge { margin-left: auto; padding: 4px 10px; border-radius: 999px; background: #e3efe6; color: #24452f; font-weight: 700; font-size: .78rem; } .pw-badge.is-rest { background: #f1ebe4; color: #6b5a48; }
.pw-item h3 { margin: 12px 0 6px; font-size: 1.12rem; }
.pw-text { margin: 0; line-height: 1.65; overflow-wrap: anywhere; }
.pw-up { margin-top: 10px; padding: 10px 12px; border-radius: 14px; background: rgba(156, 84, 87, .06); font-size: .93rem; } .pw-up p { margin: 4px 0 0; line-height: 1.55; }
.pw-up.is-praise { background: rgba(47, 107, 69, .1); } .pw-up-when, .pw-up-who { color: var(--pw-mute); font-size: .84rem; }
.pw-acts { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 14px; }
.pw-pray { display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 16px; border-radius: 999px; border: 1.5px solid var(--pw-rose); background: transparent; color: var(--pw-rose); font: 700 .92rem Inter, system-ui, sans-serif; cursor: pointer; transition: transform .2s, background .2s; }
.pw-pray:hover:not(:disabled) { background: rgba(156, 84, 87, .08); }
.pw-pray.is-on { background: var(--pw-rose); color: #fff; } html[data-theme="dark"] .pw-pray.is-on { color: #2c1e1e; }
.pw-pray:disabled { opacity: .65; cursor: default; }
.pw-pray-n { min-width: 22px; padding: 2px 8px; border-radius: 999px; background: rgba(156, 84, 87, .14); font-size: .8rem; } .pw-pray.is-on .pw-pray-n { background: rgba(255, 255, 255, .25); }
.pw-pray.is-pop { animation: pwPop .6s ease; } @keyframes pwPop { 40% { transform: scale(1.12); } }
.pw-more summary { cursor: pointer; color: var(--pw-rose); font-weight: 600; min-height: 44px; display: inline-flex; align-items: center; list-style: none; padding: 0 6px; }
.pw-more summary::-webkit-details-marker { display: none; }
.pw-mini-form { display: grid; gap: 8px; margin-top: 6px; min-width: min(420px, 80vw); } .pw-mini-form textarea { width: 100%; padding: 10px 12px; border-radius: 12px; font: inherit; }
.pw-mini-acts { display: flex; gap: 6px; flex-wrap: wrap; }
.pw-form { display: grid; gap: 12px; } .pw-f { display: grid; gap: 6px; font-weight: 600; } .pw-f input, .pw-f textarea, .pw-f select { width: 100%; padding: 10px 12px; border-radius: 12px; font: inherit; font-weight: 400; }
.pw-who-f { border: 0; padding: 0; margin: 0; display: grid; gap: 8px; } .pw-who-f legend { font-weight: 600; margin-bottom: 6px; }
.pw-who-f label { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border-radius: 14px; border: 1px solid var(--pw-line); cursor: pointer; } .pw-who-f small { display: block; color: var(--pw-mute); }
.pw-who-f input, .pw-check input { accent-color: #9c5457; width: 18px; height: 18px; margin-top: 3px; flex: none; }
.pw-check { display: flex; gap: 10px; align-items: flex-start; font-size: .9rem; line-height: 1.45; cursor: pointer; }
.pw-note { margin: 0; font-size: .84rem; color: var(--pw-mute); line-height: 1.5; } .pw-note a { color: var(--pw-rose); }
.pw-verse { text-align: center; font-family: 'Playfair Display', Georgia, serif; font-style: italic; font-size: 1.05rem; line-height: 1.6; } .pw-verse p { margin: 0 0 6px; } .pw-verse small { font-family: Inter, sans-serif; font-style: normal; color: var(--pw-mute); }
@media (max-width: 900px) { .pw-layout { grid-template-columns: 1fr; } .pw-side { position: static; order: -1; } .pw-cat { margin-left: 0; } }
@media (prefers-reduced-motion: reduce) { .pw-item { animation: none; } }
</style>
<?php require_once 'includes/footer.php'; ?>
