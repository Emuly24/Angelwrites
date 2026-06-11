<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=subscribers_' . date('Y-m-d') . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, ['Email', 'Name', 'Status', 'Subscribed At']);

$stmt = $db->query("SELECT email, name, is_active, subscribed_at FROM newsletter ORDER BY subscribed_at DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $row['email'],
        $row['name'] ?? '',
        $row['is_active'] ? 'Active' : 'Inactive',
        $row['subscribed_at']
    ]);
}
fclose($output);
exit;