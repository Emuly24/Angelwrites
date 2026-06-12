<?php
// ============================================================
//  READER_FUNCTIONS.PHP – Shared helper functions for the reader
// ============================================================

// Format duration (seconds to HH:MM:SS)
function formatDuration($seconds) {
    if (!$seconds) return '0:00';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    if ($hours > 0) {
        return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
    } else {
        return sprintf('%d:%02d', $minutes, $secs);
    }
}

// Calculate reading time (minutes)
function readingTime($content) {
    $word_count = str_word_count(strip_tags($content));
    $minutes = ceil($word_count / 200);
    return $minutes < 1 ? '1 min read' : $minutes . ' min read';
}

// Get reader level based on total reading time
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

// Sanitize text for safe output
function sanitizeReaderText($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
function time_ago($timestamp) {
    $time = strtotime($timestamp);
    $diff = time() - $time;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $time);
}