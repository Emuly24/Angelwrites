<?php
/**
 * Bible Lookup - Fetch a full chapter from local SQLite databases.
 */

function getBibleText($book, $chapter, $version = 'KJV') {
    $version_map = [
        'KJV'  => 'bible_KJV.db',
        'NIV'  => 'bible_NIV.db',
        'ESV'  => 'bible_ESV.db',
        'NASB' => 'bible_NASB.db',
        'NKJV' => 'bible_NKJV.db',
        'AMP'  => 'bible_AMP.db',
        'ASV'  => 'bible_ASV.db',
        'WEB'  => 'bible_WEB.db'
    ];
    
    $db_file = $version_map[$version] ?? 'bible_KJV.db';
    $bible_db = __DIR__ . '/../assets/bible/' . $db_file;
    
    if (!file_exists($bible_db)) {
        return ["error" => "Database file for $version not found."];
    }
    
    try {
        $db = new PDO('sqlite:' . $bible_db);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Fetch the whole chapter
        $stmt = $db->prepare("
            SELECT verse, text 
            FROM bible 
            WHERE book = ? AND chapter = ? 
            ORDER BY verse ASC
        ");
        $stmt->execute([$book, $chapter]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($results) === 0) {
            return ["error" => "No verses found for $book $chapter."];
        }
        
        return $results;
        
    } catch (PDOException $e) {
        return ["error" => "Database error: " . $e->getMessage()];
    }
}

// Handle AJAX request
if (isset($_GET['book']) && isset($_GET['chapter'])) {
    header('Content-Type: application/json');
    
    $book = $_GET['book'];
    $chapter = (int)$_GET['chapter'];
    $version = $_GET['version'] ?? 'KJV';
    
    $result = getBibleText($book, $chapter, $version);
    
    if (isset($result['error'])) {
        echo json_encode(['success' => false, 'error' => $result['error']]);
    } else {
        echo json_encode(['success' => true, 'data' => $result]);
    }
    exit;
}
?>