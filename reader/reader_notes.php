<?php
// ============================================================
//  READER_NOTES.PHP – Enhanced group notes panel
//  Can be included in reader.php or used as a standalone page.
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reading_groups.php';
require_once __DIR__ . '/reader_functions.php'; // for time_ago etc.

// --- Get parameters ---
$book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : (isset($book_id) ? (int)$book_id : 0);
$chapter = isset($_GET['chapter']) ? (int)$_GET['chapter'] : (isset($current_chapter) ? (int)$current_chapter : 0);
$user_id = isLoggedIn() ? $_SESSION['user_id'] : 0;
$group_id = null;

// --- Determine group ---
if ($user_id > 0 && $book_id > 0) {
    $stmt = $db->prepare("
        SELECT g.id FROM reading_groups g
        JOIN group_members m ON g.id = m.group_id
        WHERE g.book_id = ? AND m.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$book_id, $user_id]);
    $group_id = $stmt->fetchColumn();
}

// --- If no group or user, show error ---
if (!$group_id || !$user_id) {
    echo '<div class="aw-notes-error">You are not a member of a reading group for this book.</div>';
    exit;
}

// --- Fetch notes for this chapter ---
$stmt = $db->prepare("
    SELECT n.*, u.username, u.display_name, u.avatar,
           (SELECT COUNT(*) FROM group_reactions WHERE target_type = 'note' AND target_id = n.id) as reaction_count
    FROM group_notes n
    JOIN users u ON n.user_id = u.id
    WHERE n.group_id = ? AND n.book_id = ? AND n.chapter_index = ?
    ORDER BY n.created_at DESC
");
$stmt->execute([$group_id, $book_id, $chapter]);
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Get reactions for each note ---
foreach ($notes as &$note) {
    $stmt = $db->prepare("
        SELECT reaction_type, COUNT(*) as count
        FROM group_reactions
        WHERE target_type = 'note' AND target_id = ?
        GROUP BY reaction_type
    ");
    $stmt->execute([$note['id']]);
    $note['reactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- Count my notes ---
$stmt = $db->prepare("SELECT COUNT(*) FROM group_notes WHERE group_id = ? AND book_id = ? AND chapter_index = ? AND user_id = ?");
$stmt->execute([$group_id, $book_id, $chapter, $user_id]);
$my_notes_count = $stmt->fetchColumn();

// --- HTML Output ---
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Group Notes</title>
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .aw-notes-panel {
            max-width: 800px;
            margin: 20px auto;
            background: var(--card-bg);
            border-radius: 16px;
            box-shadow: var(--shadow-hover);
            padding: 20px;
            font-family: 'Inter', sans-serif;
        }
        .aw-notes-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .aw-notes-title h3 {
            margin: 0;
            font-size: 1.2rem;
            color: var(--dark);
        }
        .aw-notes-title .badge {
            background: var(--rose-light);
            color: var(--rose-dark);
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            margin-left: 8px;
        }
        .aw-notes-actions {
            display: flex;
            gap: 8px;
        }
        .aw-notes-actions .btn {
            border: none;
            padding: 6px 14px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        .aw-notes-actions .btn-primary {
            background: var(--rose);
            color: white;
        }
        .aw-notes-actions .btn-primary:hover {
            background: var(--rose-dark);
        }
        .aw-notes-actions .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text);
        }
        .aw-notes-actions .btn-outline:hover {
            background: var(--border);
        }
        .aw-notes-body {
            max-height: 500px;
            overflow-y: auto;
        }
        .empty-notes {
            text-align: center;
            color: var(--text-light);
            padding: 40px 0;
        }
        .note-card {
            background: var(--bg);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            border: 1px solid var(--border);
            transition: all 0.2s;
        }
        .note-card.private {
            border-left: 4px solid var(--rose);
        }
        .note-author {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }
        .note-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }
        .note-avatar-placeholder {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--rose);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .note-author-info {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
        }
        .note-author-info strong {
            color: var(--dark);
        }
        .note-author-info small {
            color: var(--text-light);
            font-size: 0.8rem;
        }
        .badge-private {
            background: var(--rose-light);
            color: var(--rose-dark);
            padding: 0 8px;
            border-radius: 10px;
            font-size: 0.7rem;
        }
        .note-text {
            margin: 8px 0 12px;
            line-height: 1.6;
            color: var(--text);
        }
        .note-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .note-reactions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .note-reactions .reaction {
            background: var(--border);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .note-reactions .reaction:hover {
            background: var(--rose-light);
        }
        .note-footer .btn-danger {
            background: transparent;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            font-size: 1rem;
            transition: color 0.2s;
        }
        .note-footer .btn-danger:hover {
            color: #e74c3c;
        }
        #awAddNoteForm {
            border-top: 1px solid var(--border);
            padding-top: 16px;
            margin-top: 16px;
            display: none;
        }
        #awAddNoteForm .form-group {
            margin-bottom: 10px;
        }
        #awAddNoteForm textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--input-bg);
            color: var(--text);
            font-family: 'Inter', sans-serif;
            resize: vertical;
            min-height: 60px;
        }
        #awAddNoteForm textarea:focus {
            outline: none;
            border-color: var(--rose);
        }
        #awAddNoteForm label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
        }
        #awAddNoteForm .btn {
            padding: 6px 16px;
            border-radius: 20px;
            border: none;
            cursor: pointer;
            font-size: 0.85rem;
        }
        #awAddNoteForm .btn-primary {
            background: var(--rose);
            color: white;
        }
        #awAddNoteForm .btn-primary:hover {
            background: var(--rose-dark);
        }
        #awAddNoteForm .btn-secondary {
            background: var(--border);
            color: var(--text);
        }
        #awAddNoteForm .btn-secondary:hover {
            background: var(--text-light);
            color: white;
        }
        #awReactionPicker {
            display: none;
            position: fixed;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 8px 12px;
            box-shadow: var(--shadow-hover);
            z-index: 10000;
            gap: 6px;
        }
        #awReactionPicker button {
            background: transparent;
            border: none;
            font-size: 1.4rem;
            cursor: pointer;
            transition: transform 0.2s;
        }
        #awReactionPicker button:hover {
            transform: scale(1.2);
        }
        .aw-notes-error {
            text-align: center;
            color: var(--text-light);
            padding: 40px 20px;
            background: var(--card-bg);
            border-radius: 16px;
            max-width: 600px;
            margin: 40px auto;
        }
    </style>
</head>
<body>
<div class="aw-notes-panel" id="awNotesPanel">
    <div class="aw-notes-header">
        <div class="aw-notes-title">
            <h3>📝 Group Notes</h3>
            <span class="badge"><?php echo count($notes); ?> notes</span>
        </div>
        <div class="aw-notes-actions">
            <button class="btn btn-primary" onclick="toggleNoteForm()">
                <i class="fas fa-plus"></i> Add Note
            </button>
        </div>
    </div>

    <div class="aw-notes-body">
        <?php if (empty($notes)): ?>
            <p class="empty-notes">No notes for this chapter. Be the first to add one!</p>
        <?php else: ?>
            <div class="notes-list">
                <?php foreach ($notes as $note): ?>
                    <div class="note-card <?php echo $note['is_private'] ? 'private' : ''; ?>" data-note-id="<?php echo $note['id']; ?>">
                        <div class="note-author">
                            <?php if ($note['avatar']): ?>
                                <img src="<?php echo htmlspecialchars($note['avatar']); ?>" class="note-avatar" alt="Avatar">
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
                                <?php foreach ($note['reactions'] as $reaction): ?>
                                    <span class="reaction" onclick="reactNote(<?php echo $note['id']; ?>, '<?php echo $reaction['reaction_type']; ?>')">
                                        <?php echo $reaction['reaction_type']; ?> <?php echo $reaction['count']; ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if (!$note['is_private'] || $note['user_id'] == $user_id): ?>
                                    <button class="btn btn-sm btn-outline" onclick="showReactionPicker(<?php echo $note['id']; ?>, event)">
                                        ➕
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php if ($note['user_id'] == $user_id): ?>
                                <button class="btn-danger" onclick="deleteNote(<?php echo $note['id']; ?>)">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add Note Form -->
    <div id="awAddNoteForm">
        <form id="awNoteForm" onsubmit="submitNote(event)">
            <div class="form-group">
                <textarea id="awNoteText" rows="2" placeholder="Share your thoughts on this chapter..." required></textarea>
                <input type="hidden" id="awNoteHighlightId" value="0">
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" id="awNotePrivate"> Make this note private
                </label>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary">Post Note</button>
                <button type="button" class="btn btn-secondary" onclick="toggleNoteForm()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Reaction Picker -->
<div id="awReactionPicker">
    <button onclick="addReaction('👍')">👍</button>
    <button onclick="addReaction('❤️')">❤️</button>
    <button onclick="addReaction('🙏')">🙏</button>
    <button onclick="addReaction('🤔')">🤔</button>
    <button onclick="addReaction('📖')">📖</button>
    <button onclick="closeReactionPicker()" style="color:var(--text-light);font-size:0.9rem;">✕</button>
</div>

<script>
    // --- Global state ---
    const groupId = <?php echo $group_id; ?>;
    const bookId = <?php echo $book_id; ?>;
    const chapter = <?php echo $chapter; ?>;
    const userId = <?php echo $user_id; ?>;
    let currentNoteId = null;

    // --- Toggle note form ---
    function toggleNoteForm() {
        const form = document.getElementById('awAddNoteForm');
        form.style.display = form.style.display === 'none' ? 'block' : 'none';
        if (form.style.display === 'block') {
            document.getElementById('awNoteText').focus();
        }
    }

    // --- Submit note ---
    function submitNote(e) {
        e.preventDefault();
        const text = document.getElementById('awNoteText').value.trim();
        const isPrivate = document.getElementById('awNotePrivate').checked ? 1 : 0;
        const highlightId = document.getElementById('awNoteHighlightId').value;

        if (!text) {
            alert('Please write a note.');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'add_reader_note');
        formData.append('group_id', groupId);
        formData.append('book_id', bookId);
        formData.append('chapter_index', chapter);
        formData.append('text', text);
        formData.append('is_private', isPrivate);
        if (highlightId > 0) {
            formData.append('highlight_id', highlightId);
        }

        fetch('/reader/reader_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload(); // simple refresh; could also dynamically add the note
            } else {
                alert('Error: ' + (data.error || 'Could not add note.'));
            }
        })
        .catch(() => alert('Server error. Please try again.'));
    }

    // --- Delete note ---
    function deleteNote(noteId) {
        if (!confirm('Delete this note?')) return;
        const formData = new FormData();
        formData.append('action', 'delete_reader_note');
        formData.append('note_id', noteId);

        fetch('/reader/reader_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Could not delete note.'));
            }
        })
        .catch(() => alert('Server error.'));
    }

    // --- React to a note ---
    function reactNote(noteId, reaction) {
        const formData = new FormData();
        formData.append('action', 'toggle_note_reaction');
        formData.append('note_id', noteId);
        formData.append('reaction_type', reaction);

        fetch('/reader/reader_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Could not react.'));
            }
        })
        .catch(() => alert('Server error.'));
    }

    // --- Show reaction picker ---
    function showReactionPicker(noteId, event) {
        currentNoteId = noteId;
        const picker = document.getElementById('awReactionPicker');
        const btn = event.target.closest('button');
        const rect = btn.getBoundingClientRect();
        picker.style.top = (rect.top - 50) + 'px';
        picker.style.left = (rect.left) + 'px';
        picker.style.display = 'flex';
        // Close picker when clicking outside
        setTimeout(() => {
            document.addEventListener('click', function handler(e) {
                if (!picker.contains(e.target) && e.target !== btn) {
                    picker.style.display = 'none';
                    document.removeEventListener('click', handler);
                }
            });
        }, 10);
    }

    function closeReactionPicker() {
        document.getElementById('awReactionPicker').style.display = 'none';
    }

    // --- Add reaction from picker ---
    function addReaction(reaction) {
        if (currentNoteId) {
            reactNote(currentNoteId, reaction);
        }
        closeReactionPicker();
    }

    // --- Set highlight_id from URL parameter (if passed) ---
    const urlParams = new URLSearchParams(window.location.search);
    const highlightId = urlParams.get('highlight_id');
    if (highlightId) {
        document.getElementById('awNoteHighlightId').value = highlightId;
    }
</script>
</body>
</html>