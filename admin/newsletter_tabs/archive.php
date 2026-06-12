<div class="card">
    <div class="card-header">
        <h2>📦 Sent Newsletter Archive</h2>
    </div>
    <div class="card-body">
        <?php if (count($archive) > 0): ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Recipients</th>
                            <th>Sent</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($archive as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['subject']); ?></td>
                                <td><?php echo $item['recipient_count']; ?></td>
                                <td><?php echo date('M j, Y, g:i a', strtotime($item['sent_at'])); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline" onclick="previewArchive(<?php echo $item['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <a href="?delete_archive=<?php echo $item['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this archive entry?');">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="no-items">No archived newsletters yet.</p>
        <?php endif; ?>
    </div>
</div>

<script>
function previewArchive(id) {
    fetch('<?php echo SITE_URL; ?>/admin/newsletter_preview.php?id=' + id)
        .then(r => r.text())
        .then(html => {
            const w = window.open('', 'Preview', 'width=600,height=800');
            w.document.write(html);
        });
}
</script>