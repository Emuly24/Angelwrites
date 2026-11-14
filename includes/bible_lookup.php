<?php
function getBibleText($book, $chapter, $verse_start = 1, $verse_end = 0, $version = 'KJV') {
    // B1: versions come from the registry (includes/bible_versions.php)
    require_once __DIR__ . '/bible_versions.php';
    if (!isset(aw_bible_versions()[$version])) {
        return ["error" => "The $version translation is not available."];
    }
    $bible_db = aw_bible_file($version);

    try {
        $db = new PDO('sqlite:' . $bible_db);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // If $verse_end is 0, fetch the whole chapter
        if ($verse_end == 0) {
            $stmt = $db->prepare("
                SELECT verse, text 
                FROM bible 
                WHERE book = ? AND chapter = ? 
                ORDER BY verse ASC
            ");
            $stmt->execute([$book, $chapter]);
        } else {
            // Fetch a single verse or a range
            $stmt = $db->prepare("
                SELECT verse, text 
                FROM bible 
                WHERE book = ? AND chapter = ? AND verse BETWEEN ? AND ?
                ORDER BY verse ASC
            ");
            $stmt->execute([$book, $chapter, $verse_start, $verse_end]);
        }
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($results) === 0) {
            return ["error" => "No verses found for $book $chapter."];
        }
        return $results;
        
    } catch (PDOException $e) {
        return ["error" => "Database error: " . $e->getMessage()];
    }
}

if (isset($_GET['book']) && isset($_GET['chapter'])) {
    header('Content-Type: application/json');
    $book = $_GET['book'];
    $chapter = (int)$_GET['chapter'];
    $verse_start = isset($_GET['verse_start']) ? (int)$_GET['verse_start'] : 1;
    $verse_end = isset($_GET['verse_end']) ? (int)$_GET['verse_end'] : 0;
    $version = $_GET['version'] ?? 'KJV';
    
    $result = getBibleText($book, $chapter, $verse_start, $verse_end, $version);
    
    if (isset($result['error'])) {
        echo json_encode(['success' => false, 'error' => $result['error']]);
    } else {
        echo json_encode(['success' => true, 'data' => $result]);
    }
    exit;
}
?>