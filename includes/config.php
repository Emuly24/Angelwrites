<?php
require_once __DIR__ . '/load_env.php';
// Site Configuration
define('SITE_NAME', getenv('SITE_NAME'));
define('UPLOAD_PATH', getenv('UPLOAD_PATH'));
define('BIBLE_PATH', getenv('BIBLE_PATH'));
define('ADMIN_EMAIL', getenv('ADMIN_EMAIL'));
define('DB_PATH', __DIR__ . '/../data/site.db');
define('SITE_URL', 'https://angelwrites.gt.tc');
?>