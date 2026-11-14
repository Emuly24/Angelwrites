<?php
// The old /img/N address (still in links shared before) now gives the same 1200x630 JPEG as
// og_image.php, so earlier shares keep a picture.
$_GET['t'] = 'poem';
require __DIR__ . '/og_image.php';
