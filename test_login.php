<?php
echo "Login test starting...<br>";

require_once 'includes/config.php';
echo "Config loaded.<br>";

require_once 'includes/db.php';
echo "DB loaded.<br>";

require_once 'includes/auth.php';
echo "Auth loaded.<br>";

echo "All includes loaded. Login page should work.";
?>