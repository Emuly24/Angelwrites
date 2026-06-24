<?php
// ============================================================
//  READER_ANALYTICS.PHP – Advanced Admin Analytics Dashboard
//  With charts, date filters, export, and comprehensive metrics.
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reader_functions.php'; // for formatDuration

redirectIfNotAdmin();

$pageTitle = 'Reader Analytics';
require_once __DIR__ . '/../includes/header.php';

// --- Handle Date Filter ---
$date_range = isset($_GET['range']) ? $_GET['range'] : 'all';
$start_date = '';
$end_date = '';
$date_condition = '';

switch ($date_range) {
    case '7days':
        $start_date = date('Y-m-d', strtotime('-7 days'));
        $date_condition = "AND start_time >= '$start_date'";
        break;
    case '30days':
        $start_date = date('Y-m-d', strtotime('-30 days'));
        $date_condition = "AND start_time >= '$start_date'";
        break;
    case '90days':
        $start_date = date('Y-m-d', strtotime('-90 days'));
        $date_condition = "AND start_time >= '$start_date'";
        break;
    default: // all
        $date_condition = '';
}

// --- KPIs ---
// Total readers (unique users with at least one session)
$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions");
$total_readers = $stmt->fetchColumn();

// Total books
$stmt = $db->query("SELECT COUNT(*) FROM books");
$total_books = $stmt->fetchColumn();

// Total reading sessions
$stmt = $db->query("SELECT COUNT(*) FROM reading_sessions $date_condition");
$total_sessions = $stmt->fetchColumn();

// Total reading time (hours)
$stmt = $db->query("SELECT SUM(duration_seconds) FROM reading_sessions $date_condition");
$total_seconds = $stmt->fetchColumn() ?? 0;
$total_hours = floor($total_seconds / 3600);

// Active readers (last 7 days)
$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-7 days')");
$active_7days = $stmt->fetchColumn();

// Average completion rate across all books
$stmt = $db->query("
    SELECT AVG(completion_rate) as avg_rate FROM (
        SELECT 
            book_id,
            ROUND(100.0 * SUM(CASE WHEN progress_percent >= 100 THEN 1 ELSE 0 END) / COUNT(DISTINCT user_id), 1) as completion_rate
        FROM reading_progress
        GROUP BY book_id
    )
");
$avg_completion = round($stmt->fetchColumn() ?? 0, 1);

// --- Most Active Readers (top 10) ---
$stmt = $db->query("
    SELECT u.name, u.email, u.username, COUNT(rs.id) as sessions, SUM(rs.duration_seconds) as total_time
    FROM reading_sessions rs
    JOIN users u ON rs.user_id = u.id
    WHERE 1=1 $date_condition
    GROUP BY rs.user_id
    ORDER BY total_time DESC
    LIMIT 10
");
$active_readers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Books with Highest Completion Rate ---
$stmt = $db->query("
    SELECT b.id, b.title, 
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

// --- Drop-off Points (top chapters where readers stop) ---
$stmt = $db->query("
    SELECT rp.position_section as chapter, COUNT(*) as drop_offs
    FROM reading_progress rp
    WHERE rp.progress_percent < 100
    GROUP BY rp.position_section
    ORDER BY drop_offs DESC
    LIMIT 10
");
$drop_offs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Reading Activity Over Time (last 30 days) ---
$stmt = $db->query("
    SELECT date(start_time) as day, COUNT(*) as sessions, SUM(duration_seconds) as total_time
    FROM reading_sessions
    WHERE start_time > date('now', '-30 days')
    GROUP BY date(start_time)
    ORDER BY day ASC
");
$activity_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare chart data
$days = [];
$session_counts = [];
$time_data = [];
foreach ($activity_data as $row) {
    $days[] = $row['day'];
    $session_counts[] = $row['sessions'];
    $time_data[] = round($row['total_time'] / 60, 1); // minutes
}

// --- Completion Distribution (buckets) ---
$stmt = $db->query("
    SELECT 
        CASE 
            WHEN progress_percent = 0 THEN '0%'
            WHEN progress_percent < 25 THEN '1-24%'
            WHEN progress_percent < 50 THEN '25-49%'
            WHEN progress_percent < 75 THEN '50-74%'
            WHEN progress_percent < 100 THEN '75-99%'
            ELSE '100%'
        END as bucket,
        COUNT(*) as count
    FROM reading_progress
    GROUP BY bucket
    ORDER BY bucket
");
$completion_dist = $stmt->fetchAll(PDO::FETCH_ASSOC);
$buckets = [];
$bucket_counts = [];
foreach ($completion_dist as $row) {
    $buckets[] = $row['bucket'];
    $bucket_counts[] = $row['count'];
}

// --- Export CSV ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="reader_analytics_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Readers', $total_readers]);
    fputcsv($output, ['Total Books', $total_books]);
    fputcsv($output, ['Total Sessions', $total_sessions]);
    fputcsv($output, ['Total Reading Hours', $total_hours]);
    fputcsv($output, ['Active Readers (7 days)', $active_7days]);
    fputcsv($output, ['Average Completion Rate', $avg_completion . '%']);
    fputcsv($output, []);
    fputcsv($output, ['Book', 'Readers', 'Completions', 'Completion Rate']);
    foreach ($book_stats as $stat) {
        fputcsv($output, [$stat['title'], $stat['readers'], $stat['completions'], $stat['completion_rate'] . '%']);
    }
    fclose($output);
    exit;
}
?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>📊 Reader Analytics</h1>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <form method="GET" style="display:flex;gap:8px;align-items:center;">
                    <label for="range" style="font-size:0.9rem;">Date Range:</label>
                    <select name="range" id="range" onchange="this.form.submit()">
                        <option value="all" <?php echo $date_range === 'all' ? 'selected' : ''; ?>>All Time</option>
                        <option value="7days" <?php echo $date_range === '7days' ? 'selected' : ''; ?>>Last 7 Days</option>
                        <option value="30days" <?php echo $date_range === '30days' ? 'selected' : ''; ?>>Last 30 Days</option>
                        <option value="90days" <?php echo $date_range === '90days' ? 'selected' : ''; ?>>Last 90 Days</option>
                    </select>
                </form>
                <a href="?export=csv&range=<?php echo $date_range; ?>" class="btn btn-primary">📥 Export CSV</a>
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">Back to Dashboard</a>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">👤</div>
                <div class="stat-number"><?php echo number_format($total_readers); ?></div>
                <div class="stat-label">Total Readers</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📚</div>
                <div class="stat-number"><?php echo number_format($total_books); ?></div>
                <div class="stat-label">Total Books</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📖</div>
                <div class="stat-number"><?php echo number_format($total_sessions); ?></div>
                <div class="stat-label">Reading Sessions</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⏱️</div>
                <div class="stat-number"><?php echo number_format($total_hours); ?></div>
                <div class="stat-label">Reading Hours</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🔥</div>
                <div class="stat-number"><?php echo number_format($active_7days); ?></div>
                <div class="stat-label">Active Readers (7d)</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🏆</div>
                <div class="stat-number"><?php echo $avg_completion; ?>%</div>
                <div class="stat-label">Avg Completion Rate</div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="chart-row">
            <div class="card chart-card">
                <div class="card-header"><h2>📈 Reading Activity (Last 30 Days)</h2></div>
                <div class="card-body">
                    <canvas id="activityChart" style="width:100%;height:250px;"></canvas>
                </div>
            </div>
            <div class="card chart-card">
                <div class="card-header"><h2>📊 Completion Distribution</h2></div>
                <div class="card-body">
                    <canvas id="completionChart" style="width:100%;height:250px;"></canvas>
                </div>
            </div>
        </div>

        <!-- Book Completion Rates -->
        <div class="card">
            <div class="card-header"><h2>📚 Books by Completion Rate</h2></div>
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
                                    <td><a href="<?php echo SITE_URL; ?>/admin/book_detail.php?id=<?php echo $stat['id']; ?>"><?php echo htmlspecialchars($stat['title']); ?></a></td>
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

        <!-- Most Active Readers -->
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
                                    <td><?php echo htmlspecialchars($reader['name'] ?: $reader['username']); ?></td>
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

        <!-- Drop-off Points -->
        <div class="card">
            <div class="card-header"><h2>📉 Drop-off Points (Chapters Where Readers Stop)</h2></div>
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

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Activity Chart ---
    const activityCtx = document.getElementById('activityChart').getContext('2d');
    new Chart(activityCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($days); ?>,
            datasets: [{
                label: 'Sessions',
                data: <?php echo json_encode($session_counts); ?>,
                borderColor: '#DBA1A2',
                backgroundColor: 'rgba(219, 161, 162, 0.1)',
                fill: true,
                tension: 0.3
            }, {
                label: 'Minutes Read',
                data: <?php echo json_encode($time_data); ?>,
                borderColor: '#6b5a5a',
                backgroundColor: 'rgba(107, 90, 90, 0.1)',
                fill: true,
                tension: 0.3,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: { position: 'top' }
            },
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'Sessions' } },
                y1: { beginAtZero: true, position: 'right', title: { display: true, text: 'Minutes' }, grid: { drawOnChartArea: false } }
            }
        }
    });

    // --- Completion Distribution Chart ---
    const completionCtx = document.getElementById('completionChart').getContext('2d');
    new Chart(completionCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($buckets); ?>,
            datasets: [{
                data: <?php echo json_encode($bucket_counts); ?>,
                backgroundColor: ['#DBA1A2', '#e8c0c0', '#f5e0e0', '#f0d0d0', '#e0b0b0', '#c08a8b'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
});
</script>

<style>
.admin-page .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
.admin-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 24px; }
.admin-header h1 { margin: 0; }

.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 24px; }
.stat-card { background: var(--card-bg); border-radius: 12px; padding: 20px; text-align: center; border: 1px solid var(--border); box-shadow: var(--shadow); transition: transform 0.2s; }
.stat-card:hover { transform: translateY(-4px); }
.stat-icon { font-size: 1.8rem; margin-bottom: 4px; }
.stat-number { font-size: 1.8rem; font-weight: 700; color: var(--rose); }
.stat-label { font-size: 0.85rem; color: var(--text-light); margin-top: 4px; }

.chart-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
.chart-card .card-body { padding: 12px; height: 280px; }
@media (max-width: 768px) {
    .chart-row { grid-template-columns: 1fr; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}

.card { background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow); margin-bottom: 24px; }
.card-header { padding: 16px 20px; border-bottom: 1px solid var(--border); }
.card-header h2 { margin: 0; font-size: 1.1rem; font-weight: 600; color: var(--dark); }
.card-body { padding: 16px 20px; }

.table-responsive { overflow-x: auto; }
.admin-table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
.admin-table th, .admin-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--border); }
.admin-table th { background: var(--bg); font-weight: 600; color: var(--text-light); }
.admin-table tr:hover { background: var(--bg); }

.btn { display: inline-block; padding: 8px 20px; border-radius: 20px; border: none; cursor: pointer; font-weight: 600; text-decoration: none; transition: all 0.2s; }
.btn-primary { background: var(--rose); color: white; }
.btn-primary:hover { background: var(--rose-dark); transform: scale(1.02); }
.btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
.btn-outline:hover { background: var(--border); }

select { padding: 6px 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); font-size: 0.9rem; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>