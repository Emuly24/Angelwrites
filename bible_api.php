<?php
// B1: the Bible reader's data endpoint (read-only, GET, JSON). Open to everyone.
//   ?a=versions                      the versions on this server, with credit lines
//   ?a=books&v=KJV                   books of a version (names in its language) with chapter counts
//   ?a=chapter&v=KJV&b=John&c=3      one chapter: headings, psalm title, verses (paragraph starts)
//   ?a=search&v=KJV&q=shepherd       word search, at least 3 letters, at most 50 results
require_once 'includes/config.php';
require_once 'includes/bible_versions.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$a = (string)($_GET['a'] ?? '');
$v = strtoupper((string)($_GET['v'] ?? 'KJV'));
$versions = aw_bible_versions();
if ($a !== 'versions' && !isset($versions[$v])) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'That version is not available.']); exit; }
if ($a === 'versions') {
    header('Cache-Control: public, max-age=3600');
    $out = [];
    foreach ($versions as $code => $x) $out[] = ['code' => $code, 'name' => $x['name'], 'short' => $x['short'], 'lang' => $x['lang'], 'language' => $x['language'], 'scope' => $x['scope'], 'credit' => $x['credit'], 'license' => $x['license']];
    echo json_encode(['ok' => true, 'versions' => $out], JSON_UNESCAPED_UNICODE);
} elseif ($a === 'books') {
    header('Cache-Control: public, max-age=86400');
    echo json_encode(['ok' => true, 'books' => aw_bible_books($v)], JSON_UNESCAPED_UNICODE);
} elseif ($a === 'chapter') {
    $ch = aw_bible_chapter($v, (string)($_GET['b'] ?? ''), (int)($_GET['c'] ?? 0));
    if (!$ch) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'This chapter is not in this version.']); exit; }
    header('Cache-Control: public, max-age=86400');
    echo json_encode(['ok' => true] + $ch, JSON_UNESCAPED_UNICODE);
} elseif ($a === 'search') {
    $q = (string)($_GET['q'] ?? '');
    if (mb_strlen(trim($q)) < 3) { echo json_encode(['ok' => false, 'error' => 'Type at least 3 letters.']); exit; }
    echo json_encode(['ok' => true, 'results' => aw_bible_search($v, $q)], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Unknown request.']);
}
