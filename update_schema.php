<?php
/**
 * ONE-TIME DATABASE SCHEMA UPDATE
 * Run this script once to add missing columns and tables for dashboard features.
 * Delete this file after running.
 */

require_once 'includes/config.php';
require_once 'includes/db.php';

// Security: Only allow admin to run this
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die('Access denied. You must be logged in as admin.');
}

echo "<h1>Updating Database Schema...</h1><pre>";

// ===== 1. Add missing columns to users table =====
$columns = ['profile_pic', 'phone', 'address', 'bio'];
foreach ($columns as $col) {
    // Check if column exists
    $stmt = $db->prepare("PRAGMA table_info(users)");
    $stmt->execute();
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array($col, $existing)) {
        $db->exec("ALTER TABLE users ADD COLUMN $col TEXT");
        echo "✅ Added column '$col' to users table.\n";
    } else {
        echo "⏭️ Column '$col' already exists in users table.\n";
    }
}

// ===== 2. Create new tables =====
$newTables = [
    "CREATE TABLE IF NOT EXISTS user_tags (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        tag TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        UNIQUE(user_id, tag)
    )",
    "CREATE TABLE IF NOT EXISTS connections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sender_id INTEGER NOT NULL,
        receiver_id INTEGER NOT NULL,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE,
        FOREIGN KEY (receiver_id) REFERENCES users (id) ON DELETE CASCADE,
        UNIQUE(sender_id, receiver_id)
    )",
    "CREATE TABLE IF NOT EXISTS invites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sender_id INTEGER NOT NULL,
        receiver_email TEXT NOT NULL,
        message TEXT,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE
    )",
    "CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        is_read INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    )"
];

foreach ($newTables as $sql) {
    try {
        $db->exec($sql);
        echo "✅ Created table.\n";
    } catch (PDOException $e) {
        echo "❌ Error creating table: " . $e->getMessage() . "\n";
    }
}

echo "\n🎉 Schema update completed successfully!\n";
echo "<strong>Delete this file (update_schema.php) from your server now.</strong>";
echo "</pre>";