<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

aw_require('manage_newsletter'); // K1
// N3: ?status=active|pending|unsubscribed exports one group; segments and dates are included.
// Cells that a spreadsheet would run as a formula (=, +, -, @ …) are prefixed with ' so a name
// typed on the public form cannot become a formula in Excel (CSV injection).
require_once __DIR__ . '/../includes/newsletter.php';

$status = in_array($_GET['status'] ?? '', ['active', 'pending', 'unsubscribed'], true) ? $_GET['status'] : '';
$where = ['active' => 'is_active = 1', 'pending' => 'is_active = 0 AND unsubscribed_at IS NULL', 'unsubscribed' => 'is_active = 0 AND unsubscribed_at IS NOT NULL'][$status] ?? '1=1';
$segs = [];
foreach ($db->query("SELECT a.subscriber_id, s.name FROM subscriber_segment_assignments a JOIN subscriber_segments s ON s.id = a.segment_id") as $r) {
    $segs[$r['subscriber_id']][] = $r['name'];
}
function aw_csv_cell($v) {
    $v = (string)$v;
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}
aw_nl_audit($db, 'export_subscribers', $status ?: 'all');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=subscribers_' . ($status ?: 'all') . '_' . date('Y-m-d') . '.csv');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows names with accents correctly
fputcsv($out, ['Email', 'Name', 'Status', 'Segments', 'Subscribed at', 'Unsubscribed at', 'Source']);
$st = $db->query("SELECT * FROM newsletter WHERE $where ORDER BY subscribed_at DESC, id DESC");
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, array_map('aw_csv_cell', [
        $row['email'], $row['name'] ?? '',
        ['active' => 'Active', 'pending' => 'Awaiting confirmation', 'unsubscribed' => 'Unsubscribed'][aw_nl_status($row)],
        implode('; ', $segs[$row['id']] ?? []), $row['subscribed_at'], $row['unsubscribed_at'] ?? '', $row['source'] ?? 'website',
    ]));
}
fclose($out);
exit;
