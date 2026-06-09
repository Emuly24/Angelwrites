<?php
/**
 * Load environment variables from .env file
 */
function loadEnv($file = '.env') {
    if (!file_exists($file)) return false;
    
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) continue;
        
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        
        // Remove quotes if present
        if (strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) {
            $value = substr($value, 1, -1);
        } elseif (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1) {
            $value = substr($value, 1, -1);
        }
        
        // Only set if not already set (allows overriding with getenv)
        if (!getenv($key)) {
            putenv("$key=$value");
        }
    }
    return true;
}

// Load environment variables
loadEnv(__DIR__ . '/../.env');