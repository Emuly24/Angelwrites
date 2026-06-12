<?php
// ============================================================
//  READER_GAMIFICATION.PHP – Streaks, Achievements, Goals
//  Include this in reader.php or call from reader_ajax.php
// ============================================================

function checkAchievements($user_id) {
    global $db;

    // Check: First book completed
    $stmt = $db->prepare("SELECT COUNT(*) FROM reading_progress WHERE user_id = ? AND progress_percent >= 100");
    $stmt->execute([$user_id]);
    $completed_books = $stmt->fetchColumn();

    if ($completed_books >= 1) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO achievements (user_id, achievement_type) VALUES (?, ?)");
        $stmt->execute([$user_id, 'first_book_completed']);
    }

    if ($completed_books >= 5) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO achievements (user_id, achievement_type) VALUES (?, ?)");
        $stmt->execute([$user_id, 'five_books_completed']);
    }

    if ($completed_books >= 10) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO achievements (user_id, achievement_type) VALUES (?, ?)");
        $stmt->execute([$user_id, 'ten_books_completed']);
    }

    // Check: 10 highlights
    $stmt = $db->prepare("SELECT COUNT(*) FROM highlights WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $highlights = $stmt->fetchColumn();

    if ($highlights >= 10) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO achievements (user_id, achievement_type) VALUES (?, ?)");
        $stmt->execute([$user_id, 'ten_highlights']);
    }

    // Check: 10 bookmarks
    $stmt = $db->prepare("SELECT COUNT(*) FROM bookmarks WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $bookmarks = $stmt->fetchColumn();

    if ($bookmarks >= 10) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO achievements (user_id, achievement_type) VALUES (?, ?)");
        $stmt->execute([$user_id, 'ten_bookmarks']);
    }
}

function getReaderLevel($user_id) {
    global $db;

    $stmt = $db->prepare("SELECT SUM(duration_seconds) as total_seconds FROM reading_sessions WHERE user_id = ? AND end_time IS NOT NULL");
    $stmt->execute([$user_id]);
    $total_seconds = $stmt->fetchColumn() ?? 0;

    $hours = floor($total_seconds / 3600);

    if ($hours < 10) return ['level' => 1, 'name' => 'Beginner Reader', 'hours_needed' => 10 - $hours];
    if ($hours < 50) return ['level' => 2, 'name' => 'Avid Reader', 'hours_needed' => 50 - $hours];
    if ($hours < 200) return ['level' => 3, 'name' => 'Bookworm', 'hours_needed' => 200 - $hours];
    if ($hours < 500) return ['level' => 4, 'name' => 'Bibliophile', 'hours_needed' => 500 - $hours];
    return ['level' => 5, 'name' => 'Legendary Reader', 'hours_needed' => 0];
}