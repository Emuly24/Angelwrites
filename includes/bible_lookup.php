<?php
/**
 * Bible Lookup - Works with the 'bible' table in multiple database files
 */

function getBibleVerse($book, $chapter, $verse, $version = 'KJV') {
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
        return "Database file for $version not found.";
    }
    
    try {
        $db = new PDO('sqlite:' . $bible_db);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Query the 'bible' table
        $stmt = $db->prepare("
            SELECT text 
            FROM bible 
            WHERE book = ? AND chapter = ? AND verse = ?
            LIMIT 1
        ");
        $stmt->execute([$book, $chapter, $verse]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && isset($result['text'])) {
            return $result['text'];
        } else {
            return "Verse not found in $version database.";
        }
    } catch (PDOException $e) {
        return "Database error: " . $e->getMessage();
    }
}
?>