<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$isLoggedIn = isLoggedIn();
$isAdmin = isAdmin();
$isReader = $isLoggedIn && !$isAdmin;
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        /* ===== LOGO ===== */
        header .container.nav-container {
            max-width: 100%;
            padding-left: 0;
            padding-right: 10px;
        }
        .logo { margin-left: 0; flex-shrink: 0; }
        .logo-img { height: 155px; width: auto; max-width: 100%; display: block; object-fit: contain; }
        @media (max-width: 480px) { .logo-img { height: 120px; max-width: 90%; } }

        /* ===== NAVIGATION ===== */
        .nav-links {
            display: flex;
            align-items: center;
            gap: 8px;
            list-style: none;
            margin: 0;
            padding: 0;
            flex: 1;
            justify-content: center;
        }
        .nav-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
            flex-shrink: 0;
            margin-left: auto;
        }
        .nav-action-icon {
            color: var(--text);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .nav-action-icon:hover { color: var(--rose); background: rgba(219, 161, 162, 0.1); }

        /* ===== MOBILE ===== */
        @media (max-width: 992px) {
            .nav-links {
                display: none; /* Hidden by default */
                flex-direction: column;
                position: fixed;
                top: 0;
                right: 0;
                width: 280px;
                height: 100vh;
                background: var(--card-bg);
                border-left: 1px solid var(--border);
                padding: 80px 24px 24px;
                z-index: 999;
                overflow-y: auto;
            }
            .nav-links.open {
                display: flex !important; /* Show when open */
            }
            .nav-links li { margin: 4px 0; padding: 8px 0; border-bottom: 1px solid var(--border); }
            .nav-links li:last-child { border-bottom: none; }
            .nav-links a { display: block; width: 100%; font-size: 1rem; color: var(--text); }
            .nav-links a:hover { color: var(--rose); }
            .nav-links .nav-separator { display: none; }
            .hamburger {
                display: flex !important;
                flex-direction: column;
                gap: 4px;
                background: transparent;
                border: none;
                cursor: pointer;
                padding: 8px;
            }
            .hamburger span { display: block; width: 24px; height: 2px; background: var(--text); border-radius: 2px; transition: all 0.3s ease; }
            .hamburger.active span:nth-child(1) { transform: rotate(45deg) translate(4px, 4px); }
            .hamburger.active span:nth-child(2) { opacity: 0; }
            .hamburger.active span:nth-child(3) { transform: rotate(-45deg) translate(4px, -4px); }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <nav class="navbar">
            <div class="container nav-container">
                <a href="<?php echo SITE_URL; ?>/index.php" class="logo">
                    <img src="<?php echo SITE_URL; ?>/assets/images/logo.png" alt="AngelWrites" class="logo-img">
                </a>

                <ul class="nav-links" id="navLinks">
                    <?php if (!$isLoggedIn): ?>
                        <li><a href="<?php echo SITE_URL; ?>/index.php">Home</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/books.php">Books</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/poetry.php">Poems</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/blog.php">Blog</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/about.php">About</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/contact.php">Contact</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/login.php">Login</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/register.php">Sign Up</a></li>
                    <?php elseif ($isAdmin): ?>
                        <li><a href="<?php echo SITE_URL; ?>/admin/dashboard.php">Dashboard</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/admin/manage_books.php">📖 Books</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/admin/manage_poems.php">📝 Poems</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/admin/manage_sessions.php">📅 Sessions</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/admin/manage_users.php">👥 Users</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/admin/settings.php">⚙️ Settings</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/logout.php">Logout</a></li>
                    <?php else: ?>
                        <li><a href="<?php echo SITE_URL; ?>/index.php">Home</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/dashboard.php">Dashboard</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/library.php">My Library</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/books.php">Books</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/poetry.php">Poems</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/community.php">Community</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/book_session.php">Book Session</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/profile.php">Profile</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/logout.php">Logout</a></li>
                    <?php endif; ?>
                </ul>

                <div class="nav-actions">
                    <button class="hamburger" id="hamburger" aria-label="Toggle navigation menu">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                </div>
            </div>
        </nav>
    </header>

    <main class="site-main">
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const hamburger = document.getElementById('hamburger');
            const navLinks = document.getElementById('navLinks');

            hamburger.addEventListener('click', function() {
                // Toggle the 'open' class
                navLinks.classList.toggle('open');
                // Also toggle a class on the hamburger for animation
                this.classList.toggle('active');
            });
        });
        </script>