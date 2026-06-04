<?php
/**
 * One-time installer for extraction libraries
 * Run this once from your browser, then delete it.
 */

set_time_limit(120);

$base_dir = __DIR__;

// ===== 1. Download PHPSpreadsheet (for DOCX) =====
echo "Downloading PHPSpreadsheet...<br>";
$zip_file = $base_dir . '/phpspreadsheet.zip';
file_put_contents($zip_file, fopen('https://github.com/PHPOffice/PhpSpreadsheet/archive/refs/heads/master.zip', 'r'));
$zip = new ZipArchive;
if ($zip->open($zip_file) === TRUE) {
    $zip->extractTo($base_dir . '/libs/');
    $zip->close();
    unlink($zip_file);
    echo "✅ PHPSpreadsheet extracted.<br>";
} else {
    echo "❌ Failed to extract PHPSpreadsheet.<br>";
}

// ===== 2. Download smalot/pdfparser (for PDF) =====
echo "Downloading PDFParser...<br>";
$zip_file = $base_dir . '/pdfparser.zip';
file_put_contents($zip_file, fopen('https://github.com/smalot/pdfparser/archive/refs/heads/master.zip', 'r'));
$zip = new ZipArchive;
if ($zip->open($zip_file) === TRUE) {
    $zip->extractTo($base_dir . '/libs/');
    $zip->close();
    unlink($zip_file);
    echo "✅ PDFParser extracted.<br>";
} else {
    echo "❌ Failed to extract PDFParser.<br>";
}

echo "<br>🎉 Extraction libraries installed. Delete this file now.";
?>