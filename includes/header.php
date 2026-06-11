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

// ===== FETCH UNREAD NOTIFICATIONS COUNT (if user logged in) =====
$unreadNotifications = 0;
if ($isLoggedIn) {
    $user_id = $_SESSION['user_id'];
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        $unreadNotifications = $stmt->fetchColumn();
    } catch (Exception $e) {
        $unreadNotifications = 0;
    }
}
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
    
    <!-- Meta tags -->
    <meta name="description" content="<?php echo isset($metaDescription) ? $metaDescription : 'AngelWrites — Christian writing and community platform by Angella Bottoman.'; ?>">
    <meta name="keywords" content="AngelWrites, Christian writing, faith, poetry, books, reflections, Angella Bottoman">
    <meta name="robots" content="index, follow">
    <meta property="og:title" content="<?php echo isset($pageTitle) ? $pageTitle . ' - ' . SITE_NAME : SITE_NAME; ?>">
    <meta property="og:description" content="AngelWrites — Christian writing and community platform by Angella Bottoman.">
    <meta property="og:url" content="<?php echo SITE_URL . $_SERVER['REQUEST_URI']; ?>">
    <meta name="twitter:card" content="summary_large_image">
    
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="manifest" href="<?php echo SITE_URL; ?>/manifest.json">
    <meta name="theme-color" content="#DBA1A2">
    
    <style>
        .skip-link {
            position: absolute;
            top: -40px;
            left: 0;
            background: var(--rose);
            color: white;
            padding: 8px 16px;
            z-index: 9999;
            border-radius: 0 0 8px 0;
            transition: top 0.2s ease;
            text-decoration: none;
            font-weight: 600;
        }
        .skip-link:focus { top: 0; }
    </style>
</head>
<body>
    <a href="#mainContent" class="skip-link">Skip to main content</a>

    <header class="site-header">
        <nav class="navbar" role="navigation" aria-label="Main navigation">
            <div class="container nav-container">
                <!-- ===== RESTORED LOGO ===== -->
                <a href="<?php echo SITE_URL; ?>/index.php" class="logo" aria-label="AngelWrites – Home">
                    <img src="<?php echo SITE_URL; ?>/assets/images/logo.png" alt="AngelWrites – Christian writing and community" class="logo-img">
                </a>

                <!-- Navigation Links (desktop) -->
                <ul class="nav-links" id="navLinks" role="menubar">
                    <?php if (!$isLoggedIn): ?>
                        <!-- Guest menu -->
                        <li role="none"><a href="<?php echo SITE_URL; ?>/index.php" class="<?php echo $currentPage === 'index.php' ? 'active' : ''; ?>" role="menuitem">Home</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/books.php" class="<?php echo $currentPage === 'books.php' ? 'active' : ''; ?>" role="menuitem">Books</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/poetry.php" class="<?php echo $currentPage === 'poetry.php' ? 'active' : ''; ?>" role="menuitem">Poems</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/blog.php" class="<?php echo $currentPage === 'blog.php' ? 'active' : ''; ?>" role="menuitem">Blog</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/about.php" class="<?php echo $currentPage === 'about.php' ? 'active' : ''; ?>" role="menuitem">About</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/contact.php" class="<?php echo $currentPage === 'contact.php' ? 'active' : ''; ?>" role="menuitem">Contact</a></li>
                        <li class="nav-separator" role="separator">|</li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/login.php" class="btn-login" role="menuitem"><i class="fas fa-sign-in-alt"></i> Login</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/register.php" class="btn-signup" role="menuitem">Sign Up</a></li>
                    <?php elseif ($isAdmin): ?>
                        <!-- Admin menu -->
                        <li role="none"><a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="<?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>" role="menuitem"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/admin/manage_books.php" role="menuitem">📖 Books</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" role="menuitem">📝 Poems</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/admin/manage_sessions.php" role="menuitem">📅 Sessions</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/admin/manage_users.php" role="menuitem">👥 Users</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/admin/settings.php" role="menuitem">⚙️ Settings</a></li>
                        <li class="nav-separator" role="separator">|</li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/logout.php" class="btn-logout" role="menuitem"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    <?php else: ?>
                        <!-- Reader menu -->
                        <li role="none"><a href="<?php echo SITE_URL; ?>/index.php" class="<?php echo $currentPage === 'index.php' ? 'active' : ''; ?>" role="menuitem">Home</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/dashboard.php" class="<?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>" role="menuitem"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/library.php" class="<?php echo $currentPage === 'library.php' ? 'active' : ''; ?>" role="menuitem"><i class="fas fa-book-reader"></i> My Library</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/books.php" class="<?php echo $currentPage === 'books.php' ? 'active' : ''; ?>" role="menuitem">Books</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/poetry.php" class="<?php echo $currentPage === 'poetry.php' ? 'active' : ''; ?>" role="menuitem">Poems</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/community.php" class="<?php echo $currentPage === 'community.php' ? 'active' : ''; ?>" role="menuitem">Community</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/book_session.php" class="<?php echo $currentPage === 'book_session.php' ? 'active' : ''; ?>" role="menuitem">Book Session</a></li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/profile.php" class="<?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>" role="menuitem">Profile</a></li>
                        <li class="nav-separator" role="separator">|</li>
                        <li role="none"><a href="<?php echo SITE_URL; ?>/logout.php" class="btn-logout" role="menuitem"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    <?php endif; ?>
                </ul>

                <!-- Right-side actions -->
                <div class="nav-actions">
                    <!-- Search -->
                    <a href="<?php echo SITE_URL; ?>/search_results.php" class="nav-action-icon" aria-label="Search content">
                        <i class="fas fa-search"></i>
                    </a>
                    
                    <!-- Bible quick access -->
                    <a href="<?php echo SITE_URL; ?>/bible_reader.php" class="nav-action-icon" aria-label="Open Bible reader">
                        <i class="fas fa-book-bible"></i>
                    </a>
                    
                    <!-- Notification bell (logged-in users only) -->
                    <?php if ($isLoggedIn): ?>
                        <a href="<?php echo SITE_URL; ?>/notifications.php" class="nav-action-icon" aria-label="Notifications">
                            <i class="fas fa-bell"></i>
                            <?php if ($unreadNotifications > 0): ?>
                                <span class="notification-badge" aria-label="<?php echo $unreadNotifications; ?> unread notifications"><?php echo $unreadNotifications; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                    
                    <!-- Theme toggle -->
                    <button class="nav-action-icon theme-toggle" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-moon"></i>
                    </button>

                    <!-- Hamburger menu (mobile ONLY) -->
                    <button class="hamburger" id="hamburger" aria-label="Toggle navigation menu" role="button" tabindex="0" aria-expanded="false">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                </div>
            </div>
        </nav>

        <!-- Overlay for hamburger menu -->
        <div class="menu-overlay" id="menuOverlay"></div>
    </header>

    <!-- Start of main content wrapper -->
    <main class="site-main" id="mainContent">
        
        <!-- Global Notification Area -->
        <?php if (isset($_SESSION['notification'])): ?>
            <div class="global-notification">
                <?php echo $_SESSION['notification']; ?>
                <?php unset($_SESSION['notification']); ?>
            </div>
        <?php endif; ?>
        
        <!-- Breadcrumbs (optional) -->
        <?php if (isset($breadcrumbs) && is_array($breadcrumbs)): ?>
            <div class="breadcrumbs">
                <div class="container">
                    <?php foreach ($breadcrumbs as $crumb): ?>
                        <?php if (isset($crumb['url'])): ?>
                            <a href="<?php echo $crumb['url']; ?>"><?php echo $crumb['label']; ?></a> <span class="breadcrumb-sep">›</span>
                        <?php else: ?>
                            <span><?php echo $crumb['label']; ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Inline styles for header components -->
        <style>
            /* Global Notification */
            .global-notification {
                background: var(--rose);
                color: white;
                text-align: center;
                padding: 8px 16px;
                font-size: 0.9rem;
                position: sticky;
                top: 0;
                z-index: 1001;
            }
            .global-notification a {
                color: white;
                text-decoration: underline;
            }
            
            /* Breadcrumbs */
            .breadcrumbs {
                background: var(--vanilla);
                padding: 8px 0;
                border-bottom: 1px solid var(--border);
                font-size: 0.85rem;
            }
            .breadcrumbs a {
                color: var(--text);
                text-decoration: none;
            }
            .breadcrumbs a:hover {
                color: var(--rose);
            }
            .breadcrumb-sep {
                color: var(--text-light);
                margin: 0 4px;
            }
            
            /* Nav Actions */
            .nav-actions {
                display: flex;
                align-items: center;
                gap: 8px;
                flex-wrap: wrap;
                justify-content: flex-end;
                flex-shrink: 0;
            }
            .nav-action-icon {
                color: var(--text);
                transition: color var(--transition);
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 36px;
                height: 36px;
                border-radius: 50%;
                cursor: pointer;
            }
            .nav-action-icon:hover {
                color: var(--rose);
                background: rgba(219, 161, 162, 0.1);
            }
            
            /* Notification badge */
            .notification-badge {
                position: absolute;
                top: -2px;
                right: -2px;
                background: var(--rose);
                color: white;
                font-size: 0.6rem;
                font-weight: 700;
                padding: 2px 6px;
                border-radius: 10px;
                min-width: 16px;
                text-align: center;
                line-height: 1.4;
            }
            
            /* ===== FIX: Hamburger hidden on desktop ===== */
            .hamburger {
                display: none; /* Hidden by default, shown only on mobile via media query */
                flex-direction: column;
                gap: 4px;
                background: transparent;
                border: none;
                cursor: pointer;
                padding: 8px;
                border-radius: 8px;
                transition: background 0.2s;
            }
            .hamburger:hover {
                background: rgba(219, 161, 162, 0.1);
            }
            .hamburger span {
                display: block;
                width: 24px;
                height: 2px;
                background: var(--text);
                border-radius: 2px;
                transition: all 0.3s ease;
            }
            .hamburger.active span:nth-child(1) {
                transform: rotate(45deg) translate(4px, 4px);
            }
            .hamburger.active span:nth-child(2) {
                opacity: 0;
            }
            .hamburger.active span:nth-child(3) {
                transform: rotate(-45deg) translate(4px, -4px);
            }
            
            /* ===== FIX: Only show hamburger on mobile ===== */
            @media (max-width: 992px) {
                .hamburger {
                    display: flex;
                }
                .nav-links {
                    display: none;
                    flex-direction: column;
                    position: fixed;
                    top: 0;
                    right: 0;
                    width: 280px;
                    height: 100vh;
                    background: var(--card-bg);
                    border-left: 1px solid var(--border);
                    padding: 80px 24px 24px;
                    box-shadow: -4px 0 20px rgba(0,0,0,0.1);
                    z-index: 999;
                    overflow-y: auto;
                }
                .nav-links.open {
                    display: flex;
                }
                .nav-links li {
                    margin: 4px 0;
                }
                .nav-links .nav-separator {
                    display: none;
                }
                .menu-overlay {
                    display: none;
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0,0,0,0.5);
                    z-index: 998;
                }
                .menu-overlay.open {
                    display: block;
                }
            }
            
            @media (max-width: 480px) {
                .nav-actions {
                    gap: 4px;
                }
                .nav-action-icon {
                    width: 32px;
                    height: 32px;
                    font-size: 0.9rem;
                }
            }
        </style>

        <!-- ===== FIXED Theme Toggle and Mobile Menu JavaScript ===== -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ===== THEME TOGGLE =====
            const themeToggle = document.getElementById('themeToggle');
            const html = document.documentElement;

            function getCurrentTheme() {
                let theme = html.getAttribute('data-theme');
                if (theme) return theme;
                const match = document.cookie.match(/theme=([^;]+)/);
                return match ? match[1] : 'light';
            }

            function updateThemeIcon(theme) {
                const icon = themeToggle.querySelector('i');
                if (theme === 'dark') {
                    icon.className = 'fas fa-sun';
                } else {
                    icon.className = 'fas fa-moon';
                }
            }

            function setTheme(theme) {
                html.setAttribute('data-theme', theme);
                document.cookie = 'theme=' + theme + '; path=/; max-age=' + (365 * 24 * 60 * 60);
                updateThemeIcon(theme);
            }

            const currentTheme = getCurrentTheme();
            setTheme(currentTheme);

            if (themeToggle) {
                themeToggle.addEventListener('click', function() {
                    const current = html.getAttribute('data-theme') || 'light';
                    const newTheme = current === 'dark' ? 'light' : 'dark';
                    setTheme(newTheme);
                });
            }

            // ===== MOBILE MENU TOGGLE =====
            const hamburger = document.getElementById('hamburger');
            const navLinks = document.getElementById('navLinks');
            const overlay = document.getElementById('menuOverlay');
            const body = document.body;

            function toggleMenu() {
                const isOpen = navLinks.classList.toggle('open');
                overlay.classList.toggle('open', isOpen);
                hamburger.classList.toggle('active', isOpen);
                hamburger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                body.style.overflow = isOpen ? 'hidden' : '';
            }

            function closeMenu() {
                navLinks.classList.remove('open');
                overlay.classList.remove('open');
                hamburger.classList.remove('active');
                hamburger.setAttribute('aria-expanded', 'false');
                body.style.overflow = '';
            }

            if (hamburger) {
                hamburger.addEventListener('click', toggleMenu);
            }
            if (overlay) {
                overlay.addEventListener('click', closeMenu);
            }

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && navLinks.classList.contains('open')) {
                    closeMenu();
                }
            });

            window.addEventListener('resize', function() {
                if (window.innerWidth > 992 && navLinks.classList.contains('open')) {
                    closeMenu();
                }
            });
        });
        </script>