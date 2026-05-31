<?php
function getBibleText($book, $chapter, $verse_start = 1, $verse_end = 0, $version = 'KJV') {
    $version_map = [
        'AMP'  => 'bible_AMP.db',
        'ASV'  => 'bible_ASV.db',
        'ESV'  => 'bible_ESV.db',
        'KJV'  => 'bible_KJV.db',
        'NASB' => 'bible_NASB.db',
        'NIV'  => 'bible_NIV.db',
        'NKJV' => 'bible_NKJV.db',
        'WEB'  => 'bible_WEB.db',
    ];
    
    $db_file = $version_map[$version] ?? 'bible_KJV.db';
    $bible_db = __DIR__ . '/../assets/bible/' . $db_file;
    
    if (!file_exists($bible_db)) {
        return ["error" => "Database file for $version not found."];
    }
    
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