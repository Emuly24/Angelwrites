<?php
// ============================================================
//  READER_FUNCTIONS.PHP – Shared helper functions for the reader
//  Include this in reader.php, reader_ajax.php, and any other reader files.
// ============================================================

// ---------------- GENERAL UTILITIES ----------------

/**
 * Format a duration in seconds to HH:MM:SS or MM:SS.
 *
 * @param int $seconds
 * @return string
 */
function formatDuration($seconds) {
    if (!$seconds || $seconds < 0) return '0:00';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    if ($hours > 0) {
        return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
    } else {
        return sprintf('%d:%02d', $minutes, $secs);
    }
}

/**
 * Estimate reading time (minutes) from content.
 * Assumes average reading speed of 200 words per minute.
 *
 * @param string $content HTML content.
 * @return string e.g., "3 min read"
 */
function readingTime($content) {
    $word_count = str_word_count(strip_tags($content));
    $minutes = ceil($word_count / 200);
    return $minutes < 1 ? '1 min read' : $minutes . ' min read';
}

/**
 * Escape text for safe HTML output.
 *
 * @param string $text
 * @return string
 */
function sanitizeReaderText($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * Convert a timestamp to a human‑readable "time ago" string.
 *
 * @param string|int $timestamp MySQL datetime or Unix timestamp.
 * @return string
 */
function time_ago($timestamp) {
    if (is_numeric($timestamp)) {
        $time = $timestamp;
    } else {
        $time = strtotime($timestamp);
    }
    $diff = time() - $time;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $time);
}


// ---------------- READER‑SPECIFIC HELPERS ----------------

/**
 * Get chapter number for a given page.
 * Requires global $pageToChapter.
 *
 * @param int $page
 * @param array $pageToChapter Mapping array.
 * @return int
 */
function getChapterForPage($page, $pageToChapter) {
    return $pageToChapter[$page] ?? 1;
}

/**
 * Get the title of a chapter.
 * Requires global $chapterTitles.
 *
 * @param int $chapter
 * @param array $chapterTitles
 * @return string
 */
function getChapterTitle($chapter, $chapterTitles) {
    return $chapterTitles[$chapter] ?? 'Chapter ' . $chapter;
}

/**
 * Get all pages belonging to a chapter.
 * Requires global $chapterMap.
 *
 * @param int $chapter
 * @param array $chapterMap
 * @return array
 */
function getPagesInChapter($chapter, $chapterMap) {
    return $chapterMap[$chapter] ?? [];
}

/**
 * Calculate remaining pages in the current chapter.
 *
 * @param int $page Current page.
 * @param array $pageToChapter
 * @param array $chapterMap
 * @return int
 */
function getRemainingPagesInChapter($page, $pageToChapter, $chapterMap) {
    $ch = getChapterForPage($page, $pageToChapter);
    $pagesInCh = getPagesInChapter($ch, $chapterMap);
    $idx = array_search($page, $pagesInCh);
    if ($idx === false) return 0;
    return count($pagesInCh) - $idx - 1;
}

/**
 * Estimate time remaining in the current chapter (in minutes).
 *
 * @param int $page Current page.
 * @param int $readingSpeedWPM Words per minute.
 * @param array $pageToChapter
 * @param array $chapterMap
 * @return int Minutes.
 */
function estimateTimeRemaining($page, $readingSpeedWPM, $pageToChapter, $chapterMap) {
    $remaining = getRemainingPagesInChapter($page, $pageToChapter, $chapterMap);
    if ($remaining <= 0) return 0;
    // Assume ~300 words per page (adjust as needed)
    return (int) ceil($remaining * 300 / $readingSpeedWPM);
}

/**
 * Build a comprehensive TOC array from pages and chapter data.
 *
 * @param array $pages Array of HTML content.
 * @param array $chapterMap
 * @param array $chapterTitles
 * @return array TOC entries with 'title' and 'page'.
 */
function buildTOC($pages, $chapterMap, $chapterTitles) {
    $tocEntries = [];
    $totalPages = count($pages);

    // 1. Cover (page 1)
    $tocEntries[] = ['title' => 'Cover', 'page' => 1];

    // 2. Special pages (case‑insensitive detection)
    $specialTitles = ['Copyright', 'Dedication', 'Acknowledgements', 'Author\'s Note', 'About the Author'];
    foreach ($pages as $idx => $html) {
        $pageNum = $idx + 1;
        // Skip if already a chapter start
        if (in_array($pageNum, array_column($chapterMap, 0) ?: [])) continue;
        foreach ($specialTitles as $special) {
            if (preg_match('/<h[2-3][^>]*>\s*' . preg_quote($special, '/') . '\s*<\/h[2-3]>/i', $html)) {
                $tocEntries[] = ['title' => $special, 'page' => $pageNum];
                break;
            }
        }
    }

    // 3. Regular chapters
    foreach ($chapterTitles as $chIndex => $title) {
        $startPage = $chapterMap[$chIndex][0] ?? 1;
        $tocEntries[] = ['title' => $title, 'page' => $startPage];
    }

    return $tocEntries;
}

/**
 * Detect chapters from page content and return mapping arrays.
 *
 * @param array $pages
 * @return array [chapterMap, chapterTitles, pageToChapter]
 */
function detectChapters($pages) {
    $chapterMap = [];
    $chapterTitles = [];
    $pageToChapter = [];
    $currentChapter = 0;

    foreach ($pages as $idx => $html) {
        $pageNum = $idx + 1;
        if (preg_match('/<h[2-3][^>]*>(.*?Chapter\s+(\d+|[IVXLCDM]+).*?)<\/h[2-3]>/i', $html, $matches)) {
            $currentChapter++;
            $chapterTitles[$currentChapter] = trim(strip_tags($matches[1]));
            $chapterMap[$currentChapter] = [];
        }
        $pageToChapter[$pageNum] = $currentChapter ?: 1;
        if ($currentChapter > 0) {
            $chapterMap[$currentChapter][] = $pageNum;
        }
    }

    // If no chapters detected, treat whole book as one chapter
    if (empty($chapterMap)) {
        $chapterMap[1] = range(1, count($pages));
        $chapterTitles[1] = 'Chapter 1';
        foreach (range(1, count($pages)) as $p) {
            $pageToChapter[$p] = 1;
        }
    }

    return [$chapterMap, $chapterTitles, $pageToChapter];
}


// ---------------- GAMIFICATION / LEVEL HELPERS ----------------

/**
 * Calculate reader level based on total reading time (hours).
 * (Older version – kept for backward compatibility.)
 *
 * @param int $user_id
 * @return array ['level', 'name', 'hours_needed']
 */
function getReaderLevelLegacy($user_id) {
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

/**
 * Enhanced level system with XP (requires user_reputations table).
 * Used by the gamification module.
 *
 * @param int $user_id
 * @return array
 */
function getReaderLevel($user_id) {
    global $db;

    $stmt = $db->prepare("SELECT level, xp, xp_to_next FROM user_reputations WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$data) {
        // Initialize if not exists
        $stmt = $db->prepare("INSERT INTO user_reputations (user_id, level, xp, xp_to_next) VALUES (?, 1, 0, 100)");
        $stmt->execute([$user_id]);
        $data = ['level' => 1, 'xp' => 0, 'xp_to_next' => 100];
    }

    $level_names = [
        1  => 'Beginner Reader',
        2  => 'Avid Reader',
        3  => 'Bookworm',
        4  => 'Bibliophile',
        5  => 'Book Sage',
        6  => 'Literary Scholar',
        7  => 'Master Reader',
        8  => 'Grand Bibliophile',
        9  => 'Legendary Reader',
        10 => 'Living Library',
    ];

    $level = (int)$data['level'];
    $name = isset($level_names[$level]) ? $level_names[$level] : 'Legendary Reader';
    $next_level_name = isset($level_names[$level + 1]) ? $level_names[$level + 1] : 'Max Level';

    return [
        'level' => $level,
        'name' => $name,
        'xp' => (int)$data['xp'],
        'xp_to_next' => (int)$data['xp_to_next'],
        'next_level_name' => $next_level_name,
    ];
}


// ---------------- RENDERING HELPERS ----------------

/**
 * Render a level badge for the toolbar.
 *
 * @param int $user_id
 * @return string HTML
 */
function renderLevelBadge($user_id) {
    $level_data = getReaderLevel($user_id);
    $level = $level_data['level'];
    $name = $level_data['name'];
    return '<span class="level-badge" title="' . htmlspecialchars($name) . '">🏆 Lv.' . $level . '</span>';
}

/**
 * Render a streak badge.
 *
 * @param int $streak_days
 * @return string HTML (empty if $streak_days <= 0)
 */
function renderStreakBadge($streak_days) {
    if ($streak_days <= 0) return '';
    return '<span class="streak-badge">🔥 ' . (int)$streak_days . 'd</span>';
}

/**
 * Render the progress ring (circular progress).
 *
 * @param int $percent 0–100
 * @param string $id Optional element ID for the fill circle.
 * @return string HTML
 */
function renderProgressRing($percent = 0, $id = 'progressFill') {
    $circumference = 2 * M_PI * 16; // r = 16
    $offset = $circumference - ($percent / 100) * $circumference;
    return '
    <div class="progress-ring">
        <svg viewBox="0 0 36 36">
            <circle class="bg" cx="18" cy="18" r="16"/>
            <circle class="fill" id="' . $id . '" cx="18" cy="18" r="16"
                    stroke-dasharray="' . $circumference . '"
                    stroke-dashoffset="' . $offset . '"/>
        </svg>
        <span class="percent" id="progressPercent">' . $percent . '%</span>
    </div>';
}
?>