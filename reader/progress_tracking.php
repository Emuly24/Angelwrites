<?php
// ============================================================
//  PROGRESS_TRACKING.PHP – Enhanced Progress Tracking with Gamification
//  Integrates with: gamification, reading circles, challenges.
// ============================================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/reader_functions.php'; // for updateUserStats, etc.

class ProgressTracker {
    private $db;
    private $user_id;
    private $book_id;

    /**
     * Constructor.
     * @param int $user_id
     * @param int $book_id
     */
    public function __construct($user_id, $book_id) {
        $this->db = $GLOBALS['db'];
        $this->user_id = $user_id;
        $this->book_id = $book_id;
    }

    /**
     * Get current progress for this user and book.
     * @return array|null
     */
    public function getProgress() {
        if (!$this->user_id) return null;

        $stmt = $this->db->prepare("SELECT * FROM reading_progress WHERE user_id = ? AND book_id = ?");
        $stmt->execute([$this->user_id, $this->book_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Save reading progress and trigger all related systems.
     *
     * @param int $offset   Scroll offset (if any)
     * @param int $chapter  Current chapter/page number (1‑based)
     * @param int $percent  Progress percentage (0–100)
     * @param int $pages_read (optional) Number of pages read since last save (for XP)
     * @return bool
     */
    public function saveProgress($offset, $chapter, $percent, $pages_read = 1) {
        if (!$this->user_id) return false;

        // Get current progress to detect changes
        $current = $this->getProgress();
        $old_percent = $current ? (int)$current['progress_percent'] : 0;

        // Upsert progress record
        $stmt = $this->db->prepare("SELECT id FROM reading_progress WHERE user_id = ? AND book_id = ?");
        $stmt->execute([$this->user_id, $this->book_id]);
        $exists = $stmt->fetch();

        if ($exists) {
            $stmt = $this->db->prepare("
                UPDATE reading_progress
                SET position_offset = ?, position_section = ?, progress_percent = ?, last_accessed_at = CURRENT_TIMESTAMP
                WHERE user_id = ? AND book_id = ?
            ");
            $stmt->execute([$offset, $chapter, $percent, $this->user_id, $this->book_id]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO reading_progress (user_id, book_id, position_offset, position_section, progress_percent)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$this->user_id, $this->book_id, $offset, $chapter, $percent]);
        }

        // --- 1. Update reading streak ---
        $this->updateStreak();

        // --- 2. Award XP for pages read ---
        if (function_exists('updateUserStats') && $pages_read > 0) {
            // Only award XP if progress increased
            if ($percent > $old_percent) {
                // Calculate pages read since last save
                $pages_advanced = max(1, $percent - $old_percent);
                // Award XP for each page (10 XP per page)
                updateUserStats($this->user_id, $this->book_id, 'page_read', $pages_advanced);
            }
        }

        // --- 3. Update reading circle position (if user is in a circle) ---
        $this->updateCirclePosition($chapter);

        // --- 4. Update monthly challenge progress ---
        $this->updateChallengeProgress($pages_read);

        // --- 5. Check milestones and achievements ---
        $this->checkMilestones($percent, $old_percent);

        // --- 6. Check for book completion ---
        if ($percent >= 100 && $old_percent < 100) {
            $this->handleBookCompletion();
        }

        return true;
    }

    /**
     * Update reading streak.
     */
    private function updateStreak() {
        $today = date('Y-m-d');

        $stmt = $this->db->prepare("SELECT * FROM reading_streaks WHERE user_id = ?");
        $stmt->execute([$this->user_id]);
        $streak = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$streak) {
            $stmt = $this->db->prepare("
                INSERT INTO reading_streaks (user_id, current_streak, longest_streak, last_read_date)
                VALUES (?, 1, 1, ?)
            ");
            $stmt->execute([$this->user_id, $today]);
            return;
        }

        $last_read = $streak['last_read_date'];
        $current = (int)$streak['current_streak'];
        $longest = (int)$streak['longest_streak'];

        if ($last_read === $today) return;

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if ($last_read === $yesterday) {
            $current++;
            if ($current > $longest) $longest = $current;

            // Check streak achievements (7, 30, 365 days)
            if ($current === 7) {
                $this->unlockAchievement('streak_7_days');
            }
            if ($current === 30) {
                $this->unlockAchievement('streak_30_days');
            }
            if ($current === 365) {
                $this->unlockAchievement('streak_365_days');
            }
        } else {
            $current = 1;
        }

        $stmt = $this->db->prepare("
            UPDATE reading_streaks
            SET current_streak = ?, longest_streak = ?, last_read_date = ?
            WHERE user_id = ?
        ");
        $stmt->execute([$current, $longest, $today, $this->user_id]);
    }

    /**
     * Update reading circle position (if user is a member).
     */
    private function updateCirclePosition($chapter) {
        // Check if user is in a reading circle for this book
        $stmt = $this->db->prepare("
            SELECT id FROM reading_circles
            WHERE book_id = ? AND user_id = ?
        ");
        $stmt->execute([$this->book_id, $this->user_id]);
        $circle_id = $stmt->fetchColumn();

        if ($circle_id) {
            $position = 'Page ' . $chapter;
            $stmt = $this->db->prepare("
                UPDATE reading_circles
                SET last_read_position = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$position, $circle_id]);
        }
    }

    /**
     * Update monthly challenge progress.
     */
    private function updateChallengeProgress($pages_read) {
        if (!$pages_read || $pages_read <= 0) return;

        $month = date('m');
        $year = date('Y');

        // Try to get existing challenge
        $stmt = $this->db->prepare("
            SELECT id, progress, target, completed
            FROM reading_challenges
            WHERE user_id = ? AND month = ? AND year = ?
        ");
        $stmt->execute([$this->user_id, $month, $year]);
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$challenge) {
            // Create default challenge if none exists
            $default_target = 30;
            $default_goal = "Read $default_target pages this month";
            $stmt = $this->db->prepare("
                INSERT INTO reading_challenges (user_id, month, year, goal, target, progress)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$this->user_id, $month, $year, $default_goal, $default_target, $pages_read]);
            return;
        }

        $new_progress = $challenge['progress'] + $pages_read;
        $stmt = $this->db->prepare("
            UPDATE reading_challenges
            SET progress = ?
            WHERE id = ?
        ");
        $stmt->execute([$new_progress, $challenge['id']]);

        // Check if challenge completed
        if ($new_progress >= $challenge['target'] && !$challenge['completed']) {
            $stmt = $this->db->prepare("UPDATE reading_challenges SET completed = 1 WHERE id = ?");
            $stmt->execute([$challenge['id']]);
            $this->unlockAchievement('monthly_challenge_completed');
            // Award extra XP for completing challenge
            if (function_exists('updateUserStats')) {
                updateUserStats($this->user_id, $this->book_id, 'challenge', 1);
            }
        }
    }

    /**
     * Check milestones and unlock achievements.
     */
    private function checkMilestones($percent, $old_percent) {
        // Milestone 50%
        if ($percent >= 50 && $old_percent < 50) {
            $this->sendAdminNotification(50);
        }
        // Milestone 75%
        if ($percent >= 75 && $old_percent < 75) {
            $this->sendAdminNotification(75);
        }
        // Milestone 100% (handled separately)
    }

    /**
     * Handle book completion (achievements, notifications).
     */
    private function handleBookCompletion() {
        // Unlock achievement
        $this->unlockAchievement('first_book_completed');

        // Check for multiple books completed
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM reading_progress
            WHERE user_id = ? AND progress_percent >= 100
        ");
        $stmt->execute([$this->user_id]);
        $completed_books = $stmt->fetchColumn();

        if ($completed_books >= 5) {
            $this->unlockAchievement('five_books_completed');
        }
        if ($completed_books >= 10) {
            $this->unlockAchievement('ten_books_completed');
        }
        if ($completed_books >= 25) {
            $this->unlockAchievement('twenty_five_books_completed');
        }

        // Send admin notification for completion
        $this->sendAdminNotification(100);
    }

    /**
     * Unlock an achievement for the user.
     */
    private function unlockAchievement($type) {
        $stmt = $this->db->prepare("
            INSERT OR IGNORE INTO achievements (user_id, achievement_type)
            VALUES (?, ?)
        ");
        $stmt->execute([$this->user_id, $type]);
    }

    /**
     * Send admin notification for milestones.
     */
    private function sendAdminNotification($percent) {
        // Avoid duplicate notifications
        $stmt = $this->db->prepare("
            SELECT id FROM reader_admin_notifications
            WHERE user_id = ? AND book_id = ? AND event_type = ?
        ");
        $stmt->execute([$this->user_id, $this->book_id, 'progress_' . $percent]);
        if ($stmt->fetch()) return;

        // Insert notification
        $stmt = $this->db->prepare("
            INSERT INTO reader_admin_notifications (user_id, book_id, event_type)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$this->user_id, $this->book_id, 'progress_' . $percent]);

        // Send email (optional)
        require_once __DIR__ . '/../../includes/mail_helper.php';

        $stmt = $this->db->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$this->user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare("SELECT title FROM books WHERE id = ?");
        $stmt->execute([$this->book_id]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);

        $admin_email = 'angelwrites@zohomail.com';
        $subject = '📖 Reader Milestone: ' . $percent . '% – ' . $book['title'];
        $body = "<h2>Reader Milestone Reached</h2>";
        $body .= "<p><strong>User:</strong> " . ($user['email'] ?? 'Unknown') . "</p>";
        $body .= "<p><strong>Book:</strong> " . $book['title'] . "</p>";
        $body .= "<p><strong>Progress:</strong> " . $percent . "%</p>";
        sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');
    }
}