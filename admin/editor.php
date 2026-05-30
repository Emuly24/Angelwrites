<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Only admin can access
redirectIfNotAdmin();

$error = '';
$success = '';

// Determine editing mode
$type = isset($_GET['type']) ? $_GET['type'] : 'poem'; // poem, blog, reflection, research
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$existing_content = null;
$title = '';
$intro = '';
$category = '';
$tags = '';

if ($id > 0) {
    switch ($type) {
        case 'poem':
            $stmt = $db->prepare("SELECT * FROM poems WHERE id = ?");
            $stmt->execute([$id]);
            $existing_content = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing_content) {
                $title = $existing_content['title'];
                $intro = $existing_content['intro'];
                $content = $existing_content['content'];
                $category = 'Poetry';
            }
            break;
        case 'blog':
            $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ?");
            $stmt->execute([$id]);
            $existing_content = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing_content) {
                $title = $existing_content['title'];
                $intro = $existing_content['excerpt'];
                $content = $existing_content['content'];
                $category = $existing_content['category'] ?? 'Christian Reflections';
                $tags = $existing_content['tags'] ?? '';
            }
            break;
        case 'reflection':
            // Reflections stored in blog_posts with category = 'Christian Reflections'
            $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ?");
            $stmt->execute([$id]);
            $existing_content = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing_content) {
                $title = $existing_content['title'];
                $intro = $existing_content['excerpt'];
                $content = $existing_content['content'];
                $category = 'Christian Reflections';
                $tags = $existing_content['tags'] ?? '';
            }
            break;
        case 'research':
            // Research stored in blog_posts with category = 'Research & Academic'
            $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ?");
            $stmt->execute([$id]);
            $existing_content = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing_content) {
                $title = $existing_content['title'];
                $intro = $existing_content['excerpt'];
                $content = $existing_content['content'];
                $category = 'Research & Academic';
                $tags = $existing_content['tags'] ?? '';
            }
            break;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $intro = trim($_POST['intro']);
    $content = trim($_POST['content']);
    $category = trim($_POST['category']);
    $tags = trim($_POST['tags']);
    $action = $_POST['action'] ?? 'save';

    if (empty($title)) {
        $error = 'Title is required.';
    } elseif (empty($content)) {
        $error = 'Content is required.';
    } else {
        // Generate slug from title
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));

        if ($id > 0) {
            // Update existing
            switch ($type) {
                case 'poem':
                    $stmt = $db->prepare("UPDATE poems SET title = ?, intro = ?, content = ? WHERE id = ?");
                    $stmt->execute([$title, $intro, $content, $id]);
                    break;
                case 'blog':
                case 'reflection':
                case 'research':
                    $stmt = $db->prepare("UPDATE blog_posts SET title = ?, slug = ?, excerpt = ?, content = ?, category = ?, tags = ? WHERE id = ?");
                    $stmt->execute([$title, $slug, $intro, $content, $category, $tags, $id]);
                    break;
            }
            $success = 'Content updated successfully!';
        } else {
            // Insert new
            switch ($type) {
                case 'poem':
                    $stmt = $db->prepare("INSERT INTO poems (title, intro, content) VALUES (?, ?, ?)");
                    $stmt->execute([$title, $intro, $content]);
                    $id = $db->lastInsertId();
                    break;
                case 'blog':
                case 'reflection':
                case 'research':
                    $stmt = $db->prepare("INSERT INTO blog_posts (title, slug, excerpt, content, category, tags, status) VALUES (?, ?, ?, ?, ?, ?, 'published')");
                    $stmt->execute([$title, $slug, $intro, $content, $category, $tags]);
                    $id = $db->lastInsertId();
                    break;
            }
            $success = 'Content created successfully!';
        }
    }
}

$pageTitle = ucfirst($type) . ' Editor';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-editor">
    <div class="container">
        <div class="admin-header">
            <h1><?php echo ucfirst($type); ?> Editor</h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/manage_<?php echo $type; ?>s.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" id="editorForm" class="admin-form">
            <input type="hidden" name="action" id="formAction" value="save">

            <div class="card">
                <div class="card-body">
                    <div class="form-group">
                        <label for="title">Title <span class="required">*</span></label>
                        <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($title); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="intro">Introduction / Purpose (optional)</label>
                        <textarea id="intro" name="intro" rows="3"><?php echo htmlspecialchars($intro); ?></textarea>
                        <small class="field-hint">A short description or purpose statement. For poems, this appears before the poem content.</small>
                    </div>

                    <div class="form-group">
                        <label for="category">Category</label>
                        <select id="category" name="category">
                            <?php if ($type === 'poem'): ?>
                                <option value="Poetry">Poetry</option>
                            <?php elseif ($type === 'blog'): ?>
                                <option value="General" <?php echo $category === 'General' ? 'selected' : ''; ?>>General</option>
                                <option value="Christian Reflections" <?php echo $category === 'Christian Reflections' ? 'selected' : ''; ?>>Christian Reflections</option>
                                <option value="Research & Academic" <?php echo $category === 'Research & Academic' ? 'selected' : ''; ?>>Research & Academic</option>
                            <?php elseif ($type === 'reflection'): ?>
                                <option value="Christian Reflections" selected>Christian Reflections</option>
                            <?php elseif ($type === 'research'): ?>
                                <option value="Research & Academic" selected>Research & Academic</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="tags">Tags (comma separated)</label>
                        <input type="text" id="tags" name="tags" value="<?php echo htmlspecialchars($tags); ?>" placeholder="faith, hope, prayer, healing">
                    </div>
                </div>
            </div>

            <!-- ===== EDITOR TOOLBAR ===== -->
            <div class="editor-toolbar-wrapper">
                <div class="editor-toolbar">
                    <button type="button" class="toolbar-btn" onclick="insertBibleVerse()">
                        <i class="fas fa-book-bible"></i> Bible
                    </button>
                    <button type="button" class="toolbar-btn" onclick="openGoogleScholar()">
                        <i class="fas fa-graduation-cap"></i> Google Scholar
                    </button>
                    <button type="button" class="toolbar-btn" onclick="insertCitation()">
                        <i class="fas fa-quote-right"></i> Citation
                    </button>
                    <button type="button" class="toolbar-btn" onclick="toggleAdvancedTools()">
                        <i class="fas fa-tools"></i> Advanced
                    </button>
                </div>
            </div>

            <!-- ===== ADVANCED TOOLS ===== -->
            <div id="advancedTools" class="advanced-tools hidden">
                <div class="tool-group">
                    <span class="tool-label">Insert Special:</span>
                    <button type="button" onclick="insertSymbol('°')">°</button>
                    <button type="button" onclick="insertSymbol('·')">·</button>
                    <button type="button" onclick="insertSymbol('•')">•</button>
                    <button type="button" onclick="insertSymbol('✓')">✓</button>
                    <button type="button" onclick="insertSymbol('✗')">✗</button>
                    <button type="button" onclick="insertSymbol('★')">★</button>
                    <button type="button" onclick="insertSymbol('†')">†</button>
                    <button type="button" onclick="insertSymbol('‡')">‡</button>
                </div>
                <div class="tool-group">
                    <span class="tool-label">LaTeX Equation:</span>
                    <button type="button" onclick="insertLatex()">∫ Equation</button>
                </div>
                <div class="tool-group">
                    <span class="tool-label">Media:</span>
                    <button type="button" onclick="insertMedia()">🎬 Video/Media</button>
                    <button type="button" onclick="uploadMedia()">📤 Upload</button>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <div class="form-group">
                        <label for="content">Content <span class="required">*</span></label>
                        <textarea id="editor" name="content" rows="20"><?php echo htmlspecialchars($content ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('formAction').value='publish'; document.getElementById('editorForm').submit();">
                    <i class="fas fa-check-circle"></i> Save & Publish
                </button>
                <button type="button" class="btn btn-outline" onclick="window.location.href='<?php echo SITE_URL; ?>/admin/manage_<?php echo $type; ?>s.php'">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ===== SCRIPTS ===== -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js"></script>
<script>
    // ===== TINYMCE EDITOR =====
    tinymce.init({
        selector: '#editor',
        height: 600,
        menubar: 'file edit insert view format table tools',
        plugins: 'anchor autolink charmap codesample emoticons image imagetools link lists media searchreplace table visualblocks wordcount code',
        toolbar: 'undo redo | styleselect | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat | charmap | code | editimage',
        toolbar_sticky: true,
        content_style: 'body { font-family: Inter, sans-serif; font-size: 16px; line-height: 1.8; }',
        forced_root_block: 'p',
        relative_urls: false,
        images_upload_url: '<?php echo SITE_URL; ?>/admin/upload_image.php',
        automatic_uploads: true,
        image_advtab: true,
        image_dimensions: true,
        image_caption: true,
        init_instance_callback: function(editor) {
            // Load existing content
            const existingContent = <?php echo json_encode($content ?? ''); ?>;
            if (existingContent) {
                editor.setContent(existingContent);
            }
            // Auto-save every 30 seconds
            setInterval(function() {
                if (editor.isDirty()) {
                    document.querySelector('form').submit();
                }
            }, 30000);
        },
        setup: function(editor) {
            editor.addShortcut('Ctrl+S', 'Save', function() {
                document.querySelector('form').submit();
            });
        }
    });

    // ===== BIBLE VERSE INSERT =====
    function insertBibleVerse() {
        // Trigger the existing Bible modal
        document.getElementById('bibleToggle').click();
        // After user selects a verse, we'll insert it
        document.addEventListener('bibleVerseSelected', function(e) {
            if (tinymce.activeEditor) {
                tinymce.activeEditor.insertContent(e.detail.verse);
            }
        });
    }

    // ===== GOOGLE SCHOLAR =====
    function openGoogleScholar() {
        const scholarUrl = 'https://scholar.google.com/';
        const citation = prompt('Enter a search term or DOI to search Google Scholar:', '');
        if (citation) {
            window.open(scholarUrl + '?q=' + encodeURIComponent(citation), '_blank');
        }
    }

    // ===== CITATION =====
    function insertCitation() {
        const author = prompt('Author (Last, First):');
        const year = prompt('Year:');
        const title = prompt('Title:');
        const source = prompt('Source:');
        if (author && year && title && source) {
            const citation = `${author} (${year}). ${title}. ${source}.`;
            if (tinymce.activeEditor) {
                tinymce.activeEditor.insertContent(`<p><strong>Citation:</strong> ${citation}</p>`);
            }
        }
    }

    // ===== ADVANCED TOOLS TOGGLE =====
    function toggleAdvancedTools() {
        const tools = document.getElementById('advancedTools');
        tools.classList.toggle('hidden');
    }

    // ===== INSERT SYMBOL =====
    function insertSymbol(symbol) {
        if (tinymce.activeEditor) {
            tinymce.activeEditor.insertContent(symbol);
        }
    }

    // ===== INSERT LATEX =====
    function insertLatex() {
        const latex = prompt('Enter LaTeX equation:');
        if (latex) {
            if (tinymce.activeEditor) {
                tinymce.activeEditor.insertContent(`$$ ${latex} $$`);
            }
        }
    }

    // ===== INSERT MEDIA =====
    function insertMedia() {
        const url = prompt('Enter video or media URL:');
        if (url) {
            const embedHtml = `<iframe src="${url}" width="560" height="315" frameborder="0" allowfullscreen></iframe>`;
            if (tinymce.activeEditor) {
                tinymce.activeEditor.insertContent(embedHtml);
            }
        }
    }

    // ===== UPLOAD MEDIA =====
    function uploadMedia() {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'audio/*,video/*,image/*';
        input.onchange = function(e) {
            const file = e.target.files[0];
            if (!file || !tinymce.activeEditor) return;
            const formData = new FormData();
            formData.append('file', file);
            fetch('<?php echo SITE_URL; ?>/admin/upload_media.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.url) {
                    if (file.type.startsWith('audio/')) {
                        tinymce.activeEditor.insertContent(`<audio controls src="${data.url}"></audio>`);
                    } else if (file.type.startsWith('video/')) {
                        tinymce.activeEditor.insertContent(`<video controls src="${data.url}"></video>`);
                    } else if (file.type.startsWith('image/')) {
                        tinymce.activeEditor.insertContent(`<img src="${data.url}" alt="Uploaded image">`);
                    }
                }
            });
        };
        input.click();
    }
</script>

<style>
    .admin-editor { padding: 32px 0 60px; }
    .editor-toolbar-wrapper { margin: 16px 0; }
    .editor-toolbar { display: flex; gap: 8px; flex-wrap: wrap; padding: 12px; background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; box-shadow: var(--shadow); }
    .toolbar-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: var(--vanilla); border: 1px solid var(--border); border-radius: 20px; cursor: pointer; font-weight: 600; color: var(--text); transition: all 0.3s; }
    .toolbar-btn:hover { background: var(--rose); color: white; border-color: var(--rose); transform: translateY(-2px); }
    .toolbar-btn i { font-size: 1rem; }

    .advanced-tools { background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 12px; margin: 12px 0; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; transition: all 0.3s; }
    .advanced-tools.hidden { display: none; }
    .tool-group { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
    .tool-label { font-weight: 600; font-size: 0.85rem; color: var(--text-light); margin-right: 4px; }
    .tool-group button { min-width: 32px; height: 32px; padding: 0 8px; background: var(--vanilla); border: 1px solid var(--border); border-radius: 4px; cursor: pointer; transition: all 0.2s; }
    .tool-group button:hover { background: var(--rose); color: white; border-color: var(--rose); }

    .admin-form .form-group { margin-bottom: 16px; }
    .admin-form label { display: block; font-weight: 600; margin-bottom: 4px; color: var(--text); }
    .admin-form input[type="text"], .admin-form textarea, .admin-form select { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 1rem; background: var(--input-bg); color: var(--text); transition: border-color 0.3s; }
    .admin-form input:focus, .admin-form textarea:focus, .admin-form select:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
    .admin-form textarea { resize: vertical; min-height: 60px; }
    .admin-form .field-hint { font-size: 0.85rem; color: var(--text-light); margin-top: 4px; display: block; }

    .form-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border); }
    .form-actions .btn { min-width: 140px; justify-content: center; }
    .form-actions .btn-primary { background: var(--rose); color: white; }
    .form-actions .btn-primary:hover { background: var(--rose-dark); }
    .form-actions .btn-secondary { background: var(--dark); color: white; }
    .form-actions .btn-secondary:hover { background: #1a1410; }
    .form-actions .btn-outline { border: 1px solid var(--border); }
    .form-actions .btn-outline:hover { background: var(--vanilla); }

    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
    .alert-error { background: #fee2e2; color: #dc2626; }
    .alert-success { background: #d1fae5; color: #059669; }

    .required { color: #dc2626; }

    @media (max-width: 768px) {
        .editor-toolbar { flex-direction: column; }
        .toolbar-btn { width: 100%; justify-content: center; }
        .advanced-tools { flex-direction: column; }
        .tool-group { justify-content: center; }
    }
</style>

<?php require_once '../includes/footer.php'; ?>