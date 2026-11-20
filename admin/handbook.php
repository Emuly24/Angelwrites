<?php
/**
 * The Founder's handbook: everything about running AngelWrites, page by page, for Angella or anyone
 * she asks to run the site. The same words as the Founder's guided tour (includes/tour_content.php),
 * printed in full, searchable and printable, plus what members and the Care Team are shown.
 * Each chapter has "Open this page" and "Show me" (starts the guided tour at that page).
 * Owner and stewards.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/tours.php';

aw_require('manage_members'); // the founder and stewards

$e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$paras = function ($t) use ($e) { return implode('', array_map(function ($p) use ($e) { return '<p>' . $e($p) . '</p>'; }, preg_split('/\n\n+/', (string)$t))); };
$state = aw_tour_get($db, (int)$_SESSION['user_id'], 'admin');
$token = generate_csrf_token();

$pageTitle = "Founder's handbook";
require_once '../includes/header.php';
?>
<div class="hb-page">
    <header class="hb-head">
        <p class="hb-kicker"><i class="fas fa-book-open" aria-hidden="true"></i> Founder's handbook</p>
        <h1>Running AngelWrites</h1>
        <p class="hb-lead">Everything the founder does on AngelWrites, page by page: what each page is for, what you see on it and how to use it. Written so that anyone Angella trusts can run the site while she is busy.</p>
        <div class="hb-acts">
            <form method="POST" action="<?php echo SITE_URL; ?>/tour.php">
                <input type="hidden" name="csrf_token" value="<?php echo $e($token); ?>"><input type="hidden" name="tour" value="admin">
                <?php if (($state['status'] ?? '') === 'on' || ($state['status'] ?? '') === 'off'): ?>
                    <button type="submit" name="action" value="resume" class="hb-btn hb-primary"><i class="fas fa-route" aria-hidden="true"></i> <?php echo ($state['status'] ?? '') === 'on' ? 'Continue the guided tour' : 'Turn the guided tour on again'; ?></button>
                    <?php if (($state['status'] ?? '') === 'on'): ?><button type="submit" name="action" value="off" class="hb-btn">Turn the tour off</button><?php endif; ?>
                <?php else: ?>
                    <button type="submit" name="action" value="restart" class="hb-btn hb-primary"><i class="fas fa-route" aria-hidden="true"></i> Start the guided tour</button>
                <?php endif; ?>
            </form>
            <button type="button" class="hb-btn" onclick="window.print()"><i class="fas fa-print" aria-hidden="true"></i> Print or save as PDF</button>
        </div>
        <label class="hb-search"><span class="sr-only">Search the handbook</span><i class="fas fa-magnifying-glass" aria-hidden="true"></i><input type="search" id="hbQ" placeholder="Search: backup, payments, prayer, password…" autocomplete="off"></label>
        <p class="hb-count" id="hbCount" role="status" aria-live="polite"></p>
    </header>

    <div class="hb-layout">
        <nav class="hb-toc" aria-label="Chapters">
            <h2>Chapters</h2>
            <ol>
                <?php foreach (AW_TOUR_ADMIN as $k => $c): ?><li><a href="#ch-<?php echo $k; ?>"><?php echo $e($c['title']); ?></a></li><?php endforeach; ?>
                <li><a href="#ch-members">What members are shown</a></li>
                <li><a href="#ch-careteam">What the Care Team is shown</a></li>
                <li><a href="#ch-daily">A simple routine</a></li>
            </ol>
        </nav>

        <main class="hb-main" id="main">
            <?php $num = 0; foreach (AW_TOUR_ADMIN as $k => $c): $num++; ?>
            <section class="hb-ch" id="ch-<?php echo $k; ?>" data-hb>
                <h2><span class="hb-num"><?php echo $num; ?></span> <i class="fas <?php echo $e($c['icon']); ?>" aria-hidden="true"></i> <?php echo $e($c['title']); ?></h2>
                <?php echo $paras($c['summary']); ?>
                <ol class="hb-steps">
                    <?php foreach ($c['steps'] as $st): ?><li><strong><?php echo $e($st[1]); ?>.</strong> <?php echo $e(str_replace("\n\n", ' ', $st[2])); ?></li><?php endforeach; ?>
                </ol>
                <?php if (!empty($c['tips'])): ?><div class="hb-tips"><h3>Good to know</h3><ul><?php foreach ($c['tips'] as $t): ?><li><?php echo $e($t); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <div class="hb-links">
                    <a class="hb-btn hb-sm" href="<?php echo SITE_URL . '/' . $e($c['page']); ?>">Open this page</a>
                    <form method="POST" action="<?php echo SITE_URL; ?>/tour.php"><input type="hidden" name="csrf_token" value="<?php echo $e($token); ?>"><input type="hidden" name="tour" value="admin"><input type="hidden" name="step" value="<?php echo aw_tour_chapter_step($k); ?>">
                        <button type="submit" name="action" value="start" class="hb-btn hb-sm"><i class="fas fa-route" aria-hidden="true"></i> Show me</button></form>
                </div>
            </section>
            <?php endforeach; ?>

            <section class="hb-ch" id="ch-members" data-hb>
                <h2><i class="fas fa-user" aria-hidden="true"></i> What members are shown</h2>
                <p>Every new member is offered this welcome tour on their dashboard after signing up. Knowing it helps you answer their questions.</p>
                <ol class="hb-steps"><?php foreach (AW_TOUR_MEMBER as $s): ?><li><strong><?php echo $e($s['title']); ?>.</strong> <?php echo $e(str_replace("\n\n", ' ', $s['body'])); ?></li><?php endforeach; ?></ol>
            </section>

            <section class="hb-ch" id="ch-careteam" data-hb>
                <h2><i class="fas fa-hands-holding-circle" aria-hidden="true"></i> What the Care Team is shown</h2>
                <p>When a member accepts an invitation, this tour starts on their Team desk, with extra steps for the pages of their own role.</p>
                <ol class="hb-steps"><?php foreach (array_merge(AW_TOUR_TEAM_START, AW_TOUR_TEAM_END) as $s): ?><li><strong><?php echo $e($s['title']); ?>.</strong> <?php echo $e(str_replace("\n\n", ' ', $s['body'])); ?></li><?php endforeach; ?></ol>
                <h3>Extra steps by role</h3>
                <ul class="hb-roles"><?php foreach (AW_TOUR_TEAM_ROLE as $r => $ss): ?><li><strong><?php echo $e(AW_POSITIONS[$r]['title'] ?? $r); ?>:</strong> <?php echo $e(implode('; ', array_map(function ($s) { return $s['title'] . ' (' . $s['body'] . ')'; }, $ss))); ?></li><?php endforeach; ?></ul>
            </section>

            <section class="hb-ch" id="ch-daily" data-hb>
                <h2><i class="fas fa-list-check" aria-hidden="true"></i> A simple routine</h2>
                <h3>Every day (10 to 20 minutes)</h3>
                <ol class="hb-steps"><li>Open the admin dashboard and clear “Needs your attention”.</li><li>Open the Team desk: anything in red first, then the oldest items, then read the handover notes.</li><li>Answer the team inbox and confirm any book orders in Payments.</li></ol>
                <h3>Every week</h3>
                <ol class="hb-steps"><li>Read the weekly summary email.</li><li>Publish something new: a poem, a reflection, a prompt or a devotional day.</li><li>Check Statistics to see what people are reading.</li></ol>
                <h3>Every month</h3>
                <ol class="hb-steps"><li>Take a backup and keep it somewhere safe.</li><li>Read the Care Team's check-ins and thank them; plan a team meeting if needed.</li><li>Look over the audit log and Team & roles: does everyone still need the access they have?</li></ol>
                <h3>When handing the site to someone</h3>
                <ol class="hb-steps"><li>Make sure they have their own account (never share yours).</li><li>Make them a steward in Team & roles, and ask them to turn on two-step sign-in.</li><li>Ask them to take the Founder's tour, and keep this handbook open.</li><li>When you are back, change them back in Team & roles, and look over the audit log.</li></ol>
            </section>
            <p class="hb-none" id="hbNone" hidden>Nothing matches. Try another word.</p>
        </main>
    </div>
</div>
<style>
.hb-page{max-width:1180px;margin:0 auto;padding:32px 16px 72px;color:var(--text,#3d2e2e)}
.hb-kicker{margin:0;color:#9c5457;font-weight:700;letter-spacing:.05em;text-transform:uppercase;font-size:.85rem}
.hb-head h1{font-family:'Playfair Display',Georgia,serif;font-size:clamp(1.8rem,4vw,2.5rem);margin:4px 0 8px}
.hb-lead{font-size:1.08rem;line-height:1.6;max-width:760px}
.hb-acts{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0}.hb-acts form{display:flex;gap:10px;flex-wrap:wrap}
.hb-btn{display:inline-flex;align-items:center;gap:8px;min-height:44px;padding:0 18px;border-radius:999px;border:1px solid #9c5457;background:transparent;color:#9c5457;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}
.hb-primary{background:#9c5457;color:#fff}.hb-sm{min-height:38px;padding:0 14px;font-size:.9rem}
.hb-btn:focus-visible,.hb-toc a:focus-visible{outline:3px solid #3d2e2e;outline-offset:2px}
.hb-search{display:flex;align-items:center;gap:10px;max-width:560px;border:1px solid var(--border,#eadede);border-radius:999px;padding:0 16px;background:var(--card-bg,#fff)}
.hb-search input{border:0!important;box-shadow:none!important;background:transparent!important;min-height:46px;flex:1;font:inherit;color:inherit;outline:none;padding:0!important;margin:0!important}
.hb-search:focus-within{border-color:#9c5457;box-shadow:0 0 0 3px rgba(156,84,87,.2)}
.hb-count{color:var(--text-light,#6b5757);min-height:1.2em;margin:6px 0 0}
.hb-layout{display:grid;grid-template-columns:260px minmax(0,1fr);gap:28px;margin-top:20px;align-items:start}
.hb-toc{position:sticky;top:90px;max-height:calc(100vh - 110px);overflow:auto;background:var(--card-bg,#fff);border:1px solid var(--border,#eadede);border-radius:16px;padding:14px 16px}
.hb-toc h2{font-size:1rem;margin:0 0 8px}.hb-toc ol{margin:0;padding-left:20px;line-height:1.7;font-size:.92rem}.hb-toc a{color:inherit}
.hb-ch{background:var(--card-bg,#fff);border:1px solid var(--border,#eadede);border-radius:18px;padding:22px 24px;margin:0 0 18px;scroll-margin-top:90px}
.hb-ch h2{display:flex;align-items:center;gap:10px;font-size:1.25rem;margin:0 0 10px}.hb-ch h2 i{color:#9c5457}
.hb-num{display:inline-grid;place-items:center;min-width:30px;height:30px;border-radius:50%;background:#9c5457;color:#fff;font-size:.9rem}
.hb-ch h3{font-size:1.02rem;margin:14px 0 6px}.hb-ch p{line-height:1.65;margin:0 0 10px}
.hb-steps{margin:8px 0 0;padding-left:22px;line-height:1.7}.hb-steps li{margin-bottom:6px}
.hb-tips{margin-top:12px;background:rgba(219,161,162,.12);border-radius:12px;padding:10px 16px}.hb-tips h3{margin-top:0}.hb-tips ul{margin:0;padding-left:20px;line-height:1.6}
.hb-roles{padding-left:20px;line-height:1.65}
.hb-links{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}.hb-links form{margin:0}
[data-theme="dark"] .hb-page{color:#f3e6e6}[data-theme="dark"] .hb-ch,[data-theme="dark"] .hb-toc,[data-theme="dark"] .hb-search{background:#2a2122;border-color:#4a3739}
[data-theme="dark"] .hb-kicker,[data-theme="dark"] .hb-ch h2 i{color:#f0b9ba}[data-theme="dark"] .hb-btn{border-color:#f0b9ba;color:#f0b9ba}[data-theme="dark"] .hb-primary{background:#c97c7f;color:#1f1516}
@media (max-width:900px){.hb-layout{grid-template-columns:1fr}.hb-toc{position:static;max-height:none}}
@media print{header.awh,footer,.hb-acts,.hb-search,.hb-count,.hb-links,.hb-toc,.aw-et-toggle,.awt-pill,.awt-card,.awt-spot{display:none!important}.hb-layout{display:block}.hb-ch{break-inside:avoid;border:0;padding:0 0 12px}.hb-page{padding:0}}
</style>
<script>
(function () {
  var q = document.getElementById('hbQ'), secs = [].slice.call(document.querySelectorAll('[data-hb]')), none = document.getElementById('hbNone'), count = document.getElementById('hbCount');
  q.addEventListener('input', function () {
    var t = q.value.trim().toLowerCase(), shown = 0;
    secs.forEach(function (s) { var hit = !t || s.textContent.toLowerCase().indexOf(t) !== -1; s.hidden = !hit; if (hit) shown++; });
    none.hidden = shown > 0; count.textContent = t ? shown + ' of ' + secs.length + ' chapters mention “' + q.value.trim() + '”.' : '';
  });
})();
</script>
<?php require_once '../includes/footer.php'; ?>
