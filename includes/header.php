<?php
header("Cache-Control: no-cache, must-revalidate");
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$isLoggedIn = isLoggedIn();
$isAdmin = isAdmin();
$isReader = $isLoggedIn && !$isAdmin;
$currentPage = basename($_SERVER['PHP_SELF']);

// ===== FETCH UNREAD NOTIFICATIONS =====
$unreadNotifications = 0;
$latestNotifications = [];
if ($isLoggedIn) {
    $user_id = $_SESSION['user_id'];
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        $unreadNotifications = $stmt->fetchColumn();
        
        $stmt = $db->prepare("SELECT id, title, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 3");
        $stmt->execute([$user_id]);
        $latestNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $unreadNotifications = 0;
        $latestNotifications = [];
    }
}
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
    <link rel="icon" type="image/x-icon" href="<?php echo SITE_URL; ?>/favicon.ico">

    <style>
        /* ===== SKIP LINK ===== */
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

        /* ===== LOGO ===== */
        header .container.nav-container {
            max-width: 100% !important;
            padding-left: 0 !important;
            padding-right: 10px !important;
        }
        .logo {
            margin-left: 0 !important;
            padding-left: 0 !important;
            flex-shrink: 0;
        }
        .logo-img {
            height: 155px !important; 
            width: auto !important;
            max-width: 100%;
            display: block;
            object-fit: contain;
        }
        @media (max-width: 480px) {
            .logo-img {
                height: 120px !important;
                max-width: 90% !important;
            }
        }

        /* ===== DESKTOP NAVIGATION (displayed normally) ===== */
        .nav-links {
            display: flex !important;
            align-items: center;
            gap: 8px;
            list-style: none;
            margin: 0;
            padding: 0;
            flex: 1;
            justify-content: center;
        }
        .nav-links li {
            margin: 0;
            padding: 0;
            border-bottom: none;
        }
        .nav-links a {
            padding: 6px 12px;
            font-size: 0.95rem;
            color: var(--text);
            text-decoration: none;
            transition: color 0.2s;
            border-radius: 6px;
        }
        .nav-links a:hover {
            color: var(--rose);
            background: rgba(219, 161, 162, 0.08);
        }
        .nav-links a.active {
            color: var(--rose);
            font-weight: 600;
        }
        .nav-links .nav-separator {
            display: inline;
            color: var(--border);
            padding: 0 4px;
        }

        /* ===== HAMBURGER (hidden on desktop) ===== */
        .hamburger {
            display: none !important;
        }

        /* ===== NAV ACTIONS ===== */
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
            position: relative;
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
            background: none;
            border: none;
        }
        .nav-action-icon:hover {
            color: var(--rose);
            background: rgba(219, 161, 162, 0.1);
        }

        /* ===== NOTIFICATION BADGE ===== */
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

        /* ===== NOTIFICATION DROPDOWN ===== */
        .notification-wrapper {
            position: relative;
        }
        .notification-dropdown {
            position: absolute;
            top: 130%;
            right: 0;
            width: 280px;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px;
            box-shadow: var(--shadow-hover);
            display: none;
            z-index: 1002;
        }
        .notification-dropdown.open {
            display: block;
        }
        .notification-dropdown .notif-item {
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
            font-size: 0.85rem;
        }
        .notification-dropdown .notif-title {
            font-weight: 600;
        }
        .notification-dropdown .notif-date {
            color: var(--text-light);
            font-size: 0.75rem;
        }
        .notification-dropdown .view-all {
            display: block;
            text-align: center;
            margin-top: 8px;
            color: var(--rose);
            font-weight: 500;
        }

        /* ===== SEARCH DROPDOWN ===== */
        .search-wrapper {
            position: relative;
        }
        .search-dropdown {
            position: absolute;
            top: 130%;
            right: 0;
            width: 300px;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px;
            box-shadow: var(--shadow-hover);
            display: none;
            z-index: 1002;
        }
        .search-dropdown.open {
            display: flex;
            gap: 8px;
        }
        .search-dropdown input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.9rem;
            background: var(--input-bg);
            color: var(--text);
        }
        .search-dropdown input:focus {
            outline: none;
            border-color: var(--rose);
            box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
        }
        .search-dropdown button {
            background: var(--rose);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0 12px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .search-dropdown button:hover {
            background: var(--rose-dark);
        }

        /* ===== USER DROPDOWN ===== */
        .user-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .user-wrapper:hover {
            background: rgba(219, 161, 162, 0.1);
        }
        .user-dropdown {
            position: absolute;
            top: 130%;
            right: 0;
            width: 160px;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 8px 0;
            box-shadow: var(--shadow-hover);
            display: none;
            z-index: 1002;
        }
        .user-dropdown.open {
            display: block;
        }
        .user-dropdown a {
            display: block;
            padding: 8px 16px;
            color: var(--text);
            text-decoration: none;
            transition: background 0.2s;
            font-size: 0.9rem;
        }
        .user-dropdown a:hover {
            background: rgba(219, 161, 162, 0.1);
        }
        .user-dropdown hr {
            margin: 4px 0;
            border: 0;
            border-top: 1px solid var(--border);
        }

        /* ===== GLOBAL NOTIFICATION ===== */
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
        .global-notification a { color: white; text-decoration: underline; }

        /* ===== BREADCRUMBS ===== */
        .breadcrumbs {
            background: var(--vanilla);
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
            font-size: 0.85rem;
        }
        .breadcrumbs a { color: var(--text); text-decoration: none; }
        .breadcrumbs a:hover { color: var(--rose); }
        .breadcrumb-sep { color: var(--text-light); margin: 0 4px; }

        /* ===== STICKY HEADER SHADOW ===== */
        .site-header.scrolled {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        /* ===== MOBILE STYLES (992px and below) ===== */
        @media (max-width: 992px) {
            /* HAMBURGER - visible on mobile */
            .hamburger {
                display: flex !important;
                flex-direction: column;
                justify-content: space-between;
                width: 28px;
                height: 20px;
                background: none;
                border: none;
                cursor: pointer;
                padding: 0;
                z-index: 1001;
            }
            .hamburger span {
                display: block;
                width: 100%;
                height: 2px;
                background: var(--rose);
                border-radius: 2px;
                transition: all 0.3s ease;
            }
            .hamburger.active span:nth-child(1) { transform: rotate(45deg) translate(6px, 6px); }
            .hamburger.active span:nth-child(2) { opacity: 0; }
            .hamburger.active span:nth-child(3) { transform: rotate(-45deg) translate(6px, -6px); }

            /* NAVIGATION - hidden by default, slides in when .open */
            .nav-links {
                display: none !important;
                flex-direction: column;
                position: fixed;
                top: 0;
                right: -320px;
                width: 320px;
                height: 100vh;
                background: var(--card-bg);
                border-left: 1px solid var(--border);
                padding: 80px 24px 24px;
                box-shadow: -4px 0 20px rgba(0, 0, 0, 0.1);
                z-index: 1000;
                overflow-y: auto;
                transition: right 0.3s ease;
            }
            .nav-links.open {
                display: flex !important;
                right: 0;
            }
            .nav-links li {
                margin: 4px 0;
                padding: 8px 0;
                border-bottom: 1px solid var(--border);
            }
            .nav-links li:last-child {
                border-bottom: none;
            }
            .nav-links a {
                padding: 4px 0;
                width: 100%;
                font-size: 1rem;
            }
            .nav-links .nav-separator {
                display: none;
            }

            /* OVERLAY */
            .menu-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 999;
            }
            .menu-overlay.open {
                display: block;
            }
        }
    </style>
</head>
<body>
    <a href="#mainContent" class="skip-link">Skip to main content</a>

    <header class="site-header" id="siteHeader">
        <nav class="navbar" role="navigation" aria-label="Main navigation">
            <div class="container nav-container">
                <a href="<?php echo SITE_URL; ?>/index.php" class="logo" aria-label="AngelWrites – Home">
                    <img src="<?php echo SITE_URL; ?>/assets/images/logo.png" alt="AngelWrites – Christian writing and community" class="logo-img">
                </a>

                <!-- ===== NAVIGATION LINKS ===== -->
                <ul class="nav-links" id="navLinks" role="menubar">
                    <?php if (!$isLoggedIn): ?>
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
                        <li><a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="<?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/admin/manage_books.php">📖 Books</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/admin/manage_poems.php">📝 Poems</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/admin/manage_sessions.php">📅 Sessions</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/admin/manage_users.php">👥 Users</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/admin/settings.php">⚙️ Settings</a></li>
                        <li class="nav-separator">|</li>
                        <li><a href="<?php echo SITE_URL; ?>/logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    <?php else: ?>
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

                <!-- ===== NAV ACTIONS ===== -->
                <div class="nav-actions">
                    <!-- Search -->
                    <div class="search-wrapper">
                        <button class="search-trigger nav-action-icon" aria-label="Search" onclick="document.getElementById('searchDropdown').classList.toggle('open'); document.getElementById('searchInput').focus();">
                            <i class="fas fa-search"></i>
                        </button>
                        <form action="<?php echo SITE_URL; ?>/search_results.php" method="GET" class="search-dropdown" id="searchDropdown">
                            <input type="text" name="q" placeholder="Search books, poems, blog..." autocomplete="off" id="searchInput">
                            <button type="submit" aria-label="Submit search"><i class="fas fa-arrow-right"></i></button>
                        </form>
                    </div>

                    <!-- Bible Reader -->
                    <a href="<?php echo SITE_URL; ?>/bible_reader.php" class="nav-action-icon" aria-label="Open Bible reader">
                        <i class="fas fa-book-bible"></i>
                    </a>

                    <!-- Notifications -->
                    <?php if ($isLoggedIn): ?>
                        <div class="notification-wrapper">
                            <button class="nav-action-icon" aria-label="Notifications" onclick="document.getElementById('notificationDropdown').classList.toggle('open');">
                                <i class="fas fa-bell"></i>
                                <?php if ($unreadNotifications > 0): ?>
                                    <span class="notification-badge"><?php echo $unreadNotifications; ?></span>
                                <?php endif; ?>
                            </button>
                            <div class="notification-dropdown" id="notificationDropdown">
                                <?php if (count($latestNotifications) > 0): ?>
                                    <?php foreach ($latestNotifications as $notif): ?>
                                        <div class="notif-item">
                                            <div class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                            <div class="notif-message"><?php echo htmlspecialchars(substr($notif['message'], 0, 60)); ?></div>
                                            <div class="notif-date"><?php echo date('M j, Y', strtotime($notif['created_at'])); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                    <a href="<?php echo SITE_URL; ?>/notifications.php" class="view-all">View All →</a>
                                <?php else: ?>
                                    <div class="notif-item">No notifications yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Theme Toggle -->
                    <button class="nav-action-icon theme-toggle" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-moon"></i>
                    </button>

                    <!-- User Dropdown (Logged in only) -->
                    <?php if ($isLoggedIn): ?>
                        <div class="user-wrapper" onclick="document.getElementById('userDropdown').classList.toggle('open');">
                            <i class="fas fa-user-circle" style="font-size: 1.2rem; color: var(--text);"></i>
                            <span style="font-size: 0.9rem; color: var(--text);"><?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?></span>
                            <div class="user-dropdown" id="userDropdown">
                                <a href="<?php echo SITE_URL; ?>/dashboard.php">Dashboard</a>
                                <a href="<?php echo SITE_URL; ?>/library.php">My Library</a>
                                <a href="<?php echo SITE_URL; ?>/profile.php">Profile</a>
                                <hr>
                                <a href="<?php echo SITE_URL; ?>/logout.php" style="color: #e74c3c;">Logout</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Guest: login/signup icons -->
                        <a href="<?php echo SITE_URL; ?>/login.php" class="nav-action-icon" aria-label="Login">
                            <i class="fas fa-sign-in-alt"></i>
                        </a>
                        <a href="<?php echo SITE_URL; ?>/register.php" class="nav-action-icon" aria-label="Sign up">
                            <i class="fas fa-user-plus"></i>
                        </a>
                    <?php endif; ?>

                    <!-- HAMBURGER (Only on mobile) -->
                    <button class="hamburger" id="hamburger" aria-label="Toggle navigation menu" onclick="toggleMobileMenu()">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                </div>
            </div>
        </nav>

        <!-- Overlay -->
        <div class="menu-overlay" id="menuOverlay" onclick="closeMobileMenu()"></div>
    </header>

    <main class="site-main" id="mainContent">
        <?php if (isset($_SESSION['notification'])): ?>
            <div class="global-notification">
                <?php echo $_SESSION['notification']; ?>
                <?php unset($_SESSION['notification']); ?>
            </div>
        <?php endif; ?>
        
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
    </main>

    <!-- ===== JAVASCRIPT – SIMPLE & RELIABLE ===== -->
    <script>
    // ===== GLOBAL FUNCTIONS – ALWAYS AVAILABLE =====
    function toggleMobileMenu() {
        const navLinks = document.getElementById('navLinks');
        const overlay = document.getElementById('menuOverlay');
        const hamburger = document.getElementById('hamburger');
        const body = document.body;

        if (!navLinks) return;
        const isOpen = navLinks.classList.contains('open');

        if (isOpen) {
            closeMobileMenu();
        } else {
            navLinks.classList.add('open');
            if (overlay) overlay.classList.add('open');
            if (hamburger) hamburger.classList.add('active');
            body.style.overflow = 'hidden';
        }
    }

    function closeMobileMenu() {
        const navLinks = document.getElementById('navLinks');
        const overlay = document.getElementById('menuOverlay');
        const hamburger = document.getElementById('hamburger');
        const body = document.body;

        if (!navLinks) return;
        navLinks.classList.remove('open');
        if (overlay) overlay.classList.remove('open');
        if (hamburger) hamburger.classList.remove('active');
        body.style.overflow = '';
    }

    // ===== DOM READY =====
    document.addEventListener('DOMContentLoaded', function() {
        // Scroll shadow
        const header = document.getElementById('siteHeader');
        if (header) {
            window.addEventListener('scroll', function() {
                header.classList.toggle('scrolled', window.scrollY > 10);
            });
        }

        // Theme toggle
        const themeToggle = document.getElementById('themeToggle');
        const html = document.documentElement;
        const themes = ['light', 'dark', 'system'];
        let currentThemeIndex = 0;
        const storedTheme = localStorage.getItem('angelwrites_theme');
        if (storedTheme && themes.includes(storedTheme)) {
            currentThemeIndex = themes.indexOf(storedTheme);
        }

        function applyTheme(theme) {
            if (theme === 'system') {
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                html.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
            } else {
                html.setAttribute('data-theme', theme);
            }
            document.cookie = 'theme=' + theme + '; path=/; max-age=' + (365 * 24 * 60 * 60);
            updateIcon(theme);
            localStorage.setItem('angelwrites_theme', theme);
        }

        function updateIcon(theme) {
            if (themeToggle) {
                const icon = themeToggle.querySelector('i');
                if (theme === 'dark') icon.className = 'fas fa-sun';
                else if (theme === 'light') icon.className = 'fas fa-moon';
                else icon.className = 'fas fa-circle-half-stroke';
            }
        }

        applyTheme(themes[currentThemeIndex]);

        if (themeToggle) {
            themeToggle.addEventListener('click', function() {
                currentThemeIndex = (currentThemeIndex + 1) % themes.length;
                applyTheme(themes[currentThemeIndex]);
            });
        }

        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
            if (localStorage.getItem('angelwrites_theme') === 'system') {
                html.setAttribute('data-theme', e.matches ? 'dark' : 'light');
            }
        });

        // Close mobile menu when a link is clicked
        document.querySelectorAll('#navLinks a').forEach(function(link) {
            link.addEventListener('click', function() {
                closeMobileMenu();
            });
        });

        // Close mobile menu on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeMobileMenu();
        });

        // Close all dropdowns on outside click
        document.addEventListener('click', function(e) {
            // Search dropdown
            if (!e.target.closest('.search-wrapper')) {
                document.getElementById('searchDropdown').classList.remove('open');
            }
            // Notification dropdown
            if (!e.target.closest('.notification-wrapper')) {
                document.getElementById('notificationDropdown').classList.remove('open');
            }
            // User dropdown
            if (!e.target.closest('.user-wrapper')) {
                document.getElementById('userDropdown').classList.remove('open');
            }
        });
    });
    </script>
</body>
</html>