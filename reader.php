<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch book from database
$stmt = $db->prepare("SELECT * FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    header('Location: ' . SITE_URL . '/books.php');
    exit;
}

// Increment view count
$stmt = $db->prepare("UPDATE books SET view_count = view_count + 1 WHERE id = ?");
$stmt->execute([$book_id]);

// Check if this book has processed content
$stmt = $db->prepare("SELECT * FROM book_content WHERE book_id = ?");
$stmt->execute([$book_id]);
$processed_content = $stmt->fetch(PDO::FETCH_ASSOC);

// If this is Angella's book and processed content exists, use it
$has_processed = !empty($processed_content) && $processed_content['is_processed'] == 1;
$is_angella_book = $has_processed && $processed_content['is_angella_book'] == 1;

// ===== USER READING PROGRESS =====
$user_progress = null;
$user_status = null;
$position_offset = 0;
$position_section = '';
$progress_percent = 0;

if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    
    // Fetch progress
    $stmt = $db->prepare("SELECT * FROM reading_progress WHERE user_id = ? AND book_id = ?");
    $stmt->execute([$user_id, $book_id]);
    $user_progress = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user_progress) {
        $position_offset = $user_progress['position_offset'] ?? 0;
        $position_section = $user_progress['position_section'] ?? '';
        $progress_percent = $user_progress['progress_percent'] ?? 0;
    }
    
    // Fetch status
    $stmt = $db->prepare("SELECT * FROM reading_status WHERE user_id = ? AND book_id = ?");
    $stmt->execute([$user_id, $book_id]);
    $user_status = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If no progress exists, create one
    if (!$user_progress) {
        $stmt = $db->prepare("INSERT INTO reading_progress (user_id, book_id, position_offset, position_section, progress_percent) VALUES (?, ?, 0, '', 0)");
        $stmt->execute([$user_id, $book_id]);
        $user_progress = ['position_offset' => 0, 'position_section' => '', 'progress_percent' => 0];
    }
    
    // If no status exists or it's 'want to read', update to 'currently reading'
    if (!$user_status || $user_status['status'] === 'want to read') {
        $stmt = $db->prepare("INSERT OR REPLACE INTO reading_status (user_id, book_id, status) VALUES (?, ?, 'currently reading')");
        $stmt->execute([$user_id, $book_id]);
        $user_status = ['status' => 'currently reading'];
    }
}

// ===== HANDLE POSITION SAVE (AJAX) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_position'])) {
    header('Content-Type: application/json');
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Please login.']);
        exit;
    }
    
    $offset = (int)$_POST['offset'];
    $section = trim($_POST['section']);
    $percent = min(100, max(0, (int)$_POST['percent']));
    $user_id = $_SESSION['user_id'];
    
    try {
        $stmt = $db->prepare("UPDATE reading_progress SET position_offset = ?, position_section = ?, progress_percent = ?, last_accessed_at = CURRENT_TIMESTAMP WHERE user_id = ? AND book_id = ?");
        $stmt->execute([$offset, $section, $percent, $user_id, $book_id]);
        
        // If progress is 100%, mark as finished
        if ($percent >= 100) {
            // Ask user later via prompt
        }
        
        echo json_encode(['success' => true, 'message' => 'Position saved.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
    }
    exit;
}

// ===== HANDLE FINISH BOOK =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finish_book'])) {
    header('Content-Type: application/json');
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Please login.']);
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    $finish_choice = $_POST['finish_choice'] ?? 'yes';
    
    try {
        if ($finish_choice === 'yes') {
            $stmt = $db->prepare("INSERT OR REPLACE INTO reading_status (user_id, book_id, status) VALUES (?, ?, 'finished')");
            $stmt->execute([$user_id, $book_id]);
            
            $stmt = $db->prepare("UPDATE reading_progress SET finished_at = CURRENT_TIMESTAMP WHERE user_id = ? AND book_id = ?");
            $stmt->execute([$user_id, $book_id]);
            
            echo json_encode(['success' => true, 'message' => 'Book marked as finished!']);
        } else {
            echo json_encode(['success' => true, 'message' => 'Book kept as reading.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
    }
    exit;
}

// ===== HANDLE HIGHLIGHT SAVE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_highlight'])) {
    header('Content-Type: application/json');
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Please login to highlight.']);
        exit;
    }
    
    $highlight_text = trim($_POST['highlight_text']);
    $start_offset = (int)$_POST['start_offset'];
    $end_offset = (int)$_POST['end_offset'];
    $color = trim($_POST['color']) ?? '#ffeb3b';
    $user_id = $_SESSION['user_id'];
    
    if (empty($highlight_text)) {
        echo json_encode(['success' => false, 'message' => 'Highlight text cannot be empty.']);
        exit;
    }
    
    try {
        $stmt = $db->prepare("INSERT INTO book_highlights (user_id, book_id, highlight_text, start_offset, end_offset, color) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $book_id, $highlight_text, $start_offset, $end_offset, $color]);
        $highlight_id = $db->lastInsertId();
        echo json_encode(['success' => true, 'highlight_id' => $highlight_id, 'message' => 'Highlight saved!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ===== HANDLE DELETE HIGHLIGHT =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_highlight'])) {
    header('Content-Type: application/json');
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Please login.']);
        exit;
    }
    
    $highlight_id = (int)$_POST['highlight_id'];
    $user_id = $_SESSION['user_id'];
    
    try {
        $stmt = $db->prepare("DELETE FROM book_highlights WHERE id = ? AND user_id = ?");
        $stmt->execute([$highlight_id, $user_id]);
        echo json_encode(['success' => true, 'message' => 'Highlight removed.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
    }
    exit;
}

// ===== FETCH EXISTING HIGHLIGHTS =====
$highlights = [];
if (isLoggedIn()) {
    $stmt = $db->prepare("SELECT * FROM book_highlights WHERE user_id = ? AND book_id = ? ORDER BY created_at DESC");
    $stmt->execute([$_SESSION['user_id'], $book_id]);
    $highlights = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Parse TOC if processed
$toc = [];
if ($has_processed) {
    $toc = json_decode($processed_content['toc_json'], true) ?? [];
}

// Get user preferences
$reader_theme = $_COOKIE['reader_theme'] ?? 'light';
$reader_font = $_COOKIE['reader_font'] ?? 'serif';
$reader_font_size = $_COOKIE['reader_font_size'] ?? 'medium';
$reader_layout = $_COOKIE['reader_layout'] ?? 'vertical';

$pageTitle = htmlspecialchars($book['title']) . ' — Reader';
?>
<?php require_once 'includes/header.php'; ?>

<div class="reader-wrapper" data-theme="<?php echo $reader_theme; ?>" data-font="<?php echo $reader_font; ?>" data-font-size="<?php echo $reader_font_size; ?>" data-layout="<?php echo $reader_layout; ?>" data-progress="<?php echo $progress_percent; ?>">
    <div class="container">
        <!-- Book Header & Info -->
        <div class="reader-header">
            <div class="reader-header-left">
                <a href="<?php echo SITE_URL; ?>/books.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Books
                </a>
                <div class="book-title-block">
                    <h1><?php echo htmlspecialchars($book['title']); ?></h1>
                    <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>
                </div>
            </div>
            <div class="reader-header-right">
                <?php if (isLoggedIn()): ?>
                    <div class="status-controls">
                        <span class="status-display <?php echo $user_status['status'] ?? 'currently reading'; ?>">
                            <?php echo ucfirst($user_status['status'] ?? 'Currently Reading'); ?>
                        </span>
                        <span class="progress-display"><?php echo $progress_percent; ?>%</span>
                    </div>
                <?php else: ?>
                    <div class="reader-login-prompt">
                        <a href="<?php echo SITE_URL; ?>/login.php" class="btn btn-sm btn-primary">Login to track reading</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Book Cover & Description -->
        <div class="reader-info-row">
            <div class="reader-cover">
                <?php if ($book['cover_path']): ?>
                    <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                <?php else: ?>
                    <div class="reader-cover-placeholder">
                        <i class="fas fa-book"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="reader-description">
                <?php if ($book['description']): ?>
                    <p><?php echo nl2br(htmlspecialchars($book['description'])); ?></p>
                <?php else: ?>
                    <p class="text-muted">No description available.</p>
                <?php endif; ?>
                <div class="reader-meta">
                    <?php if ($book['is_free']): ?>
                        <span class="badge free">Free</span>
                    <?php elseif ($book['is_sale']): ?>
                        <span class="badge sale">$<?php echo number_format($book['price'], 2); ?></span>
                    <?php else: ?>
                        <span class="badge">$<?php echo number_format($book['price'], 2); ?></span>
                    <?php endif; ?>
                    <?php if ($has_processed): ?>
                        <span class="badge processed">Processed</span>
                    <?php endif; ?>
                </div>
                <div class="share-section">
                    <span>Share:</span>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(SITE_URL . '/reader.php?id=' . $book_id); ?>" target="_blank" class="share-btn facebook">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode('Reading ' . $book['title'] . ' by ' . $book['author']); ?>&url=<?php echo urlencode(SITE_URL . '/reader.php?id=' . $book_id); ?>" target="_blank" class="share-btn twitter">
                        <i class="fab fa-twitter"></i>
                    </a>
                    <a href="https://api.whatsapp.com/send?text=<?php echo urlencode('Check out this book: ' . SITE_URL . '/reader.php?id=' . $book_id); ?>" target="_blank" class="share-btn whatsapp">
                        <i class="fab fa-whatsapp"></i>
                    </a>
                    <a href="mailto:?subject=<?php echo urlencode('Check out this book: ' . $book['title']); ?>&body=<?php echo urlencode('I\'ve been reading this book: ' . SITE_URL . '/reader.php?id=' . $book_id); ?>" target="_blank" class="share-btn email">
                        <i class="fas fa-envelope"></i>
                    </a>
                    <button onclick="copyLink()" class="share-btn copy-link">
                        <i class="fas fa-link"></i>
                    </button>
                </div>
                <div class="download-section">
                    <?php if ($book['is_free']): ?>
                        <a href="<?php echo SITE_URL . '/' . $book['file_path']; ?>" download class="btn btn-secondary btn-sm">
                            <i class="fas fa-download"></i> Download
                        </a>
                    <?php elseif ($book['is_sale']): ?>
                        <?php if (isLoggedIn() && $user_has_paid ?? false): ?>
                            <a href="<?php echo SITE_URL . '/' . $book['file_path']; ?>" download class="btn btn-primary btn-sm">
                                <i class="fas fa-download"></i> Download
                            </a>
                        <?php else: ?>
                            <button class="btn btn-primary btn-sm" onclick="alert('This book requires payment. Please purchase first.')">
                                <i class="fas fa-lock"></i> Purchase to Download
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php if ($has_processed): ?>
                    <div class="read-button-container">
                        <a href="#reader-content" class="btn btn-primary btn-large">
                            <i class="fas fa-book-open"></i> <?php echo $progress_percent > 0 ? 'Resume Reading' : 'Start Reading'; ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reviews Section -->
        <div class="reviews-section">
            <h3><i class="fas fa-comments" style="color: var(--rose);"></i> Comments & Ratings</h3>
            <?php
            $stmt = $db->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM reviews WHERE target_type = 'book' AND target_id = ?");
            $stmt->execute([$book_id]);
            $rating_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $avg_rating = round($rating_data['avg_rating'] ?? 0, 1);
            $total_reviews = $rating_data['total'] ?? 0;
            ?>
            <div class="rating-summary">
                <div class="rating-stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fas fa-star <?php echo $i <= $avg_rating ? 'filled' : 'empty'; ?>"></i>
                    <?php endfor; ?>
                </div>
                <span class="rating-score"><?php echo number_format($avg_rating, 1); ?> / 5</span>
                <span class="rating-count">(<?php echo $total_reviews; ?> reviews)</span>
            </div>
            <?php if (isLoggedIn()): ?>
                <div class="review-form-container">
                    <h4>Write a Review</h4>
                    <form method="POST" class="review-form">
                        <input type="hidden" name="target_type" value="book">
                        <input type="hidden" name="target_id" value="<?php echo $book_id; ?>">
                        <div class="star-rating">
                            <span>Your rating:</span>
                            <div class="stars">
                                <input type="radio" name="rating" value="5" id="star5"><label for="star5"><i class="fas fa-star"></i></label>
                                <input type="radio" name="rating" value="4" id="star4"><label for="star4"><i class="fas fa-star"></i></label>
                                <input type="radio" name="rating" value="3" id="star3"><label for="star3"><i class="fas fa-star"></i></label>
                                <input type="radio" name="rating" value="2" id="star2"><label for="star2"><i class="fas fa-star"></i></label>
                                <input type="radio" name="rating" value="1" id="star1"><label for="star1"><i class="fas fa-star"></i></label>
                            </div>
                        </div>
                        <div class="form-group">
                            <textarea name="comment" rows="3" placeholder="Share your thoughts about this book..." required></textarea>
                        </div>
                        <button type="submit" name="submit_review" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Submit Review
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="login-prompt">
                    <p><a href="<?php echo SITE_URL; ?>/login.php">Login</a> to rate and review this book.</p>
                </div>
            <?php endif; ?>
            <!-- Existing Reviews -->
            <?php
            $stmt = $db->prepare("SELECT r.*, u.name AS author_name FROM reviews r JOIN users u ON r.user_id = u.id WHERE r.target_type = 'book' AND r.target_id = ? ORDER BY r.created_at DESC");
            $stmt->execute([$book_id]);
            $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <?php if (count($reviews) > 0): ?>
                <div class="reviews-list">
                    <?php foreach ($reviews as $review): ?>
                        <div class="review-item">
                            <div class="review-header">
                                <span class="review-author"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($review['author_name']); ?></span>
                                <span class="review-date"><?php echo date('M j, Y', strtotime($review['created_at'])); ?></span>
                            </div>
                            <div class="review-rating">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star <?php echo $i <= $review['rating'] ? 'filled' : 'empty'; ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <div class="review-comment"><?php echo nl2br(htmlspecialchars($review['comment'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Reader Controls -->
        <?php if ($has_processed): ?>
            <div class="reader-controls-wrapper" id="reader-content">
                <div class="reader-controls">
                    <div class="control-group">
                        <label>Theme</label>
                        <select id="themeSelect">
                            <option value="light" <?php echo $reader_theme === 'light' ? 'selected' : ''; ?>>Light</option>
                            <option value="dark" <?php echo $reader_theme === 'dark' ? 'selected' : ''; ?>>Dark</option>
                            <option value="sepia" <?php echo $reader_theme === 'sepia' ? 'selected' : ''; ?>>Sepia</option>
                            <option value="paper" <?php echo $reader_theme === 'paper' ? 'selected' : ''; ?>>Paper</option>
                        </select>
                    </div>
                    <div class="control-group">
                        <label>Font</label>
                        <select id="fontSelect">
                            <option value="serif" <?php echo $reader_font === 'serif' ? 'selected' : ''; ?>>Serif</option>
                            <option value="sans-serif" <?php echo $reader_font === 'sans-serif' ? 'selected' : ''; ?>>Sans-serif</option>
                            <option value="monospace" <?php echo $reader_font === 'monospace' ? 'selected' : ''; ?>>Monospace</option>
                        </select>
                    </div>
                    <div class="control-group">
                        <label>Font Size</label>
                        <select id="fontSizeSelect">
                            <option value="small" <?php echo $reader_font_size === 'small' ? 'selected' : ''; ?>>Small</option>
                            <option value="medium" <?php echo $reader_font_size === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="large" <?php echo $reader_font_size === 'large' ? 'selected' : ''; ?>>Large</option>
                            <option value="xlarge" <?php echo $reader_font_size === 'xlarge' ? 'selected' : ''; ?>>Extra Large</option>
                        </select>
                    </div>
                    <div class="control-group">
                        <label>Layout</label>
                        <select id="layoutSelect">
                            <option value="vertical" <?php echo $reader_layout === 'vertical' ? 'selected' : ''; ?>>Vertical</option>
                            <option value="horizontal" <?php echo $reader_layout === 'horizontal' ? 'selected' : ''; ?>>Horizontal</option>
                        </select>
                    </div>
                </div>

                <!-- Table of Contents -->
                <div class="toc-container">
                    <button class="toc-toggle">📖 Table of Contents</button>
                    <div class="toc-content" id="tocContent">
                        <?php if (count($toc) > 0): ?>
                            <ul>
                                <?php foreach ($toc as $item): ?>
                                    <li class="toc-level-<?php echo $item['level']; ?>">
                                        <a href="#<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['title']); ?></a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p>No table of contents available.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Highlights List -->
                <div class="highlights-container">
                    <button class="highlights-toggle">💡 My Highlights</button>
                    <div class="highlights-content" id="highlightsContent">
                        <?php if (count($highlights) > 0): ?>
                            <ul>
                                <?php foreach ($highlights as $highlight): ?>
                                    <li class="highlight-item" data-id="<?php echo $highlight['id']; ?>">
                                        <span style="background-color: <?php echo htmlspecialchars($highlight['color']); ?>;">
                                            <?php echo htmlspecialchars(substr($highlight['highlight_text'], 0, 60)); ?>...
                                        </span>
                                        <button class="delete-highlight" data-id="<?php echo $highlight['id']; ?>">✕</button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p>No highlights yet. Select text and click "Highlight" from the popup menu.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Content -->
                <div class="reader-content-wrapper">
                    <div class="reader-text" id="readerText" data-book-id="<?php echo $book_id; ?>" data-initial-offset="<?php echo $position_offset; ?>" data-initial-section="<?php echo $position_section; ?>">
                        <?php echo $processed_content['content_html']; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Fallback for non-processed books (PDF/EPUB) -->
            <div class="reader-content">
                <div class="reader-toolbar">
                    <span class="reader-toolbar-title">
                        <i class="fas fa-book-open"></i> Reading: <?php echo htmlspecialchars($book['title']); ?>
                    </span>
                    <div class="reader-toolbar-actions">
                        <a href="<?php echo SITE_URL . '/' . $book['file_path']; ?>" download class="btn btn-sm btn-outline">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </div>
                </div>
                <?php if ($book['file_type'] === 'pdf'): ?>
                    <div class="pdf-reader">
                        <iframe src="<?php echo SITE_URL . '/' . $book['file_path']; ?>" width="100%" height="700px" frameborder="0" allowfullscreen></iframe>
                    </div>
                <?php elseif ($book['file_type'] === 'epub'): ?>
                    <div class="epub-reader">
                        <div id="epub-viewer"></div>
                        <div class="epub-controls">
                            <button id="epub-prev" class="btn btn-sm btn-secondary"><i class="fas fa-chevron-left"></i></button>
                            <span id="epub-current">Page 1</span>
                            <button id="epub-next" class="btn btn-sm btn-secondary"><i class="fas fa-chevron-right"></i></button>
                        </div>
                    </div>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/epubjs/0.3.93/epub.min.js"></script>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            if (typeof ePub !== 'undefined') {
                                const book = ePub("<?php echo SITE_URL . '/' . $book['file_path']; ?>");
                                const viewer = document.getElementById('epub-viewer');
                                const prevBtn = document.getElementById('epub-prev');
                                const nextBtn = document.getElementById('epub-next');
                                const currentLabel = document.getElementById('epub-current');
                                let rendition = book.renderTo(viewer, { width: '100%', height: '600px', spread: 'none' });
                                rendition.display();
                                rendition.on('relocated', function(location) {
                                    currentLabel.textContent = 'Page ' + (location.start.displayedPage || 1);
                                });
                                prevBtn.addEventListener('click', function() { rendition.prev(); });
                                nextBtn.addEventListener('click', function() { rendition.next(); });
                            }
                        });
                    </script>
                <?php else: ?>
                    <div class="reader-error">
                        <p><i class="fas fa-exclamation-triangle"></i> This file type is not supported for online reading.</p>
                        <a href="<?php echo SITE_URL . '/' . $book['file_path']; ?>" download class="btn btn-primary">Download to read</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== FINISH BOOK MODAL ===== -->
<div id="finishModal" class="modal" style="display:none;">
    <div class="modal-content">
        <h3>📖 Did you finish this book?</h3>
        <p>You've reached the end of the content. Would you like to mark this book as <strong>Finished</strong>?</p>
        <div class="modal-actions">
            <button id="finishYes" class="btn btn-primary">✅ Yes, I finished it!</button>
            <button id="finishNo" class="btn btn-secondary">No, just scanning</button>
        </div>
    </div>
</div>

<!-- ===== STYLES ===== -->
<style>
    /* ===== READER THEMES ===== */
    .reader-wrapper[data-theme="light"] { --reader-bg: #ffffff; --reader-text: #1a1a1a; --reader-control-bg: #f5f5f5; }
    .reader-wrapper[data-theme="dark"] { --reader-bg: #1a1a1a; --reader-text: #f0f0f0; --reader-control-bg: #2a2a2a; }
    .reader-wrapper[data-theme="sepia"] { --reader-bg: #f4ecd8; --reader-text: #5b4636; --reader-control-bg: #e8dcc8; }
    .reader-wrapper[data-theme="paper"] { --reader-bg: #fafaf8; --reader-text: #2c2c2c; --reader-control-bg: #f0f0ed; }

    /* ===== FONT SETTINGS ===== */
    .reader-wrapper[data-font="serif"] .reader-text { font-family: 'Georgia', serif; }
    .reader-wrapper[data-font="sans-serif"] .reader-text { font-family: 'Inter', sans-serif; }
    .reader-wrapper[data-font="monospace"] .reader-text { font-family: 'Courier New', monospace; }

    .reader-wrapper[data-font-size="small"] .reader-text { font-size: 0.9rem; }
    .reader-wrapper[data-font-size="medium"] .reader-text { font-size: 1.1rem; }
    .reader-wrapper[data-font-size="large"] .reader-text { font-size: 1.4rem; }
    .reader-wrapper[data-font-size="xlarge"] .reader-text { font-size: 1.8rem; }

    .reader-wrapper[data-layout="horizontal"] .reader-content-wrapper { display: flex; flex-direction: row; gap: 20px; }
    .reader-wrapper[data-layout="vertical"] .reader-content-wrapper { display: flex; flex-direction: column; }

    /* ===== READER WRAPPER ===== */
    .reader-wrapper { background: var(--reader-bg); color: var(--reader-text); padding: 32px 0 60px; transition: all 0.3s ease; }

    /* ===== READER CONTROLS ===== */
    .reader-controls-wrapper { max-width: 800px; margin: 0 auto; }
    .reader-controls { display: flex; flex-wrap: wrap; gap: 12px; padding: 16px; background: var(--reader-control-bg); border-radius: 12px; margin-bottom: 24px; }
    .control-group { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 100px; }
    .control-group label { font-size: 0.8rem; font-weight: 600; color: var(--text-light); }
    .control-group select { padding: 6px 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); }

    /* ===== TOC ===== */
    .toc-container { margin-bottom: 20px; }
    .toc-toggle { padding: 8px 16px; background: var(--reader-control-bg); border: 1px solid var(--border); border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.2s; }
    .toc-toggle:hover { background: var(--rose); color: white; }
    .toc-content { display: none; padding: 16px; background: var(--reader-control-bg); border-radius: 8px; margin-top: 8px; }
    .toc-content.open { display: block; }
    .toc-content ul { list-style: none; padding: 0; margin: 0; }
    .toc-content li { padding: 4px 0; }
    .toc-content li a { color: var(--reader-text); text-decoration: none; transition: color 0.2s; }
    .toc-content li a:hover { color: var(--rose); }
    .toc-level-1 { padding-left: 0; font-weight: 700; }
    .toc-level-2 { padding-left: 20px; font-weight: 500; }
    .toc-level-3 { padding-left: 40px; font-weight: 400; }

    /* ===== HIGHLIGHTS ===== */
    .highlights-container { margin-bottom: 20px; }
    .highlights-toggle { padding: 8px 16px; background: var(--reader-control-bg); border: 1px solid var(--border); border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.2s; }
    .highlights-toggle:hover { background: var(--rose); color: white; }
    .highlights-content { display: none; padding: 16px; background: var(--reader-control-bg); border-radius: 8px; margin-top: 8px; }
    .highlights-content.open { display: block; }
    .highlight-item { display: flex; justify-content: space-between; align-items: center; padding: 4px 0; border-bottom: 1px solid var(--border); }
    .highlight-item:last-child { border-bottom: none; }
    .delete-highlight { background: transparent; border: none; color: #e74c3c; cursor: pointer; font-size: 0.9rem; padding: 0 4px; }
    .delete-highlight:hover { font-weight: 700; }

    /* ===== READER TEXT ===== */
    .reader-text { line-height: 1.8; margin-bottom: 32px; }
    .reader-text h1 { font-size: 2rem; margin-top: 32px; margin-bottom: 16px; color: var(--reader-text); }
    .reader-text h2 { font-size: 1.6rem; margin-top: 24px; margin-bottom: 12px; color: var(--reader-text); }
    .reader-text h3 { font-size: 1.3rem; margin-top: 20px; margin-bottom: 8px; color: var(--reader-text); }
    .reader-text p { margin-bottom: 12px; color: var(--reader-text); }
    .reader-text img { max-width: 100%; height: auto; margin: 16px 0; border-radius: 8px; }

    /* ===== HIGHLIGHT POPUP ===== */
    .highlight-popup { position: absolute; background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; box-shadow: var(--shadow-hover); display: none; z-index: 100; }
    .highlight-popup button { padding: 4px 12px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; transition: background 0.2s; }
    .highlight-popup button:hover { background: var(--rose); color: white; }

    /* ===== SHARE SECTION ===== */
    .share-section { display: flex; align-items: center; gap: 8px; margin: 12px 0; }
    .share-btn { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; color: white; font-size: 0.9rem; transition: transform 0.2s; border: none; cursor: pointer; }
    .share-btn:hover { transform: scale(1.05); }
    .share-btn.facebook { background: #1877f2; }
    .share-btn.twitter { background: #1da1f2; }
    .share-btn.whatsapp { background: #25d366; }
    .share-btn.email { background: #d44638; }
    .share-btn.copy-link { background: var(--rose-dark); color: white; }

    /* ===== FINISH MODAL ===== */
    .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 2000; }
    .modal-content { background: var(--card-bg); border-radius: 16px; padding: 32px; max-width: 480px; width: 90%; text-align: center; }
    .modal-content h3 { font-size: 1.4rem; margin-bottom: 12px; }
    .modal-actions { display: flex; gap: 12px; justify-content: center; margin-top: 20px; }
    .modal-actions .btn { padding: 10px 24px; font-size: 0.95rem; }

    /* ===== READ BUTTON ===== */
    .read-button-container { margin-top: 16px; }
    .btn-large { padding: 14px 32px; font-size: 1.1rem; }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 768px) {
        .reader-controls { flex-direction: column; }
        .control-group { min-width: auto; }
        .modal-actions { flex-direction: column; }
        .modal-actions .btn { width: 100%; }
    }
</style>

<!-- ===== READER JAVASCRIPT ===== -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // ===== THEME, FONT, SIZE, LAYOUT =====
        function setReaderPreference(type, value) {
            const wrapper = document.querySelector('.reader-wrapper');
            wrapper.setAttribute('data-' + type, value);
            document.cookie = 'reader_' + type + '=' + value + '; path=/; max-age=' + (365 * 24 * 60 * 60);
        }

        document.getElementById('themeSelect')?.addEventListener('change', function() {
            setReaderPreference('theme', this.value);
        });
        document.getElementById('fontSelect')?.addEventListener('change', function() {
            setReaderPreference('font', this.value);
        });
        document.getElementById('fontSizeSelect')?.addEventListener('change', function() {
            setReaderPreference('font-size', this.value);
        });
        document.getElementById('layoutSelect')?.addEventListener('change', function() {
            setReaderPreference('layout', this.value);
        });

        // ===== TOC =====
        const tocToggle = document.querySelector('.toc-toggle');
        const tocContent = document.getElementById('tocContent');
        if (tocToggle) {
            tocToggle.addEventListener('click', function() {
                tocContent.classList.toggle('open');
            });
        }

        // ===== HIGHLIGHTS =====
        const highlightsToggle = document.querySelector('.highlights-toggle');
        const highlightsContent = document.getElementById('highlightsContent');
        if (highlightsToggle) {
            highlightsToggle.addEventListener('click', function() {
                highlightsContent.classList.toggle('open');
            });
        }
        // Delete highlight
        document.querySelectorAll('.delete-highlight').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const id = this.dataset.id;
                if (confirm('Remove this highlight?')) {
                    const formData = new FormData();
                    formData.append('delete_highlight', '1');
                    formData.append('highlight_id', id);
                    fetch('<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book_id; ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        } else {
                            alert(data.message);
                        }
                    });
                }
            });
        });

        // ===== HIGHLIGHT POPUP =====
        const readerText = document.getElementById('readerText');
        let popup = null;
        readerText.addEventListener('mouseup', function() {
            const selection = window.getSelection();
            const text = selection.toString().trim();
            if (text.length > 0) {
                if (popup) popup.remove();
                popup = document.createElement('div');
                popup.className = 'highlight-popup';
                popup.innerHTML = `<button onclick="saveHighlight()">💡 Highlight</button>`;
                const rect = selection.getRangeAt(0).getBoundingClientRect();
                const scrollY = window.scrollY;
                const scrollX = window.scrollX;
                popup.style.position = 'absolute';
                popup.style.left = (rect.left + rect.width / 2 - 40 + scrollX) + 'px';
                popup.style.top = (rect.bottom + 10 + scrollY) + 'px';
                popup.style.display = 'block';
                document.body.appendChild(popup);
            } else {
                if (popup) popup.remove();
            }
        });

        window.saveHighlight = function() {
            const selection = window.getSelection();
            const text = selection.toString().trim();
            if (text.length === 0) return;
            const range = selection.getRangeAt(0);
            const startOffset = range.startOffset;
            const endOffset = range.endOffset;
            const color = '#ffeb3b';
            const formData = new FormData();
            formData.append('save_highlight', '1');
            formData.append('highlight_text', text);
            formData.append('start_offset', startOffset);
            formData.append('end_offset', endOffset);
            formData.append('color', color);
            fetch('<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book_id; ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message);
                }
            });
            if (popup) popup.remove();
        };

        document.addEventListener('click', function(e) {
            if (popup && !popup.contains(e.target)) {
                popup.remove();
            }
        });

        // ===== AUTO PROGRESS & POSITION SAVING =====
        let saveTimeout = null;
        const readerTextEl = document.getElementById('readerText');
        const bookId = readerTextEl?.dataset.bookId;
        const initialOffset = parseInt(readerTextEl?.dataset.initialOffset || '0');

        // Restore position on load
        if (initialOffset > 0) {
            window.scrollTo({ top: initialOffset, behavior: 'smooth' });
        }

        function savePosition() {
            const scrollTop = window.scrollY;
            const docHeight = document.documentElement.scrollHeight;
            const winHeight = window.innerHeight;
            const percent = Math.min(100, Math.round((scrollTop / (docHeight - winHeight)) * 100));
            
            // Find the current section heading (if any)
            const headings = document.querySelectorAll('.reader-text h1, .reader-text h2, .reader-text h3');
            let currentSection = '';
            for (let i = headings.length - 1; i >= 0; i--) {
                if (headings[i].offsetTop <= scrollTop + 50) {
                    currentSection = headings[i].id || '';
                    break;
                }
            }
            
            const formData = new FormData();
            formData.append('save_position', '1');
            formData.append('offset', scrollTop);
            formData.append('section', currentSection);
            formData.append('percent', percent);
            
            fetch('<?php echo SITE_URL; ?>/reader.php?id=' + bookId, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update progress display
                    const progressDisplay = document.querySelector('.progress-display');
                    if (progressDisplay) {
                        progressDisplay.textContent = percent + '%';
                    }
                    
                    // If percent >= 100, show finish modal
                    if (percent >= 100) {
                        showFinishModal();
                    }
                }
            });
        }

        function showFinishModal() {
            const modal = document.getElementById('finishModal');
            if (modal) {
                modal.style.display = 'flex';
            }
        }

        // Debounced save
        window.addEventListener('scroll', function() {
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(savePosition, 1000); // Save every 1 second after scrolling stops
        });

        // Save immediately on page unload
        window.addEventListener('beforeunload', function() {
            savePosition();
        });

        // ===== FINISH MODAL =====
        document.getElementById('finishYes')?.addEventListener('click', function() {
            const formData = new FormData();
            formData.append('finish_book', '1');
            formData.append('finish_choice', 'yes');
            fetch('<?php echo SITE_URL; ?>/reader.php?id=' + bookId, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Book marked as finished!');
                    document.getElementById('finishModal').style.display = 'none';
                    location.reload();
                } else {
                    alert(data.message);
                }
            });
        });

        document.getElementById('finishNo')?.addEventListener('click', function() {
            const formData = new FormData();
            formData.append('finish_book', '1');
            formData.append('finish_choice', 'no');
            fetch('<?php echo SITE_URL; ?>/reader.php?id=' + bookId, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('finishModal').style.display = 'none';
                }
            });
        });

        // ===== COPY LINK =====
        window.copyLink = function() {
            const url = '<?php echo SITE_URL . '/reader.php?id=' . $book_id; ?>';
            navigator.clipboard.writeText(url).then(() => {
                alert('Link copied to clipboard!');
            }).catch(() => {
                prompt('Copy this link manually:', url);
            });
        };
    });
</script>

<?php require_once 'includes/footer.php'; ?>