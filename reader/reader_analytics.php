<?php
// ============================================================
//  READER_ANALYTICS.PHP – Admin dashboard for reader analytics
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

redirectIfNotAdmin();

$pageTitle = 'Reader Analytics';
require_once __DIR__ . '/../includes/header.php';

// Most active readers
$stmt = $db->query("
    SELECT u.name, u.email, COUNT(rs.id) as sessions, SUM(rs.duration_seconds) as total_time
    FROM reading_sessions rs
    JOIN users u ON rs.user_id = u.id
    GROUP BY rs.user_id
    ORDER BY total_time DESC
    LIMIT 10
");
$active_readers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Books with highest completion rate
$stmt = $db->query("
    SELECT b.title, 
           COUNT(DISTINCT rp.user_id) as readers,
           SUM(CASE WHEN rp.progress_percent >= 100 THEN 1 ELSE 0 END) as completions,
           ROUND(100.0 * SUM(CASE WHEN rp.progress_percent >= 100 THEN 1 ELSE 0 END) / COUNT(DISTINCT rp.user_id), 1) as completion_rate
    FROM reading_progress rp
    JOIN books b ON rp.book_id = b.id
    GROUP BY rp.book_id
    ORDER BY completion_rate DESC
    LIMIT 10
");
$book_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Drop-off points
$stmt = $db->query("
    SELECT rp.position_section as chapter, COUNT(*) as drop_offs
    FROM reading_progress rp
    WHERE rp.progress_percent < 100
    GROUP BY rp.position_section
    ORDER BY drop_offs DESC
    LIMIT 10
");
$drop_offs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total reading time
$stmt = $db->query("SELECT SUM(duration_seconds) as total_seconds FROM reading_sessions");
$total_seconds = $stmt->fetchColumn() ?? 0;
$total_hours = floor($total_seconds / 3600);

// Total active readers (who read in the last 7 days)
$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-7 days')");
$active_7days = $stmt->fetchColumn();
?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>📊 Reader Analytics</h1>
            <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">Back to Dashboard</a>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($total_hours); ?></div>
                <div class="stat-label">Total Reading Hours</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($active_7days); ?></div>
                <div class="stat-label">Active Readers (7 days)</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($active_readers); ?></div>
                <div class="stat-label">Top Readers Tracked</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>📚 Completion Rates</h2></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Book</th>
                                <th>Readers</th>
                                <th>Completions</th>
                                <th>Completion Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($book_stats as $stat): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($stat['title']); ?></td>
                                    <td><?php echo $stat['readers']; ?></td>
                                    <td><?php echo $stat['completions']; ?></td>
                                    <td><?php echo $stat['completion_rate']; ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>🔥 Most Active Readers</h2></div>
            <div class="card-body">
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
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>📉 Drop-off Points</h2></div>
            <div class="card-body">
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
            </div>
        </div>
    </div>
</div>

<style>
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 24px; }
.stat-card { background: var(--card-bg); border-radius: 12px; padding: 20px; text-align: center; border: 1px solid var(--border); }
.stat-number { font-size: 2rem; font-weight: 700; color: var(--rose); }
.stat-label { font-size: 0.9rem; color: var(--text-light); }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>