<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Fetch all poems, newest first
$stmt = $db->query("SELECT * FROM poems ORDER BY created_at DESC");
$poems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Poetry';
?>
<?php require_once 'includes/header.php'; ?>

<div class="poetry-page">
    <div class="container">
        <!-- Page Header -->
        <div class="poetry-header">
            <h1>Poetry</h1>
            <p>Words that speak to the soul — discover Angella's poetic collection.</p>
        </div>

        <!-- Poem Grid -->
        <?php if (count($poems) > 0): ?>
            <div class="poems-grid">
                <?php foreach ($poems as $poem): ?>
                    <div class="poem-card">
                        <div class="poem-card-content">
                            <h3><?php echo htmlspecialchars($poem['title']); ?></h3>
                            
                            <!-- Introduction preview -->
                            <?php if ($poem['intro']): ?>
                                <div class="poem-intro-preview">
                                    <span class="intro-label">✧ Purpose</span>
                                    <p><?php echo htmlspecialchars(substr($poem['intro'], 0, 120)); ?><?php if (strlen($poem['intro']) > 120) echo '...'; ?></p>
                                </div>
                            <?php endif; ?>

                            <!-- Content preview (only if no intro) -->
                            <?php if (!$poem['intro'] && $poem['content']): ?>
                                <div class="poem-content-preview">
                                    <p><?php echo htmlspecialchars(substr($poem['content'], 0, 120)); ?><?php if (strlen($poem['content']) > 120) echo '...'; ?></p>
                                </div>
                            <?php endif; ?>

                            <!-- Audio indicator -->
                            <?php if ($poem['audio_path']): ?>
                                <div class="poem-audio-indicator">
                                    <i class="fas fa-headphones"></i>
                                    <span>Audio available</span>
                                </div>
                            <?php endif; ?>

                            <div class="poem-card-footer">
                                <span class="poem-date"><?php echo date('M j, Y', strtotime($poem['created_at'])); ?></span>
                                <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $poem['id']; ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-feather-alt"></i> Read Poem
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-pen-fancy" style="font-size: 3rem; color: var(--rose); margin-bottom: 16px;"></i>
                <h3>No Poems Yet</h3>
                <p>Check back soon for new poetry from Angella.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== STYLES ===== -->
<style>
.poetry-page {
    padding: 32px 0;
}

.poetry-header {
    text-align: center;
    margin-bottom: 32px;
}
.poetry-header h1 {
    font-size: 2.4rem;
    margin-bottom: 4px;
}
.poetry-header p {
    color: var(--text-light);
    font-size: 1.1rem;
}

.poems-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 24px;
}

.poem-card {
    background: var(--card-bg);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
    transition: transform var(--transition), box-shadow var(--transition);
    display: flex;
    flex-direction: column;
}
.poem-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
}

.poem-card-content {
    padding: 24px;
    display: flex;
    flex-direction: column;
    flex: 1;
}
.poem-card-content h3 {
    font-family: 'Playfair Display', serif;
    font-size: 1.3rem;
    margin-bottom: 8px;
    color: var(--dark);
}

.poem-intro-preview {
    background: var(--vanilla);
    padding: 12px 16px;
    border-radius: 8px;
    margin: 8px 0 12px;
    border-left: 3px solid var(--rose);
}
.poem-intro-preview .intro-label {
    display: block;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--rose);
    margin-bottom: 4px;
}
.poem-intro-preview p {
    font-style: italic;
    color: var(--text);
    font-size: 0.95rem;
    line-height: 1.5;
    margin: 0;
}

.poem-content-preview {
    margin: 8px 0 12px;
    color: var(--text-light);
    font-size: 0.95rem;
    line-height: 1.6;
}

.poem-audio-indicator {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--rose);
    font-size: 0.85rem;
    margin: 4px 0 12px;
}
.poem-audio-indicator i {
    font-size: 1.1rem;
}

.poem-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: auto;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}
.poem-date {
    font-size: 0.8rem;
    color: var(--text-light);
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-light);
}
.empty-state h3 {
    font-size: 1.4rem;
    margin-bottom: 6px;
}

@media (max-width: 480px) {
    .poems-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>