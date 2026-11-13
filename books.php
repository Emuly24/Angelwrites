<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/commerce.php';

// ===== SEARCH & FILTER =====
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter = isset($_GET['filter']) ? trim($_GET['filter']) : '';

// ===== FETCH BOOKS =====
$sql = "SELECT * FROM books WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (title LIKE ? OR author LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter === 'free') {
    $sql .= " AND is_free = 1";
} elseif ($filter === 'sale') {
    $sql .= " AND is_sale = 1 AND sale_price > 0 AND sale_price < price"; // F79
}

// P1: the catalogue showed every book on one page. 12 per page with the shared pager.
require_once __DIR__ . '/includes/pagination.php';
[$page, $per_page, $offset] = aw_page(12);
$cstmt = $db->prepare(preg_replace('/^SELECT \* /', 'SELECT COUNT(*) ', $sql));
$cstmt->execute($params);
$total_books = (int)$cstmt->fetchColumn();
[$page, $offset] = aw_clamp($total_books, $page, $per_page);
// Q14's ?buy=<id> must land on the page that holds that book (newest first, id breaks ties).
if (!isset($_GET['page']) && !empty($_GET['buy'])) {
    $bp = $db->prepare("SELECT created_at, id FROM books WHERE id = ?");
    $bp->execute([(int)$_GET['buy']]);
    if ($bb = $bp->fetch(PDO::FETCH_ASSOC)) {
        $pos = $db->prepare(preg_replace('/^SELECT \* /', 'SELECT COUNT(*) ', $sql) . " AND (created_at > ? OR (created_at = ? AND id > ?))");
        $pos->execute(array_merge($params, [$bb['created_at'], $bb['created_at'], $bb['id']]));
        $page = intdiv((int)$pos->fetchColumn(), $per_page) + 1;
        $offset = ($page - 1) * $per_page;
    }
}
$sql .= " ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$stmt->execute(array_merge($params, [$per_page, $offset]));
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// F86: only books with processed reader content can be read online; the others showed a Read
// button that opened an empty reader ("1 / 0").
$readable = [];
try {
    foreach ($db->query("SELECT book_id FROM book_content WHERE is_processed = 1")->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $readable[(int)$rid] = true;
    }
} catch (PDOException $e) {
    $readable = [];
}

// F34: books this member has bought download directly instead of opening the order form.
$owned = [];
if (isLoggedIn()) {
    foreach ($books as $b) {
        if (empty($b['is_free']) && aw_has_purchased($db, $_SESSION['user_id'], $b['id'])) {
            $owned[(int)$b['id']] = true;
        }
    }
}

$pageTitle = 'Books';
?>
<?php require_once 'includes/header.php'; ?>

<div class="books-page">
    <div class="container">
        <?php if (($_GET['error'] ?? '') === 'not_purchased'): // F37 ?>
            <div class="books-notice" role="alert" style="border:1px solid var(--rose, #DBA1A2);border-radius:10px;padding:12px 16px;margin:16px 0;">
                🔒 That book has to be bought before it can be downloaded. Press <strong>Download</strong> on it below to order it; it unlocks once your payment is confirmed. Already ordered? See <a href="<?php echo SITE_URL; ?>/my_purchases.php">My purchases</a>.
            </div>
        <?php endif; ?>
        <!-- ===== PAGE HEADER ===== -->
        <div class="books-header">
            <div class="books-header-content">
                <h1>All Books</h1>
                <p>Explore Angella's writings — available for reading, download, or purchase.</p>
            </div>
            <div class="books-header-stats">
                <span class="stat-badge">
                    <i class="fas fa-book"></i>
                    <strong><?php echo $total_books; ?></strong> book<?php echo $total_books != 1 ? 's' : ''; ?>
                </span>
            </div>
        </div>

        <!-- ===== SEARCH & FILTER BAR ===== -->
        <div class="books-tools">
            <form method="GET" class="search-form">
                <div class="search-input-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Search by title, author, or description..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group">
                    <select name="filter" class="filter-select">
                        <option value="">All Books</option>
                        <option value="free" <?php echo $filter === 'free' ? 'selected' : ''; ?>>Free</option>
                        <option value="sale" <?php echo $filter === 'sale' ? 'selected' : ''; ?>>On Sale</option>
                    </select>
                </div>
                <div class="action-group">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-sliders-h"></i> Filter
                    </button>
                    <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-outline btn-sm">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>
        </div>

        <!-- ===== BOOK GRID ===== -->
        <?php if (count($books) > 0): ?>
            <div class="books-grid">
                <?php foreach ($books as $book): ?>
                    <div class="book-card" id="book-<?php echo (int)$book['id']; ?>">
                        <!-- Cover Wrapper -->
                        <div class="book-cover-wrapper">
                            <?php if ($book['cover_path']): ?>
                                <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" loading="lazy">
                            <?php else: ?>
                                <div class="placeholder-cover">
                                    <i class="fas fa-book"></i>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Badges -->
                            <div class="book-badges">
                                <?php if (isset($owned[(int)$book['id']])): // Q14 ?>
                                    <span class="badge free">Owned</span>
                                <?php elseif ($book['is_free']): ?>
                                    <span class="badge free">Free</span>
                                <?php elseif (aw_book_on_sale($book)): // F79 ?>
                                    <span class="badge sale">Sale</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Book Details -->
                        <div class="book-details">
                            <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                            <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>

                            <!-- Description -->
                            <div class="book-description-wrapper">
                                <div class="book-description" id="desc-<?php echo $book['id']; ?>">
                                    <?php echo nl2br(htmlspecialchars($book['description'] ?? 'A beautiful story waiting to be read.')); ?>
                                </div>
                                <?php if (strlen($book['description'] ?? '') > 400): ?>
                                    <button class="toggle-desc-btn" data-id="<?php echo $book['id']; ?>">Read More</button>
                                <?php endif; ?>
                            </div>

                            <!-- Bottom: Price & Actions -->
                            <div class="book-bottom">
                                <div class="book-price">
                                    <?php if ($book['is_free']): ?>
                                        <span class="free-text">Free</span>
                                    <?php elseif ((float)$book['price'] <= 0): // F80: not free, no price yet ?>
                                        <span>Not yet on sale</span>
                                    <?php elseif (aw_book_on_sale($book)): ?>
                                        <span class="sale-text"><s><?php echo aw_mwk($book['price']); ?></s> <?php echo aw_mwk($book['sale_price']); ?></span><!-- F79 -->
                                    <?php else: ?>
                                        <span><?php echo aw_mwk($book['price']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="book-actions">
                                    <?php if (isset($readable[(int)$book['id']])): // F86 ?>
                                    <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-book-open"></i> Read
                                    </a>
                                    <?php else: ?>
                                    <a href="<?php echo SITE_URL; ?>/book.php?id=<?php echo (int)$book['id']; ?>" class="btn btn-outline">
                                        <i class="fas fa-info-circle"></i> Details
                                    </a>
                                    <?php endif; ?>
                                    <?php if (isLoggedIn() && ($book['is_free'] || isset($owned[(int)$book['id']]) || (float)$book['price'] > 0)): // F80, F34 ?>
                                        <button class="btn btn-secondary download-btn" data-id="<?php echo $book['id']; ?>" data-free="<?php echo ($book['is_free'] || isset($owned[(int)$book['id']])) ? '1' : '0'; ?>" data-price="<?php echo aw_book_price($book); ?>">
                                            <i class="fas fa-download"></i> Download
                                        </button>
                                    <?php elseif (!isLoggedIn() && ($book['is_free'] || (float)$book['price'] > 0)): // Q14: guests were not told ?>
                                        <a class="btn btn-secondary" href="<?php echo SITE_URL; ?>/login.php?redirect=<?php echo urlencode('/books.php#book-' . (int)$book['id']); ?>">
                                            <i class="fas fa-sign-in-alt"></i> Log in to <?php echo $book['is_free'] ? 'download' : 'buy'; ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php echo aw_pager($total_books, $page, $per_page); // P1 ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-book"></i>
                </div>
                <h3>No Books Found</h3>
                <p><?php echo $search ? 'Try adjusting your search terms.' : 'Check back soon for new releases from Angella.'; ?></p>
                <?php if ($search): ?>
                    <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-outline">Clear Search</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== PAYMENT MODAL ===== -->
<div id="paymentModal" class="modal" style="display:none;">
    <div class="modal-content">
        <button type="button" class="modal-close" onclick="closePaymentModal()" aria-label="Close">&times;</button><!-- A1: was a clickable span -->
        <div class="modal-header">
            <i class="fas fa-lock modal-lock-icon"></i>
            <h3>Order this book</h3>
            <p class="modal-sub">Order <strong id="modalBookTitle"></strong>. Choose how you will pay; you will then be shown where to send the money. The book unlocks once we confirm your payment.</p>
        </div>
        
        <div class="payment-options">
            <button class="payment-option" data-method="airtel" onclick="selectPayment('airtel')">
                <i class="fas fa-mobile-alt"></i>
                <span>Airtel Money</span>
                <small class="payment-hint">From an Airtel line</small><!-- Q21 -->
            </button>
            <button class="payment-option" data-method="mpamba" onclick="selectPayment('mpamba')">
                <i class="fas fa-mobile-alt"></i>
                <span>TNM Mpamba</span>
                <small class="payment-hint">From a TNM line</small>
            </button>
            <button class="payment-option" data-method="nbm" onclick="selectPayment('nbm')">
                <i class="fas fa-university"></i>
                <span>NBM Mo626</span>
                <small class="payment-hint">National Bank mobile banking</small>
            </button>
        </div>

        <div id="paymentForm" style="display:none;">
            <div class="form-group">
                <label for="paymentPhone">Phone Number</label>
                <input type="tel" id="paymentPhone" placeholder="e.g. 0999123456" required>
            </div>
            <div class="form-group">
                <label for="paymentAmount">Amount (MWK)</label>
                <input type="text" id="paymentAmount" readonly>
            </div>
            <div class="form-group">
                <label for="paymentReference">Transaction ID (optional, if you have already paid)</label>
                <input type="text" id="paymentReference" maxlength="60" placeholder="From your payment SMS">
            </div>
            <div id="paymentStatus" class="payment-status"></div>
            <div id="paymentInstructions" style="display:none;white-space:pre-line;border:1px solid var(--border);border-radius:8px;padding:12px;margin:10px 0;text-align:left;"></div>
            <div class="modal-actions">
                <button class="btn btn-primary" id="payNowBtn" onclick="processPayment()">
                    <i class="fas fa-paper-plane"></i> Place order
                </button>
                <button class="btn btn-secondary" onclick="closePaymentModal()">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== READ MORE TOGGLE =====
    const toggleBtns = document.querySelectorAll('.toggle-desc-btn');
    toggleBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const desc = document.getElementById('desc-' + id);
            if (desc.classList.contains('expanded')) {
                desc.classList.remove('expanded');
                this.textContent = 'Read More';
            } else {
                desc.classList.add('expanded');
                this.textContent = 'Show Less';
            }
        });
    });

    // ===== DOWNLOAD BUTTON =====
    const downloadBtns = document.querySelectorAll('.download-btn');
    downloadBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const bookId = this.dataset.id;
            const isFree = this.dataset.free === '1';
            const bookTitle = this.closest('.book-card').querySelector('h3').textContent;

            if (isFree) {
                // Free book – download directly
                window.location.href = '<?php echo SITE_URL; ?>/download.php?book_id=' + bookId;
            } else {
                // Paid book – open payment modal
                const price = parseFloat(this.dataset.price);
                openPaymentModal(bookId, bookTitle, price);
            }
        });
    });
});

// Q14: book.php's "Buy this book" links here with ?buy=<id>; open that book's order form.
document.addEventListener('DOMContentLoaded', function () {
    const m = location.search.match(/[?&]buy=(\d+)/);
    if (!m) return;
    const btn = document.querySelector('.download-btn[data-id="' + m[1] + '"]');
    if (btn) btn.click();
});

// Q21: same format as aw_mwk(): no tambala unless there are any.
function awMwk(n) { n = Math.round(Number(n) * 100) / 100; return 'MWK ' + n.toLocaleString('en-US', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 }); }

// ===== PAYMENT MODAL FUNCTIONS =====
let currentBookId = null;
let selectedMethod = null;

function openPaymentModal(bookId, title, price) {
    currentBookId = bookId;
    document.getElementById('modalBookTitle').textContent = title;
    document.getElementById('paymentAmount').value = awMwk(price); // Q21
    document.getElementById('paymentForm').style.display = 'none';
    document.getElementById('paymentStatus').innerHTML = '';
    document.getElementById('paymentStatus').className = 'payment-status';
    document.getElementById('paymentInstructions').style.display = 'none';
    document.getElementById('payNowBtn').style.display = '';
    document.querySelectorAll('.payment-option').forEach(btn => btn.classList.remove('selected'));
    document.getElementById('paymentModal').style.display = 'flex';
}

function closePaymentModal() {
    document.getElementById('paymentModal').style.display = 'none';
    currentBookId = null;
    selectedMethod = null;
}

function selectPayment(method) {
    selectedMethod = method;
    document.querySelectorAll('.payment-option').forEach(btn => {
        btn.classList.toggle('selected', btn.dataset.method === method);
    });
    document.getElementById('paymentForm').style.display = 'block';
    document.getElementById('paymentStatus').innerHTML = '';
    document.getElementById('paymentStatus').className = 'payment-status';
}

function processPayment() {
    const phone = document.getElementById('paymentPhone').value.trim();
    if (!phone || phone.length < 10) {
        document.getElementById('paymentStatus').innerHTML = '<span class="error">Please enter a valid phone number.</span>';
        return;
    }

    const statusDiv = document.getElementById('paymentStatus');
    statusDiv.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Placing your order...</div>';
    statusDiv.className = 'payment-status';

    const formData = new FormData();
    formData.append('book_id', currentBookId);
    formData.append('payment_method', selectedMethod);
    formData.append('phone_number', phone);
    formData.append('payer_reference', document.getElementById('paymentReference').value.trim());
    formData.append('amount', document.getElementById('paymentAmount').value.replace('MWK ', ''));

    fetch('<?php echo SITE_URL; ?>/process_payment.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // F75: an order is not a payment. Show where to pay and the reference to use; the book
            // unlocks when the admin confirms the money has arrived.
            statusDiv.innerHTML = '';
            const msg = document.createElement('span');
            msg.className = 'success';
            msg.textContent = data.message + ' Order reference: ' + data.reference + '.';
            statusDiv.appendChild(msg);
            statusDiv.className = 'payment-status success';
            const box = document.getElementById('paymentInstructions');
            box.textContent = 'Send ' + awMwk(data.amount) + ' and use the reference ' + data.reference + ':\n\n' + data.instructions;
            box.textContent += '\n\nYour orders and receipts are in My purchases (' + <?php echo json_encode(SITE_URL . '/my_purchases.php'); ?> + ').';
            box.style.display = 'block';
            document.getElementById('payNowBtn').style.display = 'none';
        } else {
            statusDiv.innerHTML = '<span class="error"><i class="fas fa-times-circle"></i> ' + data.error + '</span>';
            statusDiv.className = 'payment-status error';
        }
    })
    .catch(error => {
        statusDiv.innerHTML = '<span class="error"><i class="fas fa-times-circle"></i> The order could not be placed. Please try again.</span>';
        statusDiv.className = 'payment-status error';
    });
}

// ===== CLOSE MODAL ON OUTSIDE CLICK OR ESC =====
document.getElementById('paymentModal').addEventListener('click', function(e) {
    if (e.target === this) closePaymentModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('paymentModal').style.display === 'flex') {
        closePaymentModal();
    }
});
</script>

<style>
/* ===== BASE & DARK MODE ===== */
:root {
    --rose: #DBA1A2;
    --rose-dark: #c08a8b;
    --rose-light: #f5e0df;
    --vanilla: #EFD8D6;
    --dark: #1a1212;
    --text-light: #6b5a5a;
    --input-bg: #fdf9f8;
    --card-bg: #ffffff;
    --border: #ece0de;
    --shadow: 0 4px 20px rgba(0,0,0,0.06);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.10);
    --bg: #fdf9f8;
}
body.dark-mode {
    --bg: #1a1212;
    --card-bg: #2c1e1e;
    --border: #4a3a3a;
    --text-light: #b8a8a8;
    --input-bg: #3d2e2e;
    --vanilla: #2c1e1e;
    --shadow: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.5);
}
body { background: var(--bg); color: var(--text); transition: background 0.3s, color 0.3s; }

.books-page { padding: 40px 0 60px; }

/* ===== PAGE HEADER ===== */
.books-header {
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center;
    margin-bottom: 32px; gap: 16px;
}
.books-header-content h1 {
    font-size: 2.4rem; margin: 0 0 4px; font-weight: 700; color: var(--text);
}
.books-header-content p {
    font-size: 1.05rem; color: var(--text-light); margin: 0;
}
.books-header-stats .stat-badge {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--card-bg); border: 1px solid var(--border); border-radius: 30px;
    padding: 8px 20px; font-size: 0.95rem; color: var(--text); box-shadow: var(--shadow);
}
.books-header-stats .stat-badge i { color: var(--rose); }
.books-header-stats .stat-badge strong { font-weight: 700; }

/* ===== SEARCH & FILTER ===== */
.books-tools { margin-bottom: 32px; }
.search-form {
    display: flex; flex-wrap: wrap; gap: 12px; align-items: center;
    background: var(--card-bg); padding: 12px 16px; border-radius: 12px;
    border: 1px solid var(--border); box-shadow: var(--shadow);
}
.search-input-group {
    flex: 1; min-width: 200px; position: relative;
}
.search-input-group i {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-light);
}
.search-input-group input {
    width: 100%; padding: 10px 14px 10px 40px; border: 1px solid var(--border);
    border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text);
    transition: border-color 0.2s ease;
}
.search-input-group input:focus {
    outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}
.filter-group .filter-select {
    padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px;
    font-size: 0.9rem; background: var(--input-bg); color: var(--text); cursor: pointer;
}
.filter-group .filter-select:focus { outline: none; border-color: var(--rose); }
.action-group { display: flex; gap: 8px; }
.action-group .btn-sm { padding: 10px 20px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; }

/* ===== BOOK GRID ===== */
.books-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 28px; margin: 0 auto;
}

/* ===== BOOK CARD ===== */
.book-card {
    background: var(--card-bg); border-radius: 16px; overflow: hidden;
    border: 1px solid var(--border); box-shadow: var(--shadow);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex; flex-direction: column; height: 100%;
}
.book-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-hover); }

/* ===== COVER ===== */
.book-cover-wrapper {
    position: relative; height: 280px; background: var(--vanilla);
    display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.book-cover-wrapper img {
    width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s ease;
}
.book-card:hover .book-cover-wrapper img { transform: scale(1.03); }
.placeholder-cover { font-size: 4rem; color: var(--rose); opacity: 0.5; }
.book-badges {
    position: absolute; top: 12px; right: 12px; display: flex; flex-direction: column; gap: 6px;
}
.badge {
    padding: 4px 14px; border-radius: 20px; font-size: 0.7rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px; color: white; box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.badge.free { background: #4f8a63; }
.badge.sale { background: #b4505a; }

/* ===== BOOK DETAILS ===== */
.book-details { padding: 20px 24px 24px; flex: 1; display: flex; flex-direction: column; }
.book-details h3 { font-size: 1.2rem; margin: 0 0 4px; font-weight: 600; color: var(--text); line-height: 1.3; }
.book-author { font-size: 0.9rem; color: var(--text-light); margin-bottom: 10px; }

/* ===== DESCRIPTION ===== */
.book-description-wrapper { flex: 1; }
.book-description { font-size: 0.9rem; line-height: 1.6; color: var(--text); max-height: 80px; overflow: hidden; transition: max-height 0.4s ease; }
.book-description.expanded { max-height: none; }
.toggle-desc-btn { background: none; border: none; color: var(--rose); font-size: 0.8rem; font-weight: 600; cursor: pointer; padding: 0; margin-bottom: 8px; }
.toggle-desc-btn:hover { color: var(--rose-dark); text-decoration: underline; }

/* ===== BOTTOM ===== */
.book-bottom {
    display: flex; justify-content: space-between; align-items: center;
    margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border);
}
.book-price { font-weight: 700; font-size: 1rem; color: var(--text); }
.free-text { color: #4f8a63; }
.sale-text { color: #b4505a; }
.book-actions { display: flex; gap: 8px; }
.book-actions .btn { padding: 6px 16px; border-radius: 30px; font-size: 0.8rem; font-weight: 600; transition: all 0.2s ease; }
.book-actions .btn-primary { background: var(--rose); color: white; border: none; }
.book-actions .btn-primary:hover { background: var(--rose-dark); transform: translateY(-2px); }
.book-actions .btn-secondary { background: var(--card-bg); color: var(--text); border: 1px solid var(--border); }
.book-actions .btn-secondary:hover { border-color: var(--rose); color: var(--rose); transform: translateY(-2px); box-shadow: var(--shadow); }

/* ===== EMPTY STATE ===== */
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-light); }
.empty-state-icon { font-size: 4rem; color: var(--rose); opacity: 0.4; margin-bottom: 16px; }
.empty-state h3 { font-size: 1.4rem; margin-bottom: 8px; color: var(--text); }
.empty-state p { margin-bottom: 20px; }
.empty-state .btn { border-radius: 30px; }

/* ===== MODAL ===== */
.modal {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 2000;
    display: flex; align-items: center; justify-content: center;
}
.modal-content {
    background: var(--card-bg); border-radius: 20px; padding: 32px; max-width: 460px; width: 90%;
    box-shadow: var(--shadow-hover); position: relative; animation: modalSlideIn 0.3s ease;
}
@keyframes modalSlideIn {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.modal-close {
    position: absolute; top: 12px; right: 16px; font-size: 1.4rem; cursor: pointer;
    color: var(--text-light); transition: color 0.2s; background: none; border: none;
}
.modal-close:hover { color: var(--rose); }
.modal-header { text-align: center; margin-bottom: 20px; }
.modal-lock-icon { font-size: 2.4rem; color: var(--rose); margin-bottom: 8px; }
.modal-content h3 { margin: 0 0 4px; font-size: 1.4rem; }
.modal-sub { color: var(--text-light); margin: 0; font-size: 0.95rem; }

/* ===== PAYMENT OPTIONS ===== */
.payment-options { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.payment-option {
    flex: 1; min-width: 100px; padding: 14px; border: 2px solid var(--border);
    border-radius: 12px; background: var(--bg); cursor: pointer; transition: all 0.2s ease;
    display: flex; flex-direction: column; align-items: center; gap: 4px;
    font-size: 0.85rem; font-weight: 500; color: var(--text);
}
.payment-option i { font-size: 1.4rem; color: var(--text-light); }
.payment-option:hover { border-color: var(--rose); transform: translateY(-2px); }
.payment-option.selected { border-color: var(--rose); background: rgba(219,161,162,0.1); }
.payment-option.selected i { color: var(--rose); }

/* ===== PAYMENT FORM ===== */
#paymentForm .form-group { margin-bottom: 12px; }
#paymentForm label { display: block; font-weight: 600; font-size: 0.9rem; margin-bottom: 4px; color: var(--text); }
#paymentForm input {
    width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px;
    font-size: 0.95rem; background: var(--input-bg); color: var(--text); transition: border-color 0.2s ease;
}
#paymentForm input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
#paymentForm input[readonly] { background: var(--vanilla); cursor: not-allowed; }
.payment-status { margin: 12px 0; text-align: center; font-weight: 500; font-size: 0.95rem; }
.payment-status .loading { color: var(--text-light); }
.payment-status .success { color: #4f8a63; }
.payment-status .error { color: #b4505a; }
.payment-status i { margin-right: 6px; }

/* ===== MODAL ACTIONS ===== */
.modal-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.modal-actions .btn { flex: 1; justify-content: center; padding: 10px; border-radius: 30px; font-weight: 600; min-width: 100px; }

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .books-header { flex-direction: column; align-items: flex-start; }
    .books-grid { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px; }
    .search-form { flex-direction: column; align-items: stretch; }
    .search-input-group { min-width: auto; }
    .filter-group .filter-select { width: 100%; }
    .action-group { justify-content: stretch; }
    .action-group .btn { flex: 1; justify-content: center; }
    .book-cover-wrapper { height: 220px; }
    .payment-options { flex-direction: column; }
    .payment-option { flex-direction: row; justify-content: center; padding: 10px; }
}
@media (max-width: 480px) {
    .books-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
    .book-cover-wrapper { height: 180px; }
    .book-details { padding: 14px 16px 16px; }
    .book-details h3 { font-size: 1rem; }
    .book-actions .btn { padding: 4px 12px; font-size: 0.7rem; }
}

/* Q21 */
.payment-option .payment-hint { display:block; font-size:0.75rem; color:var(--text-light); font-weight:400; }
</style>

<?php require_once 'includes/footer.php'; ?>