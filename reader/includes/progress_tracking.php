<?php
// ============================================================
//  PROGRESS_TRACKING.PHP – Save and restore reading progress
// ============================================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

class ProgressTracker {
    private $db;
    private $user_id;
    private $book_id;

    public function __construct($user_id, $book_id) {
        $this->db = $GLOBALS['db'];
        $this->user_id = $user_id;
        $this->book_id = $book_id;
    }

    // Get current progress
    public function getProgress() {
        if (!$this->user_id) return null;

        $stmt = $this->db->prepare("SELECT * FROM reading_progress WHERE user_id = ? AND book_id = ?");
        $stmt->execute([$this->user_id, $this->book_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Save progress
    public function saveProgress($offset, $chapter, $percent) {
        if (!$this->user_id) return false;

        $stmt = $this->db->prepare("SELECT id FROM reading_progress WHERE user_id = ? AND book_id = ?");
        $stmt->execute([$this->user_id, $this->book_id]);
        $exists = $stmt->fetch();

        if ($exists) {
            $stmt = $this->db->prepare("UPDATE reading_progress SET position_offset = ?, position_section = ?, progress_percent = ?, last_accessed_at = CURRENT_TIMESTAMP WHERE user_id = ? AND book_id = ?");
            $stmt->execute([$offset, $chapter, $percent, $this->user_id, $this->book_id]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO reading_progress (user_id, book_id, position_offset, position_section, progress_percent) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$this->user_id, $this->book_id, $offset, $chapter, $percent]);
        }

        // Update streak
        $this->updateStreak();

        // Check milestones
        if ($percent >= 50 && $percent < 100) {
            $this->checkMilestone(50);
        }
        if ($percent >= 100) {
            $this->checkMilestone(100);
        }

        return true;
    }

    // Update reading streak
    private function updateStreak() {
        $today = date('Y-m-d');

        $stmt = $this->db->prepare("SELECT * FROM reading_streaks WHERE user_id = ?");
        $stmt->execute([$this->user_id]);
        $streak = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$streak) {
            $stmt = $this->db->prepare("INSERT INTO reading_streaks (user_id, current_streak, longest_streak, last_read_date) VALUES (?, 1, 1, ?)");
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
        } else {
            $current = 1;
        }

        $stmt = $this->db->prepare("UPDATE reading_streaks SET current_streak = ?, longest_streak = ?, last_read_date = ? WHERE user_id = ?");
        $stmt->execute([$current, $longest, $today, $this->user_id]);
    }

    // Check milestones
    private function checkMilestone($percent) {
        $stmt = $this->db->prepare("SELECT id FROM reader_admin_notifications WHERE user_id = ? AND book_id = ? AND event_type = ?");
        $stmt->execute([$this->user_id, $this->book_id, 'progress_' . $percent]);
        if ($stmt->fetch()) return;

        $stmt = $this->db->prepare("INSERT INTO reader_admin_notifications (user_id, book_id, event_type) VALUES (?, ?, ?)");
        $stmt->execute([$this->user_id, $this->book_id, 'progress_' . $percent]);

        // Send admin email
        $this->sendAdminNotification($percent);
    }

    // Send admin notification
    private function sendAdminNotification($percent) {
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