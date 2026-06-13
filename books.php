<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php'; // For isLoggedIn check

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
    $sql .= " AND is_sale = 1";
}

$sql .= " ORDER BY created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Books';
?>
<?php require_once 'includes/header.php'; ?>

<div class="books-page">
    <div class="container">
        <!-- Page Header -->
        <div class="books-header">
            <h1>All Books</h1>
            <p>Explore Angella's writings — available for reading, download, or purchase.</p>
        </div>

        <!-- Search & Filter -->
        <div class="books-tools">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search books by title, author, or description..." value="<?php echo htmlspecialchars($search); ?>">
                <select name="filter">
                    <option value="">All</option>
                    <option value="free" <?php echo $filter === 'free' ? 'selected' : ''; ?>>Free</option>
                    <option value="sale" <?php echo $filter === 'sale' ? 'selected' : ''; ?>>On Sale</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
                <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-outline btn-sm">Clear</a>
            </form>
            <div class="book-count"><?php echo count($books); ?> book<?php echo count($books) != 1 ? 's' : ''; ?></div>
        </div>

        <!-- Book Grid -->
        <?php if (count($books) > 0): ?>
            <div class="books-grid">
                <?php foreach ($books as $book): ?>
                    <div class="book-card">
                        <!-- Book Cover -->
                        <div class="book-cover-wrapper">
                            <?php if ($book['cover_path']): ?>
                                <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" loading="lazy">
                            <?php else: ?>
                                <div class="placeholder-cover">
                                    <i class="fas fa-book"></i>
                                </div>
                            <?php endif; ?>
                            <?php if ($book['is_free']): ?>
                                <span class="badge free">Free</span>
                            <?php elseif ($book['is_sale']): ?>
                                <span class="badge sale">Sale</span>
                            <?php endif; ?>
                        </div>

                        <!-- Book Details -->
                        <div class="book-details">
                            <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                            <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>

                            <!-- Description with Read More toggle -->
                            <div class="book-description-wrapper">
                                <div class="book-description" id="desc-<?php echo $book['id']; ?>">
                                    <?php echo nl2br(htmlspecialchars($book['description'] ?? 'A beautiful story waiting to be read.')); ?>
                                </div>
                                <?php if (strlen($book['description'] ?? '') > 400): ?>
                                    <button class="toggle-desc-btn" data-id="<?php echo $book['id']; ?>">Read More</button>
                                <?php endif; ?>
                            </div>

                            <!-- Bottom: Price & Actions -->
                            <!-- Bottom: Price & Actions -->
                        <div class="book-bottom">
                            <div class="book-price">
                                <?php if ($book['is_free']): ?>
                                    <span class="free-text">Free</span>
                                <?php elseif ($book['is_sale']): ?>
                                    <span class="sale-text">MWK <?php echo number_format($book['price'], 2); ?></span>
                                <?php else: ?>
                                    <span>MWK <?php echo number_format($book['price'], 2); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="book-actions">
                                <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-primary">
                                    <i class="fas fa-book-open"></i> Read
                                </a>
                                
                                <?php if (isLoggedIn()): ?>
                                    <button class="btn btn-secondary download-btn" data-id="<?php echo $book['id']; ?>" data-free="<?php echo $book['is_free'] ? '1' : '0'; ?>" data-price="<?php echo $book['price']; ?>">
                                        <i class="fas fa-download"></i> Download
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        </div> <!-- .book-card -->
                    <?php endforeach; ?>
            </div> <!-- .books-grid -->

        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-book" style="font-size: 3rem; color: var(--rose); margin-bottom: 16px;"></i>
                <h3>No Books Found</h3>
                <p><?php echo $search ? 'Try adjusting your search.' : 'Check back soon for new releases from Angella.'; ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== PAYMENT MODAL ===== -->
<div id="paymentModal" class="modal" style="display:none;">
    <div class="modal-content">
        <span class="modal-close" onclick="closePaymentModal()">&times;</span>
        <h3><i class="fas fa-lock" style="color: var(--rose);"></i> Complete Your Purchase</h3>
        <p class="modal-sub">You are about to download <strong id="modalBookTitle"></strong>. Choose your payment method below.</p>
        
        <div class="payment-options">
            <button class="payment-option" data-method="airtel" onclick="selectPayment('airtel')">
                <i class="fas fa-mobile-alt"></i>
                <span>Airtel Money</span>
            </button>
            <button class="payment-option" data-method="mpamba" onclick="selectPayment('mpamba')">
                <i class="fas fa-mobile-alt"></i>
                <span>Mpamba</span>
            </button>
            <button class="payment-option" data-method="nbm" onclick="selectPayment('nbm')">
                <i class="fas fa-university"></i>
                <span>NBM Mo626</span>
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
            <div id="paymentStatus" class="payment-status"></div>
            <div class="modal-actions">
                <button class="btn btn-primary" id="payNowBtn" onclick="processPayment()">
                    <i class="fas fa-credit-card"></i> Pay Now
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

// ===== PAYMENT MODAL FUNCTIONS =====
let currentBookId = null;
let selectedMethod = null;

function openPaymentModal(bookId, title, price) {
    currentBookId = bookId;
    document.getElementById('modalBookTitle').textContent = title;
    document.getElementById('paymentAmount').value = 'MWK ' + price.toFixed(2);
    document.getElementById('paymentForm').style.display = 'none';
    document.getElementById('paymentStatus').innerHTML = '';
    document.getElementById('paymentStatus').className = 'payment-status';
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
    statusDiv.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Processing payment...</div>';
    statusDiv.className = 'payment-status';

    // ===== SEND PAYMENT DATA TO SERVER =====
    const formData = new FormData();
    formData.append('book_id', currentBookId);
    formData.append('payment_method', selectedMethod);
    formData.append('phone_number', phone);
    formData.append('amount', document.getElementById('paymentAmount').value.replace('MWK ', ''));

    fetch('<?php echo SITE_URL; ?>/process_payment.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            statusDiv.innerHTML = '<span class="success"><i class="fas fa-check-circle"></i> Payment successful! Preparing your download...</span>';
            statusDiv.className = 'payment-status success';

            setTimeout(() => {
                window.location.href = '<?php echo SITE_URL; ?>/download.php?book_id=' + currentBookId;
                closePaymentModal();
            }, 1000);
        } else {
            statusDiv.innerHTML = '<span class="error"><i class="fas fa-times-circle"></i> ' + data.error + '</span>';
            statusDiv.className = 'payment-status error';
        }
    })
    .catch(error => {
        statusDiv.innerHTML = '<span class="error"><i class="fas fa-times-circle"></i> Payment failed. Please try again.</span>';
        statusDiv.className = 'payment-status error';
    });
}
</script>

<style>
/* ===== DOWNLOAD BUTTON ===== */
.book-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.book-actions .btn {
    padding: 10px 20px;
    border-radius: 30px;
    font-size: 0.95rem;
}
.book-actions .btn-secondary {
    background: var(--card-bg);
    color: var(--text);
    border: 1px solid var(--border);
    transition: all 0.2s ease;
}
.book-actions .btn-secondary:hover {
    border-color: var(--rose);
    color: var(--rose);
    transform: translateY(-2px);
    box-shadow: var(--shadow);
}

/* ===== MODAL ===== */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    z-index: 2000;
    display: flex;
    align-items: center;
    justify-content: center;
}
.modal-content {
    background: var(--card-bg);
    border-radius: 20px;
    padding: 32px;
    max-width: 480px;
    width: 90%;
    box-shadow: var(--shadow-hover);
    position: relative;
    animation: modalSlideIn 0.3s ease;
}
@keyframes modalSlideIn {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.modal-close {
    position: absolute;
    top: 12px;
    right: 16px;
    font-size: 1.4rem;
    cursor: pointer;
    color: var(--text-light);
    transition: color 0.2s;
}
.modal-close:hover {
    color: var(--rose);
}
.modal-content h3 {
    margin-top: 0;
    font-size: 1.4rem;
}
.modal-sub {
    color: var(--text-light);
    margin-bottom: 20px;
}

/* ===== PAYMENT OPTIONS ===== */
.payment-options {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}
.payment-option {
    flex: 1;
    min-width: 100px;
    padding: 14px;
    border: 2px solid var(--border);
    border-radius: 12px;
    background: var(--bg);
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--text);
}
.payment-option i {
    font-size: 1.4rem;
    color: var(--text-light);
}
.payment-option:hover {
    border-color: var(--rose);
    transform: translateY(-2px);
}
.payment-option.selected {
    border-color: var(--rose);
    background: rgba(219, 161, 162, 0.1);
}
.payment-option.selected i {
    color: var(--rose);
}

/* ===== PAYMENT FORM ===== */
#paymentForm .form-group {
    margin-bottom: 12px;
}
#paymentForm label {
    display: block;
    font-weight: 600;
    font-size: 0.9rem;
    margin-bottom: 4px;
}
#paymentForm input {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.95rem;
    background: var(--input-bg);
    color: var(--text);
}
#paymentForm input:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}
#paymentForm input[readonly] {
    background: var(--vanilla);
    cursor: not-allowed;
}

.payment-status {
    margin: 12px 0;
    text-align: center;
    font-weight: 500;
}
.payment-status .loading {
    color: var(--text-light);
}
.payment-status .success {
    color: #2ecc71;
}
.payment-status .error {
    color: #e74c3c;
}
.payment-status i {
    margin-right: 6px;
}

.modal-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.modal-actions .btn {
    flex: 1;
    justify-content: center;
    padding: 10px;
    border-radius: 30px;
    font-weight: 600;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 480px) {
    .payment-options {
        flex-direction: column;
    }
    .payment-option {
        flex-direction: row;
        justify-content: center;
        padding: 10px;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>