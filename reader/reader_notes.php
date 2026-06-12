<?php
// ============================================================
//  READER_NOTES.PHP – Fully enhanced group notes panel
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reading_groups.php';

$book_id = isset($book_id) ? $book_id : 0;
$current_chapter = isset($current_chapter) ? $current_chapter : 0;
$user_id = isLoggedIn() ? $_SESSION['user_id'] : 0;
$group_id = null;

if ($user_id > 0 && $book_id > 0) {
    $stmt = $db->prepare("
        SELECT g.id FROM reading_groups g
        JOIN group_members m ON g.id = m.group_id
        WHERE g.book_id = ? AND m.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$book_id, $user_id]);
    $group_id = $stmt->fetchColumn();

    if ($group_id) {
        // Fetch notes
        $stmt = $db->prepare("
            SELECT n.*, u.username, u.display_name, u.avatar,
            (SELECT COUNT(*) FROM group_reactions WHERE target_type = 'note' AND target_id = n.id) as reaction_count
            FROM group_notes n
            JOIN users u ON n.user_id = u.id
            WHERE n.group_id = ? AND n.book_id = ? AND n.chapter_index = ?
            ORDER BY n.created_at DESC
        ");
        $stmt->execute([$group_id, $book_id, $current_chapter]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Count my notes for this chapter
        $stmt = $db->prepare("SELECT COUNT(*) FROM group_notes WHERE group_id = ? AND book_id = ? AND chapter_index = ? AND user_id = ?");
        $stmt->execute([$group_id, $book_id, $current_chapter, $user_id]);
        $my_notes_count = $stmt->fetchColumn();
        ?>
        <div id="awNotesPanel" class="aw-notes-panel" style="display:none;">
            <div class="aw-notes-header">
                <div class="aw-notes-title">
                    <h3>📝 Group Notes</h3>
                    <span class="badge"><?php echo count($notes); ?> notes</span>
                </div>
                <div class="aw-notes-actions">
                    <button class="btn btn-sm btn-primary" onclick="addGroupNote()">
                        <i class="fas fa-plus"></i> Add Note
                    </button>
                    <button class="btn btn-sm btn-outline" onclick="toggleNotesPanel()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <div class="aw-notes-body">
                <?php if (empty($notes)): ?>
                    <p class="empty-notes">No notes for this chapter. Be the first to add one!</p>
                <?php else: ?>
                    <div class="notes-list">
                        <?php foreach ($notes as $note): ?>
                            <div class="note-card <?php echo $note['is_private'] ? 'private' : ''; ?>">
                                <div class="note-author">
                                    <?php if ($note['avatar']): ?>
                                        <img src="<?php echo htmlspecialchars($note['avatar']); ?>" class="note-avatar">
                                    <?php else: ?>
                                        <div class="note-avatar-placeholder">
                                            <?php echo strtoupper(substr($note['display_name'] ?: $note['username'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="note-author-info">
                                        <strong><?php echo htmlspecialchars($note['display_name'] ?: $note['username']); ?></strong>
                                        <small><?php echo time_ago($note['created_at']); ?></small>
                                        <?php if ($note['is_private']): ?>
                                            <span class="badge-private">🔒 Private</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <p class="note-text"><?php echo htmlspecialchars($note['text']); ?></p>
                                <div class="note-footer">
                                    <div class="note-reactions">
                                        <?php
                                        $reactions = getReactions('note', $note['id']);
                                        foreach ($reactions as $reaction): ?>
                                            <span class="reaction" onclick="reactNote(<?php echo $note['id']; ?>, '<?php echo $reaction['reaction_type']; ?>')">
                                                <?php echo $reaction['reaction_type']; ?> <?php echo $reaction['count']; ?>
                                            </span>
                                        <?php endforeach; ?>
                                        <?php if (!$note['is_private'] || $note['user_id'] == $user_id): ?>
                                            <button class="btn btn-sm btn-outline" onclick="showReactionPicker(<?php echo $note['id']; ?>)">
                                                ➕ React
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($note['user_id'] == $user_id): ?>
                                        <button class="btn btn-sm btn-danger" onclick="deleteReaderNote(<?php echo $note['id']; ?>)">
                                            🗑️
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Add Note Form (hidden by default) -->
            <div id="awAddNoteForm" style="display:none; padding:12px; border-top:1px solid var(--border);">
                <form onsubmit="submitReaderNote(event)">
                    <div class="form-group">
                        <textarea id="awNoteText" rows="2" placeholder="Share your thoughts on this chapter..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="awNotePrivate"> Make this note private
                        </label>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="btn btn-sm btn-primary">Post Note</button>
                        <button type="button" class="btn btn-sm btn-secondary" id="awCancelNote" onclick="cancelAddNote()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Reaction Picker (floating) -->
        <div id="awReactionPicker" style="display:none; position:fixed; background:var(--card-bg); border:1px solid var(--border); border-radius:8px; padding:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1); z-index:50;">
            <button onclick="addReaction('👍')">👍</button>
            <button onclick="addReaction('❤️')">❤️</button>
            <button onclick="addReaction('🙏')">🙏</button>
            <button onclick="addReaction('🤔')">🤔</button>
            <button onclick="addReaction('📖')">📖</button>
        </div>
        <?php
    }
}
?>