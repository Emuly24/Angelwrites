<?php
/**
 * C1: reader gamification is retired (PROGRAM_PLAN.md, wish C and decision 8).
 *
 * XP, levels, streaks, achievements and challenges made people feel low when they missed a
 * target. The functions below keep their names and return neutral values, so every caller in the
 * reader keeps working, and nothing is written to the old gamification tables (which the admin can
 * now delete from Admin > Database Backup).
 */

if (!function_exists('updateUserStats')) {
    function updateUserStats($user_id, $book_id = 0, $action = 'page_read', $amount = 1) { return []; }
}
if (!function_exists('updateUserLevel')) {
    function updateUserLevel($user_id) { return null; }
}
if (!function_exists('checkAchievements')) {
    function checkAchievements($user_id) { return []; }
}
if (!function_exists('getReaderLevel')) {
    function getReaderLevel($user_id) { return ['level' => 1, 'name' => '', 'xp' => 0, 'xp_to_next' => 0, 'progress' => 0]; }
}
if (!function_exists('renderLevelBadge')) {
    function renderLevelBadge($user_id) { return ''; }
}
if (!function_exists('renderAchievementsPanel')) {
    function renderAchievementsPanel($user_id) { return ''; }
}
if (!function_exists('getUserStats')) {
    function getUserStats($user_id) { return []; }
}
if (!function_exists('renderAchievementToastJS')) {
    function renderAchievementToastJS() { /* nothing to show */ }
}
