<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load configuration and database
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Determine current role (guest, reader, admin)
$isLoggedIn = isLoggedIn();
$isAdmin = isAdmin();
$isReader = $isLoggedIn && !$isAdmin;
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $_COOKIE['theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
</head>
<body>
    <header class="site-header">
        <nav class="navbar" role="navigation" aria-label="Main navigation">
            <div class="container nav-container">
                <!-- Logo -->
                <a href="<?php echo SITE_URL; ?>/index.php" class="logo">
                    <img src="<?php echo SITE_URL; ?>/assets/images/logo.png" alt="AngelWrites Logo" class="logo-img">
                </a>

                <!-- Navigation Links (desktop) -->
                <ul class="nav-links" id="navLinks">
                    <?php if (!$isLoggedIn): ?>
                        <!-- Guest menu -->
                        <li><a href="<?php echo SITE_URL; ?>/index.php" class="<?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">Home</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/books.php" class="<?php echo $currentPage === 'books.php' ? 'active' : ''; ?>">Books</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/poetry.php" class="<?php echo $currentPage === 'poetry.php' ? 'active' : ''; ?>">Poems</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/blog.php" class="<?php echo $currentPage === 'blog.php' ? 'active' : ''; ?>">Blog</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/about.php" class="<?php echo $currentPage === 'about.php' ? 'active' : ''; ?>">About</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/contact.php" class="<?php echo $currentPage === 'contact.php' ? 'active' : ''; ?>">Contact</a></li>
                        <li class="nav-separator">|</li>
                        <li><a href="<?php echo SITE_URL; ?>/login.php" class="btn-login"><i class="fas fa-sign-in-alt"></i> Login</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/register.php" class="btn-signup">Sign Up</a></li>
                    <?php elseif ($isAdmin): ?>
                        <!-- Admin menu -->
                        <li><a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="<?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/admin/manage_books.php">📖 Books</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/admin/manage_poems.php">📝 Poems</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/admin/manage_sessions.php">📅 Sessions</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/admin/manage_users.php">👥 Users</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/admin/settings.php">⚙️ Settings</a></li>
                        <li class="nav-separator">|</li>
                        <li><a href="<?php echo SITE_URL; ?>/logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    <?php else: ?>
                        <!-- Reader menu -->
                        <li><a href="<?php echo SITE_URL; ?>/index.php" class="<?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">Home</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/dashboard.php" class="<?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/library.php" class="<?php echo $currentPage === 'library.php' ? 'active' : ''; ?>"><i class="fas fa-book-reader"></i> My Library</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/books.php" class="<?php echo $currentPage === 'books.php' ? 'active' : ''; ?>">Books</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/poetry.php" class="<?php echo $currentPage === 'poetry.php' ? 'active' : ''; ?>">Poems</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/community.php" class="<?php echo $currentPage === 'community.php' ? 'active' : ''; ?>">Community</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/book_session.php" class="<?php echo $currentPage === 'book_session.php' ? 'active' : ''; ?>">Book Session</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/profile.php" class="<?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>">Profile</a></li>
                        <li class="nav-separator">|</li>
                        <li><a href="<?php echo SITE_URL; ?>/logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    <?php endif; ?>
                </ul>

                <!-- Right-side actions -->
                <div class="nav-actions">
                    <!-- Bible quick access - NOW A LINK TO THE FULL READER -->
                    <a href="<?php echo SITE_URL; ?>/bible_reader.php" class="bible-toggle" aria-label="Open Bible">
                        <i class="fas fa-book-bible"></i>
                    </a>
                    
                    <!-- Theme toggle -->
                    <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-moon"></i>
                    </button>

                    <!-- Hamburger menu (mobile) -->
                    <div class="hamburger" id="hamburger" aria-label="Toggle navigation menu" role="button" tabindex="0">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Overlay for hamburger menu (closes when clicked outside) -->
        <div class="menu-overlay" id="menuOverlay"></div>
    </header>

    <!-- Start of main content wrapper -->
    <main class="site-main">
        <style>
            /* ===== FIX FOR HEADER OVERLAP ===== */
.nav-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.nav-actions .bible-toggle {
    color: var(--text);
    transition: color var(--transition);
}

.nav-actions .bible-toggle:hover {
    color: var(--rose);
}

@media (max-width: 480px) {
    .nav-actions {
        gap: 6px;
    }
}
        </style>