<?php
// I2: Messages (PROGRAM_PLAN.md Phase 5). Every change is a POST with the CSRF token, then a redirect.
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/components.php';
require_once 'includes/messaging.php';

redirectIfNotLoggedIn();
$uid = (int)$_SESSION['user_id'];
$staff = aw_msg_staff();
if (!aw_msg_enabled() && !$staff) { $pageTitle = 'Messages'; require 'includes/header.php'; echo '<div class="container" style="padding:60px 0">' . aw_empty('Messages are resting', 'Please come back soon. You can still reach the team through Contact.', [SITE_URL . '/contact.php', 'Contact'], 'fa-envelope') . '</div>'; require 'includes/footer.php'; exit; }
aw_msg_tables($db);
aw_moderation_tables($db);
$self = SITE_URL . '/messages.php';
$go = function ($q) use ($self) { header('Location: ' . $self . ($q !== '' ? '?' . $q : '')); exit; };
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Your words are still below; please send again.';
    } else {
        $act = (string)($_POST['action'] ?? '');
        $tid = (int)($_POST['t'] ?? 0);
        if ($act === 'send') {
            $r = aw_msg_send($db, $tid, $uid, (string)($_POST['body'] ?? ''));
            if ($r['ok']) $go('t=' . $tid . (!empty($_POST['team']) ? '&team=1' : '') . '#end');
            $error = $r['message'];
        } elseif ($act === 'start') {
            $to = (int)($_POST['to'] ?? 0);
            if (!$to && trim((string)($_POST['username'] ?? '')) !== '') {
                try { $q = $db->prepare("SELECT id FROM users WHERE lower(username) = lower(?)"); $q->execute([ltrim(trim((string)$_POST['username']), '@')]); $to = (int)$q->fetchColumn(); } catch (Throwable $e) { $to = 0; }
                if (!$to) $error = 'No member has that username. Please check it with them.';
            }
            if ($to && $error === '') {
                $r = aw_msg_start($db, $uid, $to, (string)($_POST['body'] ?? ''), !empty($_POST['age_ok']));
                if ($r['ok']) $go('t=' . (int)$r['thread'] . '&sent=1');
                $error = $r['message'];
            }
        } elseif ($act === 'team') {
            // writing to the team needs no age confirmation and is never blocked
            $tid = aw_msg_team_thread($db, $uid, true);
            $r = aw_msg_send($db, $tid, $uid, (string)($_POST['body'] ?? ''));
            if ($r['ok']) $go('t=' . $tid . '#end');
            $error = $r['message'];
        } elseif ($act === 'accept' || $act === 'decline') {
            aw_msg_answer_request($db, $tid, $uid, $act === 'accept');
            $go($act === 'accept' ? 't=' . $tid : 'view=requests&msg=declined');
        } elseif ($act === 'block' || $act === 'unblock') {
            $other = (int)($_POST['user'] ?? 0);
            // only someone you share a conversation with (or already blocked) can be blocked from here
            $ok = $act === 'unblock' || ($tid && ($o = aw_msg_open($db, $tid, $uid)) && !$o['staff'] && $o['other'] === $other);
            if ($ok && $other) {
                aw_block($db, $uid, $other, $act === 'block');
                if ($act === 'block' && $tid) aw_msg_answer_request($db, $tid, $uid, false);
            }
            $go($act === 'block' ? 'msg=blocked' : 'view=settings&msg=unblocked');
        } elseif ($act === 'delete') {
            aw_msg_delete_own($db, (int)($_POST['m'] ?? 0), $uid);
            $go('t=' . $tid . '#end');
        } elseif ($act === 'settings') {
            aw_msg_set_who($db, $uid, (string)($_POST['who'] ?? 'everyone'));
            $go('view=settings&msg=saved');
        }
    }
}

$view = (string)($_GET['view'] ?? '');
$tid = (int)($_GET['t'] ?? 0);
$to = (int)($_GET['to'] ?? 0);
$open = $tid ? aw_msg_open($db, $tid, $uid) : null;
if ($tid && !$open) { http_response_code(404); }
if ($to) { // "Message" buttons: go to an existing conversation if there is one
    $ex = aw_msg_direct_thread($db, $uid, $to);
    if ($ex && $ex['my_status'] === 'active') $go('t=' . (int)$ex['id']);
}
if ($view === 'writeteam' && ($ttid = aw_msg_team_thread($db, $uid))) $go('t=' . $ttid);
if ($open) aw_msg_mark_read($db, $open, $uid);
$inbox = aw_msg_inbox($db, $uid);
$teamList = ($staff && ($view === 'team' || ($open && $open['staff']))) ? aw_msg_team_inbox($db) : [];
$notice = ['declined' => 'Request declined. They will not be told.', 'blocked' => 'Blocked. They cannot message you, and you will not hear from them.', 'unblocked' => 'Unblocked.', 'saved' => 'Saved.'][$_GET['msg'] ?? ''] ?? '';
if (!empty($_GET['sent'])) $notice = 'Sent. They will see your message once they accept your request.';
$token = generate_csrf_token();
$member = aw_cp_member($db, $uid);
$needAge = empty($member['age_confirmed_at']) && !$staff;
$label = function ($id) use ($db) { return aw_msg_name($db, (int)$id); };

$pageTitle = 'Messages';
$aw_noindex = true;
require_once 'includes/header.php';
?>
<link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/aw-messages.css?v=1">
<main class="msg" id="main">
<div class="container">
    <header class="msg-head">
        <h1>Messages</h1>
        <nav class="msg-tabs" aria-label="Messages">
            <a href="<?php echo $self; ?>"<?php echo $view === '' && !$tid && !$to && empty($_GET['new']) ? ' aria-current="page"' : ''; ?>>Conversations</a>
            <a href="<?php echo $self; ?>?view=requests"<?php echo $view === 'requests' ? ' aria-current="page"' : ''; ?>>Requests<?php echo $inbox['requests'] ? ' <span class="msg-count">' . count($inbox['requests']) . '</span>' : ''; ?></a>
            <?php if ($staff): ?><a href="<?php echo $self; ?>?view=team"<?php echo $view === 'team' ? ' aria-current="page"' : ''; ?>>Team conversations</a><?php endif; ?>
            <a href="<?php echo $self; ?>?view=settings"<?php echo $view === 'settings' ? ' aria-current="page"' : ''; ?>>Settings</a>
            <a class="msg-new" href="<?php echo $self; ?>?new=1"><i class="fas fa-pen" aria-hidden="true"></i> New message</a>
        </nav>
    </header>
    <?php echo aw_alert('error', $error); echo aw_alert('success', $notice); ?>

    <div class="msg-layout<?php echo ($open || $to || !empty($_GET['new'])) ? ' has-thread' : ''; ?>">
        <aside class="msg-list" aria-label="Your conversations">
            <?php
            $rows = $view === 'team' ? $teamList : $inbox['active'];
            if ($view === 'requests') $rows = $inbox['requests'];
            ?>
            <?php if (!$rows): ?>
                <p class="msg-empty"><?php echo $view === 'requests' ? 'No requests. When someone new writes to you, you choose whether to read it here.' : ($view === 'team' ? 'No team conversations yet.' : 'No conversations yet.'); ?></p>
                <?php if ($view === ''): ?><a class="msg-team-link" href="<?php echo $self; ?>?view=writeteam"><i class="fas fa-heart" aria-hidden="true"></i> Write to the AngelWrites team</a><?php endif; ?>
            <?php else: ?>
                <ul>
                <?php foreach ($rows as $r):
                    if ($view === 'team') { $name = $label($r['member_id']); $unread = (int)$r['team_read_id'] < (int)$r['last_id']; $href = '?t=' . (int)$r['id'] . '&team=1'; }
                    else { $name = $r['kind'] === 'team' ? 'AngelWrites Team' : $label($r['other_id']); $unread = (int)$r['unread'] > 0; $href = '?t=' . (int)$r['id']; }
                    $preview = $view === 'requests' ? 'Would like to message you' : mb_strimwidth(preg_replace('/\s+/u', ' ', (string)$r['last_body']), 0, 70, '…');
                    if ($view === '' && ($r['other_status'] ?? '') === 'requested') $preview = 'Waiting for them to accept';
                ?>
                    <li><a href="<?php echo $href; ?>" class="msg-item<?php echo $unread ? ' is-unread' : ''; ?><?php echo $open && (int)$open['thread']['id'] === (int)$r['id'] ? ' is-open' : ''; ?>"<?php echo $open && (int)$open['thread']['id'] === (int)$r['id'] ? ' aria-current="true"' : ''; ?>>
                        <span class="msg-avatar" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1))); ?></span>
                        <span class="msg-item-text"><strong><?php echo htmlspecialchars($name); ?></strong><span><?php echo htmlspecialchars($preview); ?></span></span>
                        <span class="msg-item-meta"><time datetime="<?php echo htmlspecialchars($r['last_message_at']); ?>"><?php echo htmlspecialchars(date(date('Y-m-d') === substr((string)$r['last_message_at'], 0, 10) ? 'g:i a' : 'j M', strtotime($r['last_message_at']))); ?></time><?php echo $unread ? '<span class="msg-dot"><span class="sr-only">unread</span></span>' : ''; ?></span>
                    </a></li>
                <?php endforeach; ?>
                </ul>
                <?php if ($view === ''): ?><a class="msg-team-link" href="<?php echo $self; ?>?view=writeteam"><i class="fas fa-heart" aria-hidden="true"></i> Write to the AngelWrites team</a><?php endif; ?>
            <?php endif; ?>
        </aside>

        <section class="msg-pane" aria-label="Conversation">
        <?php if ($tid && !$open): ?>
            <?php echo aw_empty('This conversation is not available', 'It may not exist, or you may not be part of it.', [$self, 'Back to Messages'], 'fa-envelope'); ?>

        <?php elseif ($open):
            $t = $open['thread']; $isTeam = $t['kind'] === 'team';
            $other = $open['other']; $otherName = $isTeam ? ($open['staff'] ? $label($other) : 'AngelWrites Team') : $label($other);
            $requested = $open['me'] && $open['me']['status'] === 'requested';
            $waiting = false;
            if (!$isTeam && !$requested) { try { $q = $db->prepare("SELECT status FROM msg_participants WHERE thread_id = ? AND user_id = ?"); $q->execute([(int)$t['id'], $other]); $waiting = (string)$q->fetchColumn() !== 'active'; } catch (Throwable $e) {} }
            $blocked = !$isTeam && aw_is_blocked($db, $uid, $other);
            $msgs = $requested ? [] : aw_msg_messages($db, (int)$t['id']);
        ?>
            <div class="msg-thread-head">
                <a class="msg-back" href="<?php echo $self . ($open['staff'] ? '?view=team' : ''); ?>"><i class="fas fa-chevron-left" aria-hidden="true"></i><span class="sr-only">All conversations</span></a>
                <h2><?php echo htmlspecialchars($otherName); ?><?php echo $isTeam && $open['staff'] ? ' <small>writing to the team</small>' : ''; ?></h2>
                <?php if (!$isTeam && !$requested): ?>
                <form method="post" onsubmit="return confirm('Block <?php echo htmlspecialchars(addslashes($otherName)); ?>? They will not be able to message you, and they will not be told.');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="action" value="<?php echo $blocked ? 'unblock' : 'block'; ?>"><input type="hidden" name="t" value="<?php echo (int)$t['id']; ?>"><input type="hidden" name="user" value="<?php echo (int)$other; ?>">
                    <button type="submit" class="msg-quiet"><?php echo $blocked ? 'Unblock' : 'Block'; ?></button>
                </form>
                <?php endif; ?>
            </div>

            <?php if ($requested): ?>
                <div class="msg-request">
                    <p><strong><?php echo htmlspecialchars($otherName); ?></strong> would like to message you. Their message stays hidden until you accept.</p>
                    <form method="post" class="msg-request-acts">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="t" value="<?php echo (int)$t['id']; ?>"><input type="hidden" name="user" value="<?php echo (int)$other; ?>">
                        <button class="msg-btn" name="action" value="accept">Accept and read</button>
                        <button class="msg-btn msg-btn-soft" name="action" value="decline">Decline</button>
                        <button class="msg-quiet" name="action" value="block" onclick="return confirm('Block them? They will not be told.');">Block</button>
                    </form>
                    <p class="msg-hint">Declining is private: they will not be told.</p>
                </div>
            <?php else: ?>
                <ol class="msg-bubbles" aria-label="Messages">
                <?php $day = ''; foreach ($msgs as $m): $mine = (int)$m['sender_id'] === $uid && !($open['staff'] && !$m['as_team']); $fromTeam = (int)$m['as_team'] === 1;
                    if ($open['staff']) $mine = $fromTeam;
                    $d = date('j F Y', strtotime($m['created_at'])); if ($d !== $day) { $day = $d; echo '<li class="msg-day"><span>' . htmlspecialchars($d) . '</span></li>'; } ?>
                    <li class="msg-b<?php echo $mine ? ' is-mine' : ''; ?><?php echo $fromTeam ? ' is-team' : ''; ?>" id="m<?php echo (int)$m['id']; ?>">
                        <?php if (!$mine): ?><span class="msg-from"><?php echo $fromTeam ? 'AngelWrites Team' : htmlspecialchars($isTeam ? $label($m['sender_id']) : $otherName); ?></span><?php endif; ?>
                        <?php if ($m['status'] !== 'sent'): ?><p class="msg-removed">This message was removed.</p>
                        <?php else: ?><p><?php echo nl2br(htmlspecialchars($m['body'])); ?></p><?php endif; ?>
                        <span class="msg-time"><?php echo htmlspecialchars(date('g:i a', strtotime($m['created_at']))); ?>
                            <?php if ($m['status'] === 'sent' && (int)$m['sender_id'] === $uid && !$fromTeam): ?>
                                <form method="post" class="msg-inline" onsubmit="return confirm('Delete this message for everyone?');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="t" value="<?php echo (int)$t['id']; ?>"><input type="hidden" name="m" value="<?php echo (int)$m['id']; ?>"><button class="msg-link">Delete</button></form>
                            <?php elseif ($m['status'] === 'sent' && !$fromTeam && !$open['staff']): ?>
                                <span class="aw-rb" data-type="message" data-id="<?php echo (int)$m['id']; ?>" data-user="" data-name=""><button type="button" class="msg-link aw-rb-btn" data-act="report">Report</button></span>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
                <li id="end" class="msg-end"></li>
                </ol>
                <?php if ($blocked): ?>
                    <p class="msg-note">You blocked this member. Unblock them to write again.</p>
                <?php elseif ($waiting): ?>
                    <p class="msg-note">Your first message is waiting for them to accept. You can write more once they do.</p>
                <?php else: ?>
                    <form method="post" class="msg-compose">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="action" value="send"><input type="hidden" name="t" value="<?php echo (int)$t['id']; ?>">
                        <?php if ($open['staff']): ?><input type="hidden" name="team" value="1"><p class="msg-hint">You are writing as the AngelWrites Team.</p><?php endif; ?>
                        <label class="sr-only" for="msgBody">Message</label>
                        <textarea id="msgBody" name="body" rows="2" maxlength="<?php echo AW_MSG_LIMITS['body']; ?>" placeholder="Write a message…" required><?php echo htmlspecialchars($error !== '' ? (string)($_POST['body'] ?? '') : ''); ?></textarea>
                        <button type="submit" class="msg-send" aria-label="Send"><i class="fas fa-paper-plane" aria-hidden="true"></i></button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

        <?php elseif ($to || !empty($_GET['new'])):
            $toName = $to ? $label($to) : '';
            $why = $to ? aw_msg_can_write($db, $uid, $to) : '';
        ?>
            <div class="msg-thread-head"><a class="msg-back" href="<?php echo $self; ?>"><i class="fas fa-chevron-left" aria-hidden="true"></i><span class="sr-only">All conversations</span></a><h2><?php echo $to ? 'Message ' . htmlspecialchars($toName) : 'New message'; ?></h2></div>
            <?php if ($why): ?>
                <p class="msg-note"><?php echo htmlspecialchars($why); ?></p>
            <?php else: ?>
            <form method="post" class="msg-start">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="action" value="start">
                <?php if ($to): ?><input type="hidden" name="to" value="<?php echo $to; ?>">
                <?php else: ?>
                    <label for="msgUser">To <span>(their username)</span></label>
                    <input type="text" id="msgUser" name="username" required autocomplete="off" value="<?php echo htmlspecialchars((string)($_POST['username'] ?? '')); ?>" placeholder="e.g. blessingsemulyn">
                <?php endif; ?>
                <label for="msgFirst">Your message</label>
                <textarea id="msgFirst" name="body" rows="5" maxlength="<?php echo AW_MSG_LIMITS['body']; ?>" required><?php echo htmlspecialchars((string)($_POST['body'] ?? '')); ?></textarea>
                <p class="msg-hint">If you have not talked before, this arrives as a request. They choose whether to read it.</p>
                <?php if ($needAge): ?><label class="msg-age"><input type="checkbox" name="age_ok" value="1" required> I confirm I am 18 or older. Messages are for adults.</label><?php endif; ?>
                <button type="submit" class="msg-btn"><i class="fas fa-paper-plane" aria-hidden="true"></i> Send</button>
            </form>
            <?php endif; ?>

        <?php elseif ($view === 'writeteam'): ?>
            <div class="msg-thread-head"><a class="msg-back" href="<?php echo $self; ?>"><i class="fas fa-chevron-left" aria-hidden="true"></i><span class="sr-only">All conversations</span></a><h2>Write to the AngelWrites team</h2></div>
            <form method="post" class="msg-start">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="action" value="team">
                <label for="msgTeam">Your message</label>
                <textarea id="msgTeam" name="body" rows="5" maxlength="<?php echo AW_MSG_LIMITS['body']; ?>" required></textarea>
                <p class="msg-hint">The team reads every message and answers as “AngelWrites Team”. If you are in danger, please call someone now: <?php foreach (aw_cp_crisis_contacts() as [$l, $n]) echo htmlspecialchars($l) . ' <a href="tel:' . htmlspecialchars(preg_replace('/[^0-9+]/', '', $n)) . '">' . htmlspecialchars($n) . '</a> '; ?></p>
                <button type="submit" class="msg-btn">Send to the team</button>
            </form>

        <?php elseif ($view === 'settings'):
            $who = aw_msg_who($db, $uid);
            $blockedList = [];
            try { $q = $db->prepare("SELECT blocked_id FROM blocks WHERE blocker_id = ?"); $q->execute([$uid]); $blockedList = $q->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
        ?>
            <div class="msg-thread-head"><a class="msg-back" href="<?php echo $self; ?>"><i class="fas fa-chevron-left" aria-hidden="true"></i><span class="sr-only">All conversations</span></a><h2>Message settings</h2></div>
            <form method="post" class="msg-start">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="action" value="settings">
                <fieldset><legend>Who can send me a message request</legend>
                <?php foreach (AW_MSG_WHO as $k => $l): ?><label class="msg-radio"><input type="radio" name="who" value="<?php echo $k; ?>"<?php echo $who === $k ? ' checked' : ''; ?>> <?php echo htmlspecialchars($l); ?></label><?php endforeach; ?>
                </fieldset>
                <button type="submit" class="msg-btn">Save</button>
            </form>
            <p class="msg-hint">Emails about new messages: choose in <a href="<?php echo SITE_URL; ?>/notifications.php">notification settings</a>. An email never contains the message itself.</p>
            <h3 class="msg-sub">People you blocked</h3>
            <?php if (!$blockedList): ?><p class="msg-hint">Nobody.</p><?php else: ?><ul class="msg-blocked"><?php foreach ($blockedList as $bid): ?>
                <li><span><?php echo htmlspecialchars($label($bid)); ?></span><form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="action" value="unblock"><input type="hidden" name="user" value="<?php echo (int)$bid; ?>"><button class="msg-quiet">Unblock</button></form></li>
            <?php endforeach; ?></ul><?php endif; ?>

        <?php else: ?>
            <div class="msg-placeholder"><i class="far fa-envelope" aria-hidden="true"></i><p><?php echo $view === 'requests' ? 'Choose a request to accept or decline it.' : 'Choose a conversation, or start a new one.'; ?></p></div>
        <?php endif; ?>
        </section>
    </div>
</div>
</main>
<?php echo aw_report_assets(); ?>
<script>
(function () {
  var end = document.getElementById('end'); if (end && !location.hash) end.scrollIntoView({ block: 'end' });
  var ta = document.getElementById('msgBody');
  if (ta) {
    ta.addEventListener('input', function () { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 220) + 'px'; });
    ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); ta.form.requestSubmit ? ta.form.requestSubmit() : ta.form.submit(); } });
  }
})();
</script>
<?php require_once 'includes/footer.php'; ?>
