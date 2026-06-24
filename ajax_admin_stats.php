<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

$stats = [];

// Core counts
$stmt = $db->query("SELECT COUNT(*) FROM users"); $stats['users'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM books"); $stats['books'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM poems"); $stats['poems'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM sessions"); $stats['sessions'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM blog_posts"); $stats['posts'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM videos"); $stats['videos'] = $stmt->fetchColumn();

// Monitoring stats
$stmt = $db->query("SELECT SUM(view_count) FROM poems"); $stats['poem_views'] = $stmt->fetchColumn() ?? 0;
$stmt = $db->query("SELECT SUM(view_count) FROM books"); $stats['book_views'] = $stmt->fetchColumn() ?? 0;
$stats['total_views'] = number_format($stats['poem_views'] + $stats['book_views']);

$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-1 day')"); $stats['active_today'] = $stmt->fetchColumn() ?? 0;
$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-7 days')"); $stats['active_week'] = $stmt->fetchColumn() ?? 0;
$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-30 days')"); $stats['active_month'] = $stmt->fetchColumn() ?? 0;
$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-365 days')"); $stats['active_year'] = $stmt->fetchColumn() ?? 0;

$stmt = $db->query("SELECT SUM(duration_seconds) as total_seconds FROM reading_sessions"); $stats['reading_hours'] = number_format(floor(($stmt->fetchColumn() ?? 0) / 3600));

header('Content-Type: application/json');
echo json_encode($stats);