<?php
// ============================================================
//  READER_GAMIFICATION.PHP – Enhanced Gamification System
//  Include this in reader.php or require from reader_ajax.php
// ============================================================

/**
 * Update user stats and check achievements.
 * Call this whenever the user makes progress (reading, highlights, etc.)
 *
 * @param int $user_id
 * @param int $book_id (optional)
 * @param string $action (e.g., 'page_read', 'highlight', 'bookmark', 'note')
 * @param int $amount (e.g., number of pages, highlights)
 */
function updateUserStats($user_id, $book_id = 0, $action = 'page_read', $amount = 1) {
    global $db;

    // Update XP
    $xp_map = [
        'page_read'   => 10,
        'highlight'   => 5,
        'bookmark'    => 3,
        'note'        => 8,
        'book_finish' => 100,
        'challenge'   => 50,
    ];
    $xp = isset($xp_map[$action]) ? $xp_map[$action] * $amount : 0;

    if ($xp > 0) {
        $stmt = $db->prepare("UPDATE user_reputations SET xp = xp + ? WHERE user_id = ?");
        $stmt->execute([$xp, $user_id]);
        // Update level based on new XP
        updateUserLevel($user_id);
    }

    // Check achievements after any action
    checkAchievements($user_id);
}

/**
 * Calculate and update user level based on total XP.
 */
function updateUserLevel($user_id) {
    global $db;

    $stmt = $db->prepare("SELECT xp FROM user_reputations WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $xp = (int)$stmt->fetchColumn();

    $level = 1;
    $xp_needed = 0;
    $levels = [
        1 => 0,
        2 => 100,
        3 => 300,
        4 => 600,
        5 => 1000,
        6 => 1500,
        7 => 2100,
        8 => 2800,
        9 => 3600,
        10 => 4500,
    ];
    foreach ($levels as $lvl => $needed) {
        if ($xp >= $needed) {
            $level = $lvl;
            $xp_needed = $needed;
        }
    }
    // Next level requirement
    $next_level = $level + 1;
    $next_needed = isset($levels[$next_level]) ? $levels[$next_level] : PHP_INT_MAX;

    $stmt = $db->prepare("UPDATE user_reputations SET level = ?, xp_to_next = ? WHERE user_id = ?");
    $stmt->execute([$level, $next_needed - $xp, $user_id]);
}

/**
 * Check all achievements and unlock new ones.
 * Returns an array of newly unlocked achievements (for notifications).
 */
function checkAchievements($user_id) {
    global $db;
    $new_achievements = [];

    $achievement_checks = [
        // Reading milestones
        'first_book_completed' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM reading_progress WHERE user_id = ? AND progress_percent >= 100");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 1;
        },
        'five_books_completed' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM reading_progress WHERE user_id = ? AND progress_percent >= 100");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 5;
        },
        'ten_books_completed' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM reading_progress WHERE user_id = ? AND progress_percent >= 100");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 10;
        },
        'twenty_five_books_completed' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM reading_progress WHERE user_id = ? AND progress_percent >= 100");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 25;
        },

        // Highlights
        'ten_highlights' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM highlights WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 10;
        },
        'fifty_highlights' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM highlights WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 50;
        },
        'hundred_highlights' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM highlights WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 100;
        },

        // Bookmarks
        'ten_bookmarks' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM bookmarks WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 10;
        },
        'fifty_bookmarks' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM bookmarks WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 50;
        },

        // Streaks
        'streak_7_days' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT current_streak FROM reading_streaks WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 7;
        },
        'streak_30_days' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT current_streak FROM reading_streaks WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 30;
        },
        'streak_365_days' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT current_streak FROM reading_streaks WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 365;
        },

        // Notes
        'first_note' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM group_notes WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 1;
        },
        'ten_notes' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM group_notes WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 10;
        },
        'fifty_notes' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM group_notes WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 50;
        },

        // Challenges
        'monthly_challenge_completed' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM reading_challenges WHERE user_id = ? AND progress >= target");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 1;
        },
        'three_monthly_challenges' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM reading_challenges WHERE user_id = ? AND progress >= target");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 3;
        },

        // Comments
        'first_comment' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM book_comments WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 1;
        },
        'ten_comments' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM book_comments WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 10;
        },

        // Level milestones
        'level_5' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT level FROM user_reputations WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 5;
        },
        'level_10' => function() use ($db, $user_id) {
            $stmt = $db->prepare("SELECT level FROM user_reputations WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() >= 10;
        },
    ];

    foreach ($achievement_checks as $type => $check) {
        $stmt = $db->prepare("SELECT id FROM achievements WHERE user_id = ? AND achievement_type = ?");
        $stmt->execute([$user_id, $type]);
        if (!$stmt->fetch()) {
            if ($check()) {
                $stmt = $db->prepare("INSERT INTO achievements (user_id, achievement_type) VALUES (?, ?)");
                $stmt->execute([$user_id, $type]);
                $new_achievements[] = $type;
            }
        }
    }

    return $new_achievements;
}

/**
 * Get user level details.
 *
 * @param int $user_id
 * @return array {level, name, xp, xp_to_next, next_level_name}
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

/**
 * Render the level badge HTML.
 *
 * @param int $user_id
 * @return string HTML for the badge.
 */
function renderLevelBadge($user_id) {
    $level_data = getReaderLevel($user_id);
    $level = $level_data['level'];
    $name = $level_data['name'];
    return '<span class="level-badge" title="' . htmlspecialchars($name) . '">🏆 Lv.' . $level . '</span>';
}

/**
 * Render the achievements panel (for modal or sidebar).
 *
 * @param int $user_id
 * @return string HTML.
 */
function renderAchievementsPanel($user_id) {
    global $db;

    $stmt = $db->prepare("SELECT achievement_type, unlocked_at FROM achievements WHERE user_id = ? ORDER BY unlocked_at DESC");
    $stmt->execute([$user_id]);
    $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $achievement_icons = [
        'first_book_completed' => '📖',
        'five_books_completed' => '📚',
        'ten_books_completed' => '📚',
        'twenty_five_books_completed' => '📚',
        'ten_highlights' => '✨',
        'fifty_highlights' => '✨',
        'hundred_highlights' => '✨',
        'ten_bookmarks' => '🔖',
        'fifty_bookmarks' => '🔖',
        'streak_7_days' => '🔥',
        'streak_30_days' => '🔥',
        'streak_365_days' => '🔥',
        'first_note' => '📝',
        'ten_notes' => '📝',
        'fifty_notes' => '📝',
        'monthly_challenge_completed' => '🏅',
        'three_monthly_challenges' => '🏅',
        'first_comment' => '💬',
        'ten_comments' => '💬',
        'level_5' => '⭐',
        'level_10' => '⭐',
    ];

    $html = '<div class="achievements-panel">';
    $html .= '<h3>🏆 Achievements</h3>';
    $html .= '<div class="achievement-list">';

    if (empty($achievements)) {
        $html .= '<p>No achievements yet. Keep reading!</p>';
    } else {
        foreach ($achievements as $a) {
            $icon = isset($achievement_icons[$a['achievement_type']]) ? $achievement_icons[$a['achievement_type']] : '🎖️';
            $label = ucwords(str_replace('_', ' ', $a['achievement_type']));
            $html .= '<div class="achievement-item">';
            $html .= '<span class="achievement-icon">' . $icon . '</span>';
            $html .= '<span class="achievement-label">' . htmlspecialchars($label) . '</span>';
            $html .= '<small>' . date('M j, Y', strtotime($a['unlocked_at'])) . '</small>';
            $html .= '</div>';
        }
    }

    $html .= '</div></div>';
    return $html;
}

/**
 * Get user stats for the analytics page.
 *
 * @param int $user_id
 * @return array
 */
function getUserStats($user_id) {
    global $db;

    // Total reading time
    $stmt = $db->prepare("SELECT SUM(duration_seconds) as total_seconds FROM reading_sessions WHERE user_id = ? AND end_time IS NOT NULL");
    $stmt->execute([$user_id]);
    $total_seconds = (int)$stmt->fetchColumn();

    // Total pages read
    $stmt = $db->prepare("SELECT SUM(pages_read) as total_pages FROM reading_sessions WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $total_pages = (int)$stmt->fetchColumn();

    // Books finished
    $stmt = $db->prepare("SELECT COUNT(*) FROM reading_progress WHERE user_id = ? AND progress_percent >= 100");
    $stmt->execute([$user_id]);
    $books_finished = (int)$stmt->fetchColumn();

    // Streak
    $stmt = $db->prepare("SELECT current_streak FROM reading_streaks WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $streak = (int)$stmt->fetchColumn();

    // Highlights count
    $stmt = $db->prepare("SELECT COUNT(*) FROM highlights WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $highlights = (int)$stmt->fetchColumn();

    // Notes count
    $stmt = $db->prepare("SELECT COUNT(*) FROM group_notes WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $notes = (int)$stmt->fetchColumn();

    // Bookmarks count
    $stmt = $db->prepare("SELECT COUNT(*) FROM bookmarks WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $bookmarks = (int)$stmt->fetchColumn();

    // Comments count
    $stmt = $db->prepare("SELECT COUNT(*) FROM book_comments WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $comments = (int)$stmt->fetchColumn();

    // Challenges completed
    $stmt = $db->prepare("SELECT COUNT(*) FROM reading_challenges WHERE user_id = ? AND progress >= target");
    $stmt->execute([$user_id]);
    $challenges = (int)$stmt->fetchColumn();

    return [
        'total_seconds' => $total_seconds,
        'total_pages' => $total_pages,
        'books_finished' => $books_finished,
        'streak' => $streak,
        'highlights' => $highlights,
        'notes' => $notes,
        'bookmarks' => $bookmarks,
        'comments' => $comments,
        'challenges' => $challenges,
    ];
}

/**
 * JavaScript for showing achievement toast notifications.
 * Call this in the reader's footer.
 */
function renderAchievementToastJS() {
    ?>
    <style>
    #achievement-toast {
        position: fixed;
        bottom: 80px;
        right: 20px;
        background: var(--card-bg);
        border: 2px solid var(--rose);
        border-radius: 16px;
        padding: 16px 24px;
        box-shadow: var(--shadow-hover);
        z-index: 99999;
        transform: translateX(120%);
        transition: transform 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        max-width: 320px;
        pointer-events: none;
    }
    #achievement-toast.show {
        transform: translateX(0);
    }
    #achievement-toast .toast-icon {
        font-size: 2rem;
        margin-right: 12px;
    }
    #achievement-toast .toast-text {
        font-weight: 600;
        font-size: 0.95rem;
    }
    #achievement-toast .toast-sub {
        color: var(--text-light);
        font-size: 0.8rem;
    }
    </style>
    <div id="achievement-toast">
        <div style="display:flex;align-items:center;">
            <span class="toast-icon">🏆</span>
            <div>
                <div class="toast-text" id="toastTitle">Achievement Unlocked!</div>
                <div class="toast-sub" id="toastDesc">Keep up the great work!</div>
            </div>
        </div>
    </div>
    <script>
    function showAchievementToast(achievementType) {
        const icons = {
            'first_book_completed': '📖',
            'five_books_completed': '📚',
            'ten_books_completed': '📚',
            'twenty_five_books_completed': '📚',
            'ten_highlights': '✨',
            'fifty_highlights': '✨',
            'hundred_highlights': '✨',
            'ten_bookmarks': '🔖',
            'fifty_bookmarks': '🔖',
            'streak_7_days': '🔥',
            'streak_30_days': '🔥',
            'streak_365_days': '🔥',
            'first_note': '📝',
            'ten_notes': '📝',
            'fifty_notes': '📝',
            'monthly_challenge_completed': '🏅',
            'three_monthly_challenges': '🏅',
            'first_comment': '💬',
            'ten_comments': '💬',
            'level_5': '⭐',
            'level_10': '⭐'
        };
        const labels = {
            'first_book_completed': 'First Book Completed',
            'five_books_completed': '5 Books Completed',
            'ten_books_completed': '10 Books Completed',
            'twenty_five_books_completed': '25 Books Completed',
            'ten_highlights': '10 Highlights',
            'fifty_highlights': '50 Highlights',
            'hundred_highlights': '100 Highlights',
            'ten_bookmarks': '10 Bookmarks',
            'fifty_bookmarks': '50 Bookmarks',
            'streak_7_days': '7-Day Streak',
            'streak_30_days': '30-Day Streak',
            'streak_365_days': '365-Day Streak',
            'first_note': 'First Note',
            'ten_notes': '10 Notes',
            'fifty_notes': '50 Notes',
            'monthly_challenge_completed': 'Monthly Challenge',
            'three_monthly_challenges': '3 Monthly Challenges',
            'first_comment': 'First Comment',
            'ten_comments': '10 Comments',
            'level_5': 'Reached Level 5',
            'level_10': 'Reached Level 10'
        };
        const icon = icons[achievementType] || '🎖️';
        const label = labels[achievementType] || achievementType.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        const toast = document.getElementById('achievement-toast');
        document.getElementById('toastIcon').textContent = icon;
        document.getElementById('toastTitle').textContent = '🏆 Achievement Unlocked!';
        document.getElementById('toastDesc').textContent = label;
        toast.classList.add('show');
        setTimeout(() => {
            toast.classList.remove('show');
        }, 5000);
    }
    </script>
    <?php
}
?>