<?php
/**
 * Community reputation points.
 *
 * awardReputation() was called from community.php (answer, accept, upvote) and
 * book.php (review) but never defined, so those actions ended in a fatal error.
 * Points are a nice-to-have: any failure here is logged and ignored, so the
 * action that earned the points always completes.
 */

if (!function_exists('awardReputation')) {
    function awardReputation($user_id, $points, $reason = '') {
        global $db;
        $user_id = (int)$user_id;
        $points  = (int)$points;
        if ($user_id <= 0 || $points === 0 || !$db) {
            return false;
        }
        try {
            $stmt = $db->prepare("UPDATE user_reputations SET points = COALESCE(points, 0) + ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?");
            $stmt->execute([$points, $user_id]);
            if ($stmt->rowCount() === 0) {
                $stmt = $db->prepare("INSERT INTO user_reputations (user_id, points, level) VALUES (?, ?, 1)");
                $stmt->execute([$user_id, max(0, $points)]);
            }
            // One level per 100 points, never going down
            $stmt = $db->prepare("UPDATE user_reputations SET level = MAX(COALESCE(level, 1), 1 + (COALESCE(points, 0) / 100)) WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return true;
        } catch (\Throwable $e) {
            error_log("awardReputation failed (user $user_id, $points, $reason): " . $e->getMessage());
            return false;
        }
    }
}
