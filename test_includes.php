<?php
echo "Testing includes...<br><br>";

echo "1. Testing config.php... ";
if (file_exists('includes/config.php')) {
    require_once 'includes/config.php';
    echo "✅ loaded.<br>";
} else {
    echo "❌ NOT found.<br>";
}

echo "2. Testing db.php... ";
if (file_exists('includes/db.php')) {
    $db = require_once 'includes/db.php';
    if ($db instanceof PDO) {
        echo "✅ loaded and connected.<br>";
    } else {
        echo "⚠️ loaded but not returning PDO object.<br>";
    }
} else {
    echo "❌ NOT found.<br>";
}

echo "3. Testing auth.php... ";
if (file_exists('includes/auth.php')) {
    require_once 'includes/auth.php';
    echo "✅ loaded.<br>";
} else {
    echo "❌ NOT found.<br>";
}

echo "4. Testing header.php... ";
if (file_exists('includes/header.php')) {
    require_once 'includes/header.php';
    echo "✅ loaded.<br>";
} else {
    echo "❌ NOT found.<br>";
}

echo "<br>If you see '❌ NOT found', check the file path and permissions.";
?>