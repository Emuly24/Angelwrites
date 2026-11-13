<?php
/**
 * C2: choose "Angella's picks" — a poem, a story and a reflection, each with a sentence on why.
 * Shown on the home page. Admins only; POST + CSRF. See includes/picks.php.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/picks.php';

redirectIfNotAdmin();

$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_picks'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please reload the page and try again.';
    } else {
        $in = [];
        foreach (array_keys(AW_PICK_SLOTS) as $slot) {
            $in[$slot] = ['id' => (int)($_POST[$slot . '_id'] ?? 0), 'note' => (string)($_POST[$slot . '_note'] ?? '')];
        }
        if (aw_save_picks($db, $in)) {
            try {
                $db->prepare("INSERT INTO newsletter_audit_log (user_id, action, details) VALUES (?, ?, ?)")
                   ->execute([(int)$_SESSION['user_id'], 'picks_updated', 'Updated Angella\'s picks']);
            } catch (Throwable $e) { /* the audit table is optional */ }
            $notice = 'Saved. The picks now show on the home page.';
        } else {
            $error = 'The picks could not be saved. Please try again.';
        }
    }
}

$raw = aw_picks_raw($db);
$choices = aw_pick_choices($db);
$updated = (string)($raw['picks_updated_at'] ?? '');

$pageTitle = "Angella's Picks";
require_once '../includes/header.php';
?>
<div class="admin-page">
    <div class="container" style="max-width:860px;">
        <div class="admin-header">
            <div>
                <h1>Angella's Picks</h1>
                <p>Choose a poem, a story and a reflection to feature on the home page, with a sentence on why.</p>
            </div>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Dashboard</a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($notice): ?>
            <div class="alert alert-success" role="status"><?php echo htmlspecialchars($notice); ?> <a href="<?php echo SITE_URL; ?>/index.php#picks">See it on the home page</a></div>
        <?php endif; ?>
        <?php if ($updated !== ''): ?>
            <p class="field-hint" style="margin:0 0 16px;">Last changed <?php echo htmlspecialchars(date('F j, Y', strtotime($updated))); ?>. A fresh choice each week keeps the home page alive.</p>
        <?php endif; ?>

        <form method="POST" class="admin-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
            <?php foreach (AW_PICK_SLOTS as $slot => $meta):
                $cur = (int)($raw['pick_' . $slot . '_id'] ?? 0);
                $note = (string)($raw['pick_' . $slot . '_note'] ?? '');
                $list = $choices[$slot] ?? [];
            ?>
            <div class="card" style="margin-bottom:20px;">
                <div class="card-body">
                    <h2 style="font-size:1.2rem;margin:0 0 12px;"><i class="fas <?php echo $meta['icon']; ?>" aria-hidden="true" style="color:var(--rose);"></i> <?php echo htmlspecialchars($meta['label']); ?></h2>
                    <?php if (!$list): ?>
                        <p class="field-hint" style="margin:0;"><?php echo $slot === 'poem' ? 'There are no poems yet.' : ($slot === 'reflection' ? 'There are no published posts in "Christian Reflections" yet.' : 'There are no published blog posts yet.'); ?></p>
                        <input type="hidden" name="<?php echo $slot; ?>_id" value="0">
                    <?php else: ?>
                    <div class="form-group">
                        <label for="pick_<?php echo $slot; ?>">Featured <?php echo $slot; ?></label>
                        <select id="pick_<?php echo $slot; ?>" name="<?php echo $slot; ?>_id">
                            <option value="0">None (hide this pick)</option>
                            <?php foreach ($list as $id => $title): ?>
                                <option value="<?php echo (int)$id; ?>"<?php echo $id === $cur ? ' selected' : ''; ?>><?php echo htmlspecialchars($title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="note_<?php echo $slot; ?>">Why this one? <span class="field-hint">(one sentence, shown with the pick)</span></label>
                        <textarea id="note_<?php echo $slot; ?>" name="<?php echo $slot; ?>_note" rows="2" maxlength="240"><?php echo htmlspecialchars($note); ?></textarea>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <button type="submit" name="save_picks" value="1" class="btn btn-primary"><i class="fas fa-check" aria-hidden="true"></i> Save picks</button>
        </form>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>
