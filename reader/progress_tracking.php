<?php
// ============================================================
//  PROGRESS_TRACKING.PHP – Enhanced Progress Tracking with Gamification
//  Integrates with: gamification, reading circles, challenges.
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/reader_functions.php'; // for updateUserStats, etc.

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

        // C1: streaks and XP are retired (PROGRAM_PLAN.md, wish C).

        // --- 3. Update reading circle position (if user is in a circle) ---
        $this->updateCirclePosition($chapter);

        // C1: monthly challenges and achievements are retired; reading milestones still notify the admin.
        $this->checkMilestones($percent, $old_percent);

        // --- 6. Check for book completion ---
        if ($percent >= 100 && $old_percent < 100) {
            $this->handleBookCompletion();
        }

        return true;
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
     * Tell the admin when a reader passes 50% and 75%.
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
     * Handle book completion (admin notification).
     */
    private function handleBookCompletion() {
        // C1: achievements are retired; finishing a book still notifies the admin.
        // Send admin notification for completion
        $this->sendAdminNotification(100);
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
        require_once __DIR__ . '/../includes/mail_helper.php';

        $stmt = $this->db->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$this->user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare("SELECT title FROM books WHERE id = ?");
        $stmt->execute([$this->book_id]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);

        $admin_email = aw_admin_email();
        $subject = '📖 Reader Milestone: ' . $percent . '% – ' . $book['title'];
        $body = "<h2>Reader Milestone Reached</h2>";
        $body .= "<p><strong>User:</strong> " . ($user['email'] ?? 'Unknown') . "</p>";
        $body .= "<p><strong>Book:</strong> " . $book['title'] . "</p>";
        $body .= "<p><strong>Progress:</strong> " . $percent . "%</p>";
        sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');
    }
}