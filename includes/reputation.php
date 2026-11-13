<?php
/**
 * C1: community reputation points are retired (PROGRAM_PLAN.md, wish C and decision 8).
 *
 * Points and levels made members compare themselves with each other. Helpful marks and accepted
 * answers stay as part of Q&A; nothing is scored or ranked any more. awardReputation() is kept as
 * an empty function so the places that used to call it keep working unchanged.
 */

if (!function_exists('awardReputation')) {
    function awardReputation($user_id, $points, $reason = '') {
        return null; // retired: no points are recorded
    }
}
