<div class="card">
    <div class="card-header">
        <h2>🏷️ Subscriber Segments</h2>
        <button class="btn btn-sm btn-primary" onclick="document.getElementById('createSegmentForm').style.display='block'">
            + New Segment
        </button>
    </div>
    <div class="card-body">
        <div id="createSegmentForm" style="display:none;margin-bottom:20px;padding:16px;border:1px solid var(--border);border-radius:8px;">
            <form method="POST">
                <input type="hidden" name="action" value="create_segment">
                <div class="form-group">
                    <label for="segment_name">Segment Name</label>
                    <input type="text" id="segment_name" name="segment_name" required>
                </div>
                <div class="form-group">
                    <label for="segment_description">Description</label>
                    <textarea id="segment_description" name="segment_description" rows="2"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Create Segment</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('createSegmentForm').style.display='none'">Cancel</button>
            </form>
        </div>

        <?php if (count($segments) > 0): ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Subscribers</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($segments as $seg): ?>
                            <?php
                            $stmt = $db->prepare("SELECT COUNT(*) FROM subscriber_segment_assignments WHERE segment_id = ?");
                            $stmt->execute([$seg['id']]);
                            $count = $stmt->fetchColumn();
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($seg['name']); ?></td>
                                <td><?php echo htmlspecialchars($seg['description'] ?? '—'); ?></td>
                                <td><?php echo $count; ?></td>
                                <td><?php echo date('M j, Y', strtotime($seg['created_at'])); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-danger" onclick="if(confirm('Delete segment?')){document.getElementById('deleteSegment<?php echo $seg['id']; ?>').submit();}">
                                        🗑️
                                    </button>
                                    <form id="deleteSegment<?php echo $seg['id']; ?>" method="POST" style="display:none;">
                                        <input type="hidden" name="action" value="delete_segment">
                                        <input type="hidden" name="segment_id" value="<?php echo $seg['id']; ?>">
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="no-items">No segments created yet.</p>
        <?php endif; ?>
    </div>
</div>