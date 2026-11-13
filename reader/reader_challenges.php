<?php
// ============================================================
//  READER_CHALLENGES.PHP: retired (C1, PROGRAM_PLAN.md wish C)
//
//  Monthly reading challenges ("read 30 pages this month") made members feel they had failed
//  when they missed the number. The module is gone. This file stays so that an old link or a
//  cached page that still asks for it gets a clear answer instead of an error.
// ============================================================
if (isset($_POST['action']) || isset($_GET['action'])) {
    header('Content-Type: application/json');
    http_response_code(410);
    echo json_encode(['success' => false, 'error' => 'Reading challenges have been retired.']);
    exit;
}
