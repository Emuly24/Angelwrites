<?php
// ============================================================
//  READER_ANALYTICS.PHP – Admin Dashboard for Reader Analytics
//  Shows reading time, completion rates, drop-off points,
//  active readers, and most active readers.
// ============================================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

redirectIfNotAdmin();

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// ===== FETCH STATISTICS =====

// Total reading hours
$stmt = $db->query("SELECT SUM(duration_seconds) as total_seconds FROM reading_sessions");
$total_seconds = $stmt->fetchColumn() ?? 0;
$total_hours = floor($total_seconds / 3600);
$total_minutes = floor(($total_seconds % 3600) / 60);

// Active readers
$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-7 days')");
$active_7days = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-30 days')");
$active_30days = $stmt->fetchColumn();

// Total sessions
$stmt = $db->query("SELECT COUNT(*) FROM reading_sessions");
$total_sessions = $stmt->fetchColumn();

// Total distinct readers
$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions");
$total_readers = $stmt->fetchColumn();

// Total books with reading progress
$stmt = $db->query("SELECT COUNT(DISTINCT book_id) FROM reading_progress");
$total_books_reading = $stmt->fetchColumn();

// ===== FETCH BOOK COMPLETION RATES =====
$stmt = $db->prepare("
    SELECT b.id, b.title, b.author,
           COUNT(DISTINCT rp.user_id) as readers,
           SUM(CASE WHEN rp.progress_percent >= 100 THEN 1 ELSE 0 END) as completions,
           ROUND(100.0 * SUM(CASE WHEN rp.progress_percent >= 100 THEN 1 ELSE 0 END) / COUNT(DISTINCT rp.user_id), 1) as completion_rate
    FROM reading_progress rp
    JOIN books b ON rp.book_id = b.id
    GROUP BY rp.book_id
    ORDER BY completion_rate DESC
    LIMIT 20
");
$stmt->execute();
$completion_rates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH DROP-OFF POINTS =====
$stmt = $db->prepare("
    SELECT rp.position_section as chapter, COUNT(*) as drop_offs
    FROM reading_progress rp
    WHERE rp.progress_percent < 100
    GROUP BY rp.position_section
    ORDER BY drop_offs DESC
    LIMIT 20
");
$stmt->execute();
$drop_offs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH MOST ACTIVE READERS =====
$stmt = $db->prepare("
    SELECT u.id, u.name, u.email, COUNT(rs.id) as sessions, SUM(rs.duration_seconds) as total_time
    FROM reading_sessions rs
    JOIN users u ON rs.user_id = u.id
    GROUP BY rs.user_id
    ORDER BY total_time DESC
    LIMIT 20
");
$stmt->execute();
$active_readers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH RECENT READING ACTIVITY =====
$stmt = $db->prepare("
    SELECT u.name as user_name, b.title as book_title, rp.progress_percent, rp.last_accessed_at
    FROM reading_progress rp
    JOIN users u ON rp.user_id = u.id
    JOIN books b ON rp.book_id = b.id
    WHERE rp.progress_percent > 0
    ORDER BY rp.last_accessed_at DESC
    LIMIT 50
");
$stmt->execute();
$recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH ACHIEVEMENTS LEADERBOARD =====
$stmt = $db->prepare("
    SELECT u.name, a.achievement_type, a.unlocked_at
    FROM achievements a
    JOIN users u ON a.user_id = u.id
    ORDER BY a.unlocked_at DESC
    LIMIT 20
");
$stmt->execute();
$achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Reader Analytics';
?>
<?php require_once __DIR__ . '/../../includes/header.php'; ?>

<div class="analytics-page">
    <div class="container">
        <div class="page-header">
            <h1>📊 Reader Analytics</h1>
            <div class="header-actions">
                <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()">
                    <i class="fas fa-moon"></i>
                </button>
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- ===== STATS ROW ===== -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-number"><?php echo $total_hours; ?>h <?php echo $total_minutes; ?>m</div>
                <div class="stat-label">Total Reading Time</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-number"><?php echo $total_readers; ?></div>
                <div class="stat-label">Distinct Readers</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-book"></i></div>
                <div class="stat-number"><?php echo $total_books_reading; ?></div>
                <div class="stat-label">Books Being Read</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-session"></i></div>
                <div class="stat-number"><?php echo $total_sessions; ?></div>
                <div class="stat-label">Total Reading Sessions</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-number"><?php echo $active_7days; ?></div>
                <div class="stat-label">Active (7 days)</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-number"><?php echo $active_30days; ?></div>
                <div class="stat-label">Active (30 days)</div>
            </div>
        </div>

        <!-- ===== COMPLETION RATES ===== -->
        <div class="card">
            <div class="card-header">
                <h2>📚 Book Completion Rates</h2>
            </div>
            <div class="card-body">
                <?php if (count($completion_rates) > 0): ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Book</th>
                                    <th>Readers</th>
                                    <th>Completions</th>
                                    <th>Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($completion_rates as $book): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($book['title']); ?></strong>
                                            <br><small>by <?php echo htmlspecialchars($book['author']); ?></small>
                                        </td>
                                        <td><?php echo $book['readers']; ?></td>
                                        <td><?php echo $book['completions']; ?></td>
                                        <td>
                                            <span class="badge <?php echo $book['completion_rate'] >= 50 ? 'badge-success' : 'badge-warning'; ?>">
                                                <?php echo $book['completion_rate']; ?>%
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-items">No completion data available yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== DROP-OFF POINTS ===== -->
        <div class="card">
            <div class="card-header">
                <h2>📉 Drop-off Points</h2>
            </div>
            <div class="card-body">
                <?php if (count($drop_offs) > 0): ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Chapter</th>
                                    <th>Drop-offs</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($drop_offs as $drop): ?>
                                    <tr>
                                        <td>Chapter <?php echo $drop['chapter'] + 1; ?></td>
                                        <td><?php echo $drop['drop_offs']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-items">No drop-off data available yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== MOST ACTIVE READERS ===== -->
        <div class="card">
            <div class="card-header">
                <h2>🔥 Most Active Readers</h2>
            </div>
            <div class="card-body">
                <?php if (count($active_readers) > 0): ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Sessions</th>
                                    <th>Total Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($active_readers as $reader): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($reader['name']); ?></td>
                                        <td><?php echo htmlspecialchars($reader['email']); ?></td>
                                        <td><?php echo $reader['sessions']; ?></td>
                                        <td><?php echo formatDuration($reader['total_time']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-items">No active readers data available yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== RECENT READING ACTIVITY ===== -->
        <div class="card">
            <div class="card-header">
                <h2>📖 Recent Reading Activity</h2>
            </div>
            <div class="card-body">
                <?php if (count($recent_activity) > 0): ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Book</th>
                                    <th>Progress</th>
                                    <th>Last Accessed</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_activity as $activity): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($activity['user_name']); ?></td>
                                        <td><?php echo htmlspecialchars($activity['book_title']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $activity['progress_percent'] >= 100 ? 'badge-success' : 'badge-primary'; ?>">
                                                <?php echo $activity['progress_percent']; ?>%
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y, g:i a', strtotime($activity['last_accessed_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-items">No recent reading activity.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== ACHIEVEMENTS LEADERBOARD ===== -->
        <div class="card">
            <div class="card-header">
                <h2>🏆 Achievements Leaderboard</h2>
            </div>
            <div class="card-body">
                <?php if (count($achievements) > 0): ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Achievement</th>
                                    <th>Unlocked</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($achievements as $achievement): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($achievement['name']); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $achievement['achievement_type'])); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($achievement['unlocked_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-items">No achievements unlocked yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== THEME TOGGLE =====
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('analyticsTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('analyticsTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };
});
</script>

<style>
.analytics-page { padding: 32px 0 60px; }
.page-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.page-header h1 { font-size: 2rem; margin: 0; }
.header-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 24px; }
.stat-card { background: var(--card-bg); border-radius: 12px; padding: 16px; text-align: center; border: 1px solid var(--border); box-shadow: var(--shadow); }
.stat-icon { font-size: 2rem; color: var(--rose); margin-bottom: 4px; }
.stat-number { font-size: 1.6rem; font-weight: 700; color: var(--text); }
.stat-label { font-size: 0.8rem; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; }

.card { margin-bottom: 24px; border-radius: 12px; overflow: hidden; border: 1px solid var(--border); box-shadow: var(--shadow); }
.card-header { background: var(--vanilla); padding: 14px 20px; border-bottom: 1px solid var(--border); }
.card-header h2 { font-size: 1.15rem; margin: 0; display: flex; align-items: center; gap: 8px; }
.card-body { padding: 20px; }

.admin-table { width: 100%; border-collapse: collapse; }
.admin-table th { background: var(--vanilla); padding: 10px 16px; text-align: left; font-weight: 600; border-bottom: 2px solid var(--border); }
.admin-table td { padding: 10px 16px; border-bottom: 1px solid var(--border); }
.admin-table tbody tr:hover { background: rgba(219,161,162,0.08); }

.badge { display: inline-block; padding: 2px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; }
.badge-success { background: #2ecc71; color: white; }
.badge-warning { background: #f1c40f; color: white; }
.badge-primary { background: #3498db; color: white; }

.no-items { text-align: center; padding: 40px 0; color: var(--text-light); }

.table-responsive { overflow-x: auto; }

@media (max-width: 480px) {
    .stats-row { grid-template-columns: 1fr 1fr; }
    .page-header { flex-direction: column; align-items: flex-start; }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>