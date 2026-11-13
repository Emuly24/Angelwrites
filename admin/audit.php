<?php
/**
 * K1: the audit log. Who did what on the staff side, newest first. Owner and admins.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/components.php';
require_once '../includes/pagination.php';

aw_require('audit_log');

$rows = []; $total = 0;
$filter = trim((string)($_GET['action'] ?? ''));
try {
    aw_audit_table($db);
    $where = $filter !== '' ? ' WHERE a.action = ?' : '';
    $params = $filter !== '' ? [$filter] : [];
    $c = $db->prepare("SELECT COUNT(*) FROM audit_log a" . $where);
    $c->execute($params);
    $total = (int)$c->fetchColumn();
    [$page, $per] = aw_page(50);
    [$page, $offset] = aw_clamp($total, $page, $per);
    $st = $db->prepare("SELECT a.*, u.name, u.first_name, u.last_name, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id" . $where . " ORDER BY a.id DESC LIMIT ? OFFSET ?");
    $st->execute(array_merge($params, [$per, $offset]));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $actions = $db->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('audit page: ' . $e->getMessage());
    $actions = []; $page = 1; $per = 50;
}

$pageTitle = 'Audit Log';
require_once '../includes/header.php';
?>
<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <div><h1>Audit Log</h1><p>Every staff action that changes something, and refused attempts.</p></div>
            <div class="admin-actions"><?php echo aw_link_btn(SITE_URL . '/admin/dashboard.php', 'Back to Dashboard', 'outline', ['icon' => 'fa-arrow-left']); ?></div>
        </div>
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
            <label class="sr-only" for="actionFilter">Show only</label>
            <select name="action" id="actionFilter" style="min-height:44px;border-radius:10px;border:1px solid var(--border);padding:6px 10px;background:var(--card-bg);color:var(--text);">
                <option value="">All actions</option>
                <?php foreach ($actions as $a): ?><option value="<?php echo htmlspecialchars($a); ?>"<?php echo $a === $filter ? ' selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', $a)); ?></option><?php endforeach; ?>
            </select>
            <?php echo aw_btn('Show', 'outline', ['type' => 'submit', 'small' => true]); ?>
        </form>
        <?php if (!$rows): ?>
            <?php echo aw_empty('Nothing logged yet', 'Role changes, moderation decisions, team replies, backups and refused attempts will be listed here.', null, 'fa-clipboard-list'); ?>
        <?php else: ?>
        <div class="table-responsive"><table class="admin-table">
            <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Action</th><th scope="col">Details</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td style="white-space:nowrap;"><?php echo htmlspecialchars(date('M j, Y g:i a', strtotime($r['created_at']))); ?></td>
                    <td><?php echo $r['actor_id'] ? htmlspecialchars(awDisplayName($r)) . '<br><small style="color:var(--text-light);">' . htmlspecialchars(aw_role_label((string)$r['actor_role'])) . '</small>' : '<span style="color:var(--text-light);">Not signed in</span>'; ?></td>
                    <td><?php echo htmlspecialchars(str_replace('_', ' ', (string)$r['action'])); ?><?php echo $r['target_type'] ? '<br><small style="color:var(--text-light);">' . htmlspecialchars($r['target_type'] . ($r['target_id'] ? ' #' . (int)$r['target_id'] : '')) . '</small>' : ''; ?></td>
                    <td style="overflow-wrap:anywhere;"><?php echo htmlspecialchars((string)$r['details']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php echo aw_pager($total, $page, $per); ?>
        <?php endif; ?>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>
