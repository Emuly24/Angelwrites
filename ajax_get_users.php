<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
$stmt = $db->query("SELECT id, name FROM users WHERE name != '' ORDER BY name LIMIT 50");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode($users);