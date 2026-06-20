<?php
// ============================================================
//  CREATE_GROUP.PHP – Create a new reading group
//  Supports Books, Poems, and Newsletters.
// ============================================================

require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/reading_groups.php';

redirectIfNotLoggedIn();
$user_id = $_SESSION['user_id'];

// ===== FETCH ALL CONTENT TYPES FOR DROPDOWN (static) =====
$content_types = [
    'book' => '📖 Book',
    'poem' => '✍️ Poem',
    'newsletter' => '📰 Newsletter'
];

// ===== HANDLE FORM SUBMISSION =====
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    $content_type = $_POST['content_type'] ?? '';
    $content_id = (int)$_POST['content_id'] ?? 0;
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $is_private = isset($_POST['is_private']) ? 1 : 0;

    // Validate
    if (empty($name)) {
        $error = 'Please enter a group name.';
    } elseif (!in_array($content_type, ['book', 'poem', 'newsletter'])) {
        $error = 'Please select a valid content type.';
    } elseif ($content_id <= 0) {
        $error = 'Please select a ' . $content_type . '.';
    } else {
        // Create group using the enhanced function (see reading_groups.php)
        $group_id = createReadingGroup($content_type, $content_id, $user_id, $name, $description, $is_private);
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
                <h2><i class="fas fa-users-cog"></i> New Reading Group</h2>
            </div>
            <div class="card-body">
                <form method="POST" class="create-group-form" id="createGroupForm">
                    <!-- Content Type -->
                    <div class="form-group">
                        <label for="content_type">Content Type <span class="required">*</span></label>
                        <select id="content_type" name="content_type" required>
                            <option value="">Select content type…</option>
                            <?php foreach ($content_types as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Content Select (dynamic) -->
                    <div class="form-group" id="contentSelectGroup" style="display:none;">
                        <label for="content_id">Select <span id="contentTypeLabel">Item</span> <span class="required">*</span></label>
                        <select id="content_id" name="content_id" required>
                            <option value="">Loading…</option>
                        </select>
                        <small class="field-hint" id="contentHint">Choose the book, poem, or newsletter this group will read together.</small>
                    </div>

                    <!-- Group Name -->
                    <div class="form-group">
                        <label for="name">Group Name <span class="required">*</span></label>
                        <input type="text" id="name" name="name" placeholder="e.g., The Faithful Readers" required>
                    </div>

                    <!-- Description -->
                    <div class="form-group">
                        <label for="description">Description (optional)</label>
                        <textarea id="description" name="description" rows="3" placeholder="What will this group focus on?"></textarea>
                    </div>

                    <!-- Private toggle -->
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_private" value="1">
                            Make this group private (only invite code access)
                        </label>
                        <small class="field-hint">Private groups are not listed publicly. Members can only join via invite code.</small>
                    </div>

                    <!-- Submit -->
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
/* ===== STYLES – Enhanced & Professional ===== */
.create-group-page { padding: 32px 0 60px; background: var(--bg); }
.page-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 28px; }
.page-header h1 { margin: 0; font-size: 2rem; color: var(--dark); }

.card {
    background: var(--card-bg);
    border-radius: 16px;
    overflow: hidden;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    transition: box-shadow var(--transition);
}
.card:hover { box-shadow: var(--shadow-hover); }
.card-header {
    background: var(--vanilla);
    padding: 16px 24px;
    border-bottom: 1px solid var(--border);
}
.card-header h2 { font-size: 1.2rem; margin: 0; display: flex; align-items: center; gap: 8px; color: var(--dark); }
.card-body { padding: 24px; }

.create-group-form .form-group { margin-bottom: 24px; }
.create-group-form label {
    display: block; font-weight: 600; margin-bottom: 6px; color: var(--text); font-size: 0.95rem;
}
.create-group-form select,
.create-group-form input,
.create-group-form textarea {
    width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 10px;
    font-size: 1rem; background: var(--input-bg); color: var(--text);
    transition: border-color 0.3s, box-shadow 0.3s;
}
.create-group-form select:focus,
.create-group-form input:focus,
.create-group-form textarea:focus {
    outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}
.create-group-form textarea { resize: vertical; min-height: 80px; }
.required { color: #dc2626; }
.field-hint { display: block; margin-top: 6px; font-size: 0.85rem; color: var(--text-light); }

.checkbox-label {
    display: flex; align-items: center; gap: 8px; font-weight: 500; cursor: pointer;
}
.checkbox-label input[type="checkbox"] {
    width: 18px; height: 18px; accent-color: var(--rose);
}

.form-actions { margin-top: 24px; }
.btn-large { padding: 14px 32px; font-size: 1.1rem; border-radius: 30px; width: 100%; }

/* ===== RESPONSIVE ===== */
@media (min-width: 768px) {
    .btn-large { width: auto; }
    .create-group-form .form-group { max-width: 600px; }
}
@media (max-width: 480px) {
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header h1 { font-size: 1.6rem; }
    .card-body { padding: 16px; }
}
</style>

<!-- ===== JAVASCRIPT – Dynamic Content Loading ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const contentTypeSelect = document.getElementById('content_type');
    const contentSelectGroup = document.getElementById('contentSelectGroup');
    const contentSelect = document.getElementById('content_id');
    const contentTypeLabel = document.getElementById('contentTypeLabel');
    const contentHint = document.getElementById('contentHint');

    // Map labels and hints
    const labels = {
        book: { label: 'Book', hint: 'Choose the book this group will read together.' },
        poem: { label: 'Poem', hint: 'Choose the poem this group will discuss together.' },
        newsletter: { label: 'Newsletter', hint: 'Choose the newsletter this group will explore together.' }
    };

    contentTypeSelect.addEventListener('change', function() {
        const type = this.value;
        if (!type || !labels[type]) {
            contentSelectGroup.style.display = 'none';
            contentSelect.innerHTML = '<option value="">Loading…</option>';
            return;
        }

        // Show the select group
        contentSelectGroup.style.display = 'block';
        contentTypeLabel.textContent = labels[type].label;
        contentHint.textContent = labels[type].hint;

        // Fetch items via AJAX
        fetch('ajax/get_content_items.php?type=' + encodeURIComponent(type))
            .then(response => response.json())
            .then(data => {
                contentSelect.innerHTML = '<option value="">Select a ' + labels[type].label + '…</option>';
                if (data.length === 0) {
                    contentSelect.innerHTML += '<option value="" disabled>No items found</option>';
                } else {
                    data.forEach(item => {
                        const opt = document.createElement('option');
                        opt.value = item.id;
                        opt.textContent = item.title + (item.author ? ' by ' + item.author : '');
                        contentSelect.appendChild(opt);
                    });
                }
            })
            .catch(err => {
                console.error(err);
                contentSelect.innerHTML = '<option value="">Error loading items</option>';
            });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>