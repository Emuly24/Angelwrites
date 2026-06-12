<?php
// ============================================================
//  CREATE_GROUP.PHP – Create a new reading group
//  Fully enhanced with all features.
// ============================================================

require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/reading_groups.php';

redirectIfNotLoggedIn();
$user_id = $_SESSION['user_id'];

// ===== FETCH ALL BOOKS FOR DROPDOWN =====
$stmt = $db->prepare("SELECT id, title, author FROM books ORDER BY title ASC");
$stmt->execute();
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== HANDLE FORM SUBMISSION =====
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    $book_id = (int)$_POST['book_id'];
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $is_private = isset($_POST['is_private']) ? 1 : 0;

    if (empty($name)) {
        $error = 'Please enter a group name.';
    } elseif ($book_id <= 0) {
        $error = 'Please select a book.';
    } else {
        $group_id = createReadingGroup($book_id, $user_id, $name, $description, $is_private);
        if ($group_id) {
            $success = 'Group created successfully! <a href="group.php?id=' . $group_id . '">Go to your new group</a>';
        } else {
            $error = 'Failed to create group. Please try again.';
        }
    }
}

$pageTitle = 'Create a Reading Group';
?>
<?php require_once 'includes/header.php'; ?>

<div class="create-group-page">
    <div class="container">
        <div class="page-header">
            <h1>📚 Create a Reading Group</h1>
            <a href="groups.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Groups
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2>New Reading Group</h2>
            </div>
            <div class="card-body">
                <form method="POST" class="create-group-form">
                    <div class="form-group">
                        <label for="book_id">Book <span class="required">*</span></label>
                        <select id="book_id" name="book_id" required>
                            <option value="">Select a book…</option>
                            <?php foreach ($books as $book): ?>
                                <option value="<?php echo $book['id']; ?>">
                                    <?php echo htmlspecialchars($book['title']); ?> by <?php echo htmlspecialchars($book['author']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="name">Group Name <span class="required">*</span></label>
                        <input type="text" id="name" name="name" placeholder="e.g., The Faithful Readers" required>
                    </div>

                    <div class="form-group">
                        <label for="description">Description (optional)</label>
                        <textarea id="description" name="description" rows="3" placeholder="What will this group focus on?"></textarea>
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_private" value="1">
                            Make this group private (only invite code access)
                        </label>
                        <small class="field-hint">Private groups are not listed publicly. Members can only join via invite code.</small>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="create_group" class="btn btn-primary btn-large">
                            <i class="fas fa-plus"></i> Create Group
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.create-group-page { padding: 32px 0 60px; }
.page-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.page-header h1 { margin: 0; }

.card { margin-bottom: 24px; border-radius: 12px; overflow: hidden; border: 1px solid var(--border); box-shadow: var(--shadow); }
.card-header { background: var(--vanilla); padding: 14px 20px; border-bottom: 1px solid var(--border); }
.card-header h2 { font-size: 1.15rem; margin: 0; display: flex; align-items: center; gap: 8px; }
.card-body { padding: 20px; }

.create-group-form .form-group { margin-bottom: 20px; }
.create-group-form label { display: block; font-weight: 600; margin-bottom: 6px; color: var(--text); font-size: 0.95rem; }
.create-group-form select,
.create-group-form input,
.create-group-form textarea {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 1rem;
    background: var(--input-bg);
    color: var(--text);
    transition: border-color 0.3s, box-shadow 0.3s;
}
.create-group-form select:focus,
.create-group-form input:focus,
.create-group-form textarea:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}
.create-group-form textarea { resize: vertical; min-height: 80px; }
.required { color: #dc2626; }
.field-hint { display: block; margin-top: 4px; font-size: 0.85rem; color: var(--text-light); }

.create-group-form .form-actions { margin-top: 16px; }
.btn-large { padding: 12px 28px; font-size: 1.1rem; border-radius: 30px; }

@media (max-width: 480px) {
    .page-header { flex-direction: column; align-items: flex-start; }
    .btn-large { width: 100%; }
}
</style>

<?php require_once 'includes/footer.php'; ?>