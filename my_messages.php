<?php
// K4: a member's messages to the team and the team's replies (signed "AngelWrites Team").
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/components.php';
require_once 'includes/team_inbox.php';

redirectIfNotLoggedIn();
aw_inbox_tables($db);
$uid = (int)$_SESSION['user_id'];
$msgs = []; $replies = [];
try {
    $st = $db->prepare("SELECT id, subject, message, created_at FROM contact_messages WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
    $st->execute([$uid]);
    $msgs = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($msgs) {
        $ids = array_column($msgs, 'id');
        $r = $db->prepare("SELECT message_id, body, created_at FROM team_replies WHERE message_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY id");
        $r->execute($ids);
        foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) $replies[(int)$row['message_id']][] = $row; // never the staff author
    }
} catch (Throwable $e) { error_log('my_messages: ' . $e->getMessage()); }

$pageTitle = 'Messages to the team';
require_once 'includes/header.php';
?>
<div class="container" style="max-width:780px;padding:32px 20px 60px;">
    <h1 style="font-family:'Playfair Display',Georgia,serif;">Messages to the team</h1>
    <p style="color:var(--text-light);">What you sent through Contact, and the team's replies.</p>
    <?php if (!$msgs): ?>
        <?php echo aw_empty('No messages yet', 'If you need to talk to the AngelWrites team, write to us. Replies appear here and in your email.', [SITE_URL . '/contact.php', 'Write to the team'], 'fa-envelope'); ?>
    <?php else: foreach ($msgs as $m): ?>
        <section class="mm-card" id="m<?php echo (int)$m['id']; ?>">
            <p class="mm-meta">You wrote · <?php echo htmlspecialchars(date('M j, Y', strtotime($m['created_at']))); ?></p>
            <h2><?php echo htmlspecialchars($m['subject'] ?: 'Your message'); ?></h2>
            <div class="mm-body"><?php echo nl2br(htmlspecialchars($m['message'])); ?></div>
            <?php foreach ($replies[(int)$m['id']] ?? [] as $r): ?>
                <div class="mm-reply">
                    <p class="mm-meta"><strong>AngelWrites Team</strong> · <?php echo htmlspecialchars(date('M j, Y', strtotime($r['created_at']))); ?></p>
                    <div class="mm-body"><?php echo nl2br(htmlspecialchars($r['body'])); ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($replies[(int)$m['id']])): ?><p class="mm-wait">The team has not replied yet. We read every message.</p><?php endif; ?>
        </section>
    <?php endforeach; endif; ?>
    <p><?php echo aw_link_btn(SITE_URL . '/contact.php', 'Write a new message', 'outline'); ?></p>
</div>
<style>
.mm-card { background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:18px 20px; margin:16px 0; }
.mm-card h2 { font-size:1.15rem; margin:0 0 8px; }
.mm-meta { color:var(--text-light); font-size:.88rem; margin:0 0 6px; }
.mm-body { line-height:1.65; overflow-wrap:anywhere; }
.mm-reply { margin-top:14px; padding:12px 14px; border-left:4px solid var(--rose); background:var(--fantasy); border-radius:0 12px 12px 0; }
.mm-wait { color:var(--text-light); font-style:italic; margin:12px 0 0; }
</style>
<?php require_once 'includes/footer.php'; ?>
