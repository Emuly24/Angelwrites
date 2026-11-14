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

// G1: planned reads remind the member the first time they use the site on the day (no cron on this host)
if ($isLoggedIn && ($_SESSION['aw_plans_day'] ?? '') !== date('Y-m-d') && isset($db) && $db instanceof PDO) {
    $_SESSION['aw_plans_day'] = date('Y-m-d');
    try {
        require_once __DIR__ . '/reading_plans.php';
        if (!function_exists('sendEmail') && is_file(__DIR__ . '/mail_helper.php')) require_once __DIR__ . '/mail_helper.php';
        aw_plans_remind($db, (int)$_SESSION['user_id']);
    } catch (Throwable $e) { error_log('plans hook: ' . $e->getMessage()); }
}

// ===== FETCH UNREAD NOTIFICATIONS =====
$unreadNotifications = 0;
$latestNotifications = [];
if ($isLoggedIn) {
    $user_id = $_SESSION['user_id'];
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        $unreadNotifications = $stmt->fetchColumn();
        
        $stmt = $db->prepare("SELECT id, title, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY is_read ASC, created_at DESC LIMIT 5");
        $stmt->execute([$user_id]);
        $latestNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $unreadNotifications = 0;
        $latestNotifications = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="aw-ui" data-aw-page="<?php echo htmlspecialchars(basename($_SERVER['PHP_SELF'] ?? '', '.php')); ?>"><!-- D1 -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
    // D8: apply the saved theme before the page paints, so dark mode no longer flashes light first.
    (function () { try {
        var t = localStorage.getItem('angelwrites_theme');
        var dark = t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    } catch (e) {} })();
    </script>
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo htmlspecialchars(aw_site_name()); /* F191 */ ?></title>
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    
    <meta name="description" content="<?php echo isset($metaDescription) ? $metaDescription : 'AngelWrites — Christian writing and community platform by Angella Bottoman.'; ?>">
    <meta name="keywords" content="AngelWrites, Christian writing, faith, poetry, books, reflections, Angella Bottoman">
    <meta name="robots" content="<?php echo !empty($aw_noindex) ? 'noindex, nofollow' : 'index, follow'; ?>"><!-- D2: private-ish pages (Stories & Help) ask search engines to stay away -->
    <!-- Q32: og:title/description/url and twitter:card were printed here and again in the Open Graph
         block below, so pages that set their own $og_* values were shadowed by these generic ones. -->
    
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="manifest" href="<?php echo SITE_URL; ?>/manifest.json">
    <meta name="theme-color" content="#DBA1A2">
    <link rel="icon" type="image/x-icon" href="<?php echo SITE_URL; ?>/favicon.ico">
    <link rel="apple-touch-icon" href="<?php echo SITE_URL; ?>/assets/images/apple-touch-icon.png"><!-- Q44 -->

   <!-- ===== OPEN GRAPH META TAGS (Social Sharing) ===== -->
<?php
    // 1. Strictly ENSURE these variables are defined, even if they are empty.
    $og_title       = isset($og_title)       ? htmlspecialchars($og_title)       : (isset($pageTitle) ? htmlspecialchars($pageTitle) : htmlspecialchars(aw_site_name())); // F191
    $og_description = isset($og_description) ? htmlspecialchars($og_description) : 'AngelWrites — Christian writing and community platform.';
    $og_url         = isset($og_url)         ? htmlspecialchars($og_url)         : htmlspecialchars(SITE_URL . strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '#'));
    $og_image       = isset($og_image)       ? htmlspecialchars($og_image)       : SITE_URL . '/assets/images/og-logo.jpg'; // Q43: angelwrites-logo.png never existed
    if (!isset($og_image_width) && strpos($og_image, '/og-logo.jpg') !== false) { $og_image_width = 1200; $og_image_height = 800; }
    $og_image_alt   = htmlspecialchars($og_image_alt ?? (html_entity_decode($og_title, ENT_QUOTES, 'UTF-8') . ' · AngelWrites'));
    $og_image_type  = strpos($og_image, '/og_image.php') !== false || preg_match('/\.jpe?g($|\?)/i', $og_image) ? 'image/jpeg' : (preg_match('/\.png($|\?)/i', $og_image) ? 'image/png' : '');
?>
<meta property="og:site_name" content="<?php echo htmlspecialchars(aw_site_name()); ?>" />
<meta property="og:type" content="article" />
<meta property="og:title" content="<?php echo $og_title; ?>" />
<meta property="og:description" content="<?php echo $og_description; ?>" />
<meta property="og:url" content="<?php echo $og_url; ?>" />
<meta property="og:image" content="<?php echo $og_image; ?>" />
<meta property="og:image:secure_url" content="<?php echo $og_image; ?>" />
<?php if ($og_image_type !== ''): ?><meta property="og:image:type" content="<?php echo $og_image_type; ?>" /><?php endif; ?>
<meta property="og:image:alt" content="<?php echo $og_image_alt; ?>" />
<?php if (isset($og_image_width)): ?>
    <meta property="og:image:width" content="<?php echo $og_image_width; ?>" />
    <meta property="og:image:height" content="<?php echo $og_image_height; ?>" />
<?php endif; ?>

<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="<?php echo $og_title; ?>" />
<meta name="twitter:description" content="<?php echo $og_description; ?>" />
<meta name="twitter:image" content="<?php echo $og_image; ?>" />
<meta name="twitter:image:alt" content="<?php echo $og_image_alt; ?>" />
<?php if (!empty($aw_share_url)): /* the share buttons and the reader's Share use the relay link when one is set up */ ?><script>window.AW_SHARE_URL = <?php echo json_encode((string)$aw_share_url, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES); ?>;</script><?php endif; ?>
    
    <style>
        /* ===== RESET & BASE ===== */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { overflow-x: hidden; width: 100%; }
        :root {
            --rose: #DBA1A2; --rose-dark: #c08a8b; --rose-light: #e8c0c0;
            --vanilla: #EFD8D6; --fantasy: #F7F3ED; --white: #fff;
            --dark: #2c1e1e; --text: #3d2e2e; --text-light: #6b5a5a;
            --bg: #F7F3ED; --card-bg: #fff; --border: #e5d5d5;
            --shadow: 0 4px 16px rgba(44,30,30,0.08);
            --shadow-hover: 0 8px 30px rgba(44,30,30,0.15);
            --input-bg: #fff; --transition: 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        .skip-link { position: absolute; top: -60px; left: 12px; background: #9c5457; color: #fff; padding: 10px 16px; z-index: 9999; border-radius: 0 0 12px 12px; transition: top .2s ease; text-decoration: none; font-weight: 700; }
        .skip-link:focus { top: 0; }
        .global-notification { background: #9c5457; color: #fff; text-align: center; padding: 10px 16px; font-size: .92rem; }
        .global-notification a { color: #fff; text-decoration: underline; }
        .breadcrumbs { background: var(--vanilla); padding: 8px 0; border-bottom: 1px solid var(--border); font-size: .85rem; }
        .breadcrumbs a { color: var(--text); text-decoration: none; }
        .breadcrumbs a:hover { color: var(--rose); }
        .breadcrumb-sep { color: var(--text-light); margin: 0 4px; }
    </style>
    <!-- D1: site-wide design layer, after the base styles so it applies on every page -->
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/aw-design.css?v=1">
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/aw-header.css?v=1"><!-- the header -->
    <script src="<?php echo SITE_URL; ?>/assets/js/aw-motion.js?v=1" defer></script><!-- D2 -->
<?php echo function_exists('aw_staff_csrf_script') ? aw_staff_csrf_script() : ''; /* K3: staff forms and fetches carry the CSRF token */ ?>
</head>
<body>
    <a href="#mainContent" class="skip-link">Skip to main content</a>
<?php
// ===== What the header needs to know (read once) =====
$aw_u = rtrim(SITE_URL, '/');
$aw_script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$aw_in_admin = strpos($aw_script, '/admin/') !== false;
$aw_is = function ($pages) use ($currentPage, $aw_in_admin) { // is this the current page?
    foreach ((array)$pages as $pg) {
        $adm = strpos($pg, 'admin/') === 0;
        if ($adm === $aw_in_admin && basename($pg) === $currentPage) return true;
    }
    return false;
};
$aw_cur = function ($pages) use ($aw_is) { return $aw_is($pages) ? ' aria-current="page"' : ''; };
$aw_stories_on = (string)(function_exists('aw_setting') ? aw_setting('community_posts_enabled', '1') : '1') !== '0';
$aw_is_staff = function_exists('aw_is_staff') && aw_is_staff();
$aw_me = ['name' => 'Friend', 'initial' => 'A', 'avatar' => '', 'email' => ''];
$aw_msg_unread = 0;
$aw_msg_on = (string)(function_exists('aw_setting') ? aw_setting('messaging_enabled', '1') : '1') !== '0';
if ($isLoggedIn) {
    try {
        $st = $db->prepare("SELECT name, first_name, last_name, username, email, avatar, profile_pic FROM users WHERE id = ?");
        $st->execute([(int)$_SESSION['user_id']]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $aw_me['name'] = awDisplayName($row);
            $aw_me['email'] = (string)$row['email'];
            $pic = trim((string)($row['avatar'] ?: $row['profile_pic']));
            if ($pic !== '' && strpos($pic, '..') === false) $aw_me['avatar'] = preg_match('#^https?://#i', $pic) ? $pic : $aw_u . '/' . ltrim($pic, '/');
        }
    } catch (Throwable $e) { error_log('header me: ' . $e->getMessage()); }
    $aw_me['initial'] = mb_strtoupper(mb_substr($aw_me['name'], 0, 1)) ?: 'A';
    // I2: unread conversations and requests, counted once on page load (nothing polls)
    if ($aw_msg_on) { try { require_once __DIR__ . '/messaging.php'; $aw_msg_unread = aw_msg_unread_count($db, (int)$_SESSION['user_id']); } catch (Throwable $e) { error_log('header messages: ' . $e->getMessage()); } }
}
$aw_first = explode(' ', $aw_me['name'])[0];
$aw_logo = aw_logo_path(); // F191
$aw_logo_default = $aw_logo === 'assets/images/logo.png';
$aw_note_link = function ($l) use ($aw_u) {
    $l = trim((string)$l);
    if ($l === '') return $aw_u . '/notifications.php';
    if (strpos($l, $aw_u . '/') === 0) return $l;
    if ($l[0] === '/' && (strlen($l) < 2 || $l[1] !== '/')) return $aw_u . $l;
    return $aw_u . '/notifications.php';
};
$aw_ago = function ($when) {
    $t = strtotime((string)$when . ' UTC'); if (!$t) return '';
    $d = time() - $t;
    if ($d < 60) return 'just now'; if ($d < 3600) return floor($d / 60) . ' min ago'; if ($d < 86400) return floor($d / 3600) . ' h ago';
    if ($d < 172800) return 'yesterday'; return date('j M', $t);
};
// drawer links: [group => [[label, icon, href, pages-for-current, count]]]
$aw_groups = [];
if (!$isLoggedIn) {
    $aw_groups['Explore'] = [['Search', 'fa-magnifying-glass', $aw_u . '/search_results.php', ['search_results.php']], ['Home', 'fa-house', $aw_u . '/index.php', ['index.php']], ['Books', 'fa-book', $aw_u . '/books.php', ['books.php', 'book.php']], ['Poems', 'fa-feather', $aw_u . '/poetry.php', ['poetry.php', 'poem_view.php']],
        ['Reflections', 'fa-dove', $aw_u . '/reflections.php', ['reflections.php']], ['Blog', 'fa-pen-nib', $aw_u . '/blog.php', ['blog.php', 'blog_post.php']], ['Videos', 'fa-circle-play', $aw_u . '/videos.php', ['videos.php', 'video_watch.php']], ['Bible', 'fa-book-bible', $aw_u . '/bible_reader.php', ['bible_reader.php']]];
    $aw_groups['AngelWrites'] = [['About Angella', 'fa-heart', $aw_u . '/about.php', ['about.php']], ['Contact', 'fa-envelope', $aw_u . '/contact.php', ['contact.php']]];
} elseif ($isAdmin) {
    $aw_groups['Admin'] = [['Dashboard', 'fa-gauge-high', $aw_u . '/admin/dashboard.php', ['admin/dashboard.php']], ['Books', 'fa-book', $aw_u . '/admin/manage_books.php', ['admin/manage_books.php']], ['Poems', 'fa-feather', $aw_u . '/admin/manage_poems.php', ['admin/manage_poems.php']],
        ['Sessions', 'fa-calendar-check', $aw_u . '/admin/manage_sessions.php', ['admin/manage_sessions.php']], ['Members', 'fa-users', $aw_u . '/admin/manage_users.php', ['admin/manage_users.php']], ['Moderation', 'fa-shield-heart', $aw_u . '/admin/moderation.php', ['admin/moderation.php']],
        ['Stories & Help', 'fa-seedling', $aw_u . '/admin/community.php', ['admin/community.php']], ['Settings', 'fa-sliders', $aw_u . '/admin/settings.php', ['admin/settings.php']]];
    $aw_groups['Site'] = [['View the site', 'fa-house', $aw_u . '/index.php', ['index.php']], ['Messages', 'fa-envelope', $aw_u . '/messages.php', ['messages.php'], $aw_msg_unread], ['Bible', 'fa-book-bible', $aw_u . '/bible_reader.php', ['bible_reader.php']]];
} else {
    $aw_groups['You'] = [['Dashboard', 'fa-gauge-high', $aw_u . '/dashboard.php', ['dashboard.php']], ['My library', 'fa-bookmark', $aw_u . '/library.php', ['library.php']],
        ['Messages', 'fa-envelope', $aw_u . '/messages.php', ['messages.php'], $aw_msg_on ? $aw_msg_unread : null], ['Notifications', 'fa-bell', $aw_u . '/notifications.php', ['notifications.php'], $unreadNotifications], ['Profile', 'fa-user', $aw_u . '/profile.php', ['profile.php']]];
    $aw_groups['Read'] = [['Home', 'fa-house', $aw_u . '/index.php', ['index.php']], ['Books', 'fa-book', $aw_u . '/books.php', ['books.php', 'book.php']], ['Poems', 'fa-feather', $aw_u . '/poetry.php', ['poetry.php', 'poem_view.php']],
        ['Reflections', 'fa-dove', $aw_u . '/reflections.php', ['reflections.php']], ['Blog', 'fa-pen-nib', $aw_u . '/blog.php', ['blog.php', 'blog_post.php']], ['Videos', 'fa-circle-play', $aw_u . '/videos.php', ['videos.php', 'video_watch.php']], ['Bible', 'fa-book-bible', $aw_u . '/bible_reader.php', ['bible_reader.php']]];
    $aw_groups['Community'] = array_values(array_filter([
        $aw_stories_on ? ['Stories & Help', 'fa-seedling', $aw_u . '/stories.php', ['stories.php', 'story.php', 'story_write.php']] : null,
        $aw_stories_on ? ['My Stories & Help', 'fa-pen', $aw_u . '/my_stories.php', ['my_stories.php']] : null,
        ['Community Q&A', 'fa-circle-question', $aw_u . '/community.php', ['community.php']], ['Reading groups', 'fa-user-group', $aw_u . '/groups.php', ['groups.php', 'group.php']], ['Book a session', 'fa-calendar-check', $aw_u . '/book_session.php', ['book_session.php']]]));
    if ($aw_is_staff) $aw_groups['Team'] = [['Staff dashboard', 'fa-shield-heart', $aw_u . '/admin/dashboard.php', ['admin/dashboard.php']]];
}
$aw_community_pages = ['stories.php', 'story.php', 'story_write.php', 'my_stories.php', 'community.php', 'groups.php', 'group.php', 'book_session.php'];
?>
    <header class="awh site-header" id="siteHeader">
        <div class="awh-bar">
            <a href="<?php echo $aw_u; ?>/<?php echo $isAdmin ? 'admin/dashboard.php' : 'index.php'; ?>" class="awh-logo" aria-label="AngelWrites – home">
                <?php if ($aw_logo_default): ?><span class="awh-logo-crop"><img src="<?php echo $aw_u . '/' . htmlspecialchars($aw_logo); ?>" alt="AngelWrites"></span>
                <?php else: ?><span class="awh-logo-plain"><img src="<?php echo $aw_u . '/' . htmlspecialchars($aw_logo); ?>" alt="AngelWrites"></span><?php endif; ?>
            </a>

            <nav class="awh-nav" aria-label="Main">
                <span class="awh-ink" aria-hidden="true"></span>
                <ul class="awh-links" id="navLinks">
                    <?php if (!$isLoggedIn): ?>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/index.php"<?php echo $aw_cur('index.php'); ?>>Home</a></li>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/books.php"<?php echo $aw_cur(['books.php', 'book.php']); ?>>Books</a></li>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/poetry.php"<?php echo $aw_cur(['poetry.php', 'poem_view.php']); ?>>Poems</a></li>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/reflections.php"<?php echo $aw_cur('reflections.php'); ?>>Reflections</a></li>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/blog.php"<?php echo $aw_cur(['blog.php', 'blog_post.php']); ?>>Blog</a></li>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/about.php"<?php echo $aw_cur('about.php'); ?>>About</a></li>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/contact.php"<?php echo $aw_cur('contact.php'); ?>>Contact</a></li>
                    <?php elseif ($isAdmin): ?>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/admin/dashboard.php"<?php echo $aw_cur('admin/dashboard.php'); ?>>Dashboard</a></li>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/admin/manage_books.php"<?php echo $aw_cur('admin/manage_books.php'); ?>>Books</a></li>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/admin/manage_poems.php"<?php echo $aw_cur('admin/manage_poems.php'); ?>>Poems</a></li>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/admin/manage_sessions.php"<?php echo $aw_cur('admin/manage_sessions.php'); ?>>Sessions</a></li>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/admin/manage_users.php"<?php echo $aw_cur('admin/manage_users.php'); ?>>Members</a></li>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/admin/settings.php"<?php echo $aw_cur('admin/settings.php'); ?>>Settings</a></li>
                    <?php else: ?>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/index.php"<?php echo $aw_cur('index.php'); ?>>Home</a></li>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/library.php"<?php echo $aw_cur('library.php'); ?>>My library</a></li>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/books.php"<?php echo $aw_cur(['books.php', 'book.php']); ?>>Books</a></li>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/poetry.php"<?php echo $aw_cur(['poetry.php', 'poem_view.php']); ?>>Poems</a></li>
                        <li><a class="awh-link" href="<?php echo $aw_u; ?>/reflections.php"<?php echo $aw_cur('reflections.php'); ?>>Reflections</a></li>
                        <li>
                            <button type="button" class="awh-link<?php echo $aw_is($aw_community_pages) ? ' is-active' : ''; ?>" aria-haspopup="true" aria-expanded="false" aria-controls="awhCommunity" onclick="awToggleMenu(this, 'awhCommunity')">Community <i class="fas fa-chevron-down" aria-hidden="true"></i></button>
                            <div class="awh-menu" id="awhCommunity">
                                <?php if ($aw_stories_on): ?><a href="<?php echo $aw_u; ?>/stories.php"><i class="fas fa-seedling" aria-hidden="true"></i><span>Stories &amp; Help<small>Get help · Offer help</small></span></a><?php endif; ?>
                                <a href="<?php echo $aw_u; ?>/community.php"><i class="fas fa-circle-question" aria-hidden="true"></i><span>Community Q&amp;A<small>Ask and answer</small></span></a>
                                <a href="<?php echo $aw_u; ?>/groups.php"><i class="fas fa-user-group" aria-hidden="true"></i><span>Reading groups<small>Read a book together</small></span></a>
                                <a href="<?php echo $aw_u; ?>/book_session.php"><i class="fas fa-calendar-check" aria-hidden="true"></i><span>Book a session<small>Talk with Angella</small></span></a>
                            </div>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>

            <div class="awh-actions">
                <a href="<?php echo $aw_u; ?>/search_results.php" class="awh-icon awh-hide-sm" aria-label="Search" title="Search"><i class="fas fa-magnifying-glass" aria-hidden="true"></i></a>
                <a href="<?php echo $aw_u; ?>/bible_reader.php" class="awh-icon awh-hide-sm" aria-label="Bible" title="Bible"><i class="fas fa-book-bible" aria-hidden="true"></i></a>
                <?php if ($isLoggedIn): ?>
                    <?php if ($aw_msg_on): ?>
                    <a class="awh-icon" href="<?php echo $aw_u; ?>/messages.php" aria-label="Messages<?php echo $aw_msg_unread ? ', ' . (int)$aw_msg_unread . ' unread' : ''; ?>" title="Messages"><i class="fas fa-envelope" aria-hidden="true"></i><?php if ($aw_msg_unread > 0): ?><span class="awh-badge" aria-hidden="true"><?php echo (int)$aw_msg_unread; ?></span><?php endif; ?></a>
                    <?php endif; ?>
                    <div class="awh-wrap">
                        <button type="button" class="awh-icon" aria-label="Notifications<?php echo $unreadNotifications ? ', ' . (int)$unreadNotifications . ' new' : ''; ?>" title="Notifications" aria-haspopup="true" aria-expanded="false" aria-controls="notificationDropdown" onclick="awToggleMenu(this, 'notificationDropdown')">
                            <i class="fas fa-bell" aria-hidden="true"></i><?php if ($unreadNotifications > 0): ?><span class="awh-badge" aria-hidden="true"><?php echo (int)$unreadNotifications; ?></span><?php endif; ?>
                        </button>
                        <div class="awh-menu awh-notes" id="notificationDropdown">
                            <div class="awh-notes-head"><strong>Notifications</strong><a href="<?php echo $aw_u; ?>/notifications.php">See all</a></div>
                            <?php if (!$latestNotifications): ?><p class="awh-empty">Nothing yet. Replies, answers and messages will appear here.</p>
                            <?php else: foreach ($latestNotifications as $notif): ?>
                                <a class="awh-note<?php echo empty($notif['is_read']) ? ' is-new' : ''; ?>" href="<?php echo htmlspecialchars($aw_note_link($notif['link'] ?? '')); ?>"><span class="awh-dot" aria-hidden="true"></span><span><b><?php echo htmlspecialchars($notif['title']); ?></b><span><?php echo htmlspecialchars(mb_strimwidth((string)$notif['message'], 0, 90, '…')); ?></span><time><?php echo htmlspecialchars($aw_ago($notif['created_at'])); ?></time></span></a>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <button type="button" class="awh-icon theme-toggle" id="themeToggle" aria-label="Change theme (light, dark or your device's setting)" title="Theme"><i class="fas fa-moon"></i></button>
                <?php if ($isLoggedIn): ?>
                    <div class="awh-wrap awh-hide-md">
                        <button type="button" class="awh-me" aria-haspopup="true" aria-expanded="false" aria-controls="userDropdown" onclick="awToggleMenu(this, 'userDropdown')">
                            <span class="awh-avatar" aria-hidden="true"><?php if ($aw_me['avatar']): ?><img src="<?php echo htmlspecialchars($aw_me['avatar']); ?>" alt="" onerror="this.remove()"><?php endif; ?><?php echo htmlspecialchars($aw_me['initial']); ?></span>
                            <span class="awh-me-name"><?php echo htmlspecialchars($aw_first); ?></span><i class="fas fa-chevron-down" aria-hidden="true"></i><span class="sr-only">, account menu</span>
                        </button>
                        <div class="awh-menu" id="userDropdown">
                            <div class="awh-menu-head"><strong><?php echo htmlspecialchars($aw_me['name']); ?></strong><span><?php echo htmlspecialchars($aw_me['email']); ?></span></div>
                            <?php if ($isAdmin): ?>
                                <a href="<?php echo $aw_u; ?>/admin/dashboard.php"><i class="fas fa-gauge-high" aria-hidden="true"></i>Admin dashboard</a>
                                <a href="<?php echo $aw_u; ?>/admin/moderation.php"><i class="fas fa-shield-heart" aria-hidden="true"></i>Moderation</a>
                                <a href="<?php echo $aw_u; ?>/index.php"><i class="fas fa-house" aria-hidden="true"></i>View the site</a>
                                <a href="<?php echo $aw_u; ?>/account_security.php"><i class="fas fa-lock" aria-hidden="true"></i>Account security</a>
                            <?php else: ?>
                                <a href="<?php echo $aw_u; ?>/dashboard.php"><i class="fas fa-gauge-high" aria-hidden="true"></i>Dashboard</a>
                                <a href="<?php echo $aw_u; ?>/library.php"><i class="fas fa-bookmark" aria-hidden="true"></i>My library</a>
                                <?php if ($aw_stories_on): ?><a href="<?php echo $aw_u; ?>/my_stories.php"><i class="fas fa-seedling" aria-hidden="true"></i>My Stories &amp; Help</a><?php endif; ?>
                                <a href="<?php echo $aw_u; ?>/profile.php"><i class="fas fa-user" aria-hidden="true"></i>Profile</a>
                                <?php if ($aw_is_staff): ?><a href="<?php echo $aw_u; ?>/admin/dashboard.php"><i class="fas fa-shield-heart" aria-hidden="true"></i>Staff dashboard</a><?php endif; ?>
                            <?php endif; ?>
                            <hr>
                            <a class="awh-out" href="<?php echo $aw_u; ?>/logout.php"><i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i>Log out</a>
                        </div>
                    </div>
                <?php else: ?>
                    <a class="awh-login awh-hide-md" href="<?php echo $aw_u; ?>/login.php">Log in</a>
                    <a class="awh-join" href="<?php echo $aw_u; ?>/register.php">Join free</a>
                <?php endif; ?>
                <button type="button" class="awh-icon awh-burger" id="hamburger" aria-label="Menu" aria-expanded="false" aria-controls="awhDrawer" onclick="toggleMobileMenu()"><span></span><span></span><span></span></button>
            </div>
        </div>
    </header>

    <div class="awh-scrim" id="menuOverlay" onclick="closeMobileMenu()"></div>
    <div class="awh-drawer" id="awhDrawer" role="dialog" aria-modal="true" aria-label="Menu" tabindex="-1">
        <div class="awh-drawer-head">
            <?php if ($aw_logo_default): ?><span class="awh-logo-crop" style="height:34px;width:122px"><img src="<?php echo $aw_u . '/' . htmlspecialchars($aw_logo); ?>" alt="AngelWrites" style="height:122.2px;margin:-40.5px 0 0 -27.9px"></span><?php else: ?><span class="awh-logo-plain"><img src="<?php echo $aw_u . '/' . htmlspecialchars($aw_logo); ?>" alt="AngelWrites" style="max-height:38px"></span><?php endif; ?>
            <button type="button" class="awh-icon" onclick="closeMobileMenu()" aria-label="Close the menu"><i class="fas fa-xmark" aria-hidden="true"></i></button>
        </div>
        <div class="awh-drawer-body">
            <?php if ($isLoggedIn): ?>
                <div class="awh-drawer-me"><span class="awh-avatar" aria-hidden="true"><?php if ($aw_me['avatar']): ?><img src="<?php echo htmlspecialchars($aw_me['avatar']); ?>" alt="" onerror="this.remove()"><?php endif; ?><?php echo htmlspecialchars($aw_me['initial']); ?></span><span><strong><?php echo htmlspecialchars($aw_me['name']); ?></strong><span><?php echo htmlspecialchars($aw_me['email']); ?></span></span></div>
            <?php endif; ?>
            <?php $aw_i = 0; foreach ($aw_groups as $aw_g => $aw_items): ?>
                <p class="awh-group"><?php echo htmlspecialchars($aw_g); ?></p>
                <?php foreach ($aw_items as $it): ?>
                    <a class="awh-dlink" style="--i:<?php echo $aw_i++; ?>" href="<?php echo htmlspecialchars($it[2]); ?>"<?php echo $aw_cur($it[3]); ?>><i class="fas <?php echo $it[1]; ?>" aria-hidden="true"></i><?php echo htmlspecialchars($it[0]); ?><?php if (!empty($it[4])): ?><span class="awh-count"><?php echo (int)$it[4]; ?><span class="sr-only"> new</span></span><?php endif; ?></a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
        <div class="awh-drawer-foot">
            <?php if ($isLoggedIn): ?>
                <a href="<?php echo $aw_u; ?>/search_results.php"><i class="fas fa-magnifying-glass" aria-hidden="true"></i> Search</a>
                <a href="<?php echo $aw_u; ?>/logout.php"><i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i> Log out</a>
            <?php else: ?>
                <a href="<?php echo $aw_u; ?>/login.php">Log in</a>
                <a class="awh-join" href="<?php echo $aw_u; ?>/register.php">Join free</a>
            <?php endif; ?>
        </div>
    </div>

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

    <script>
    (function () {
        var header = document.getElementById('siteHeader'), drawer = document.getElementById('awhDrawer'), scrim = document.getElementById('menuOverlay'), burger = document.getElementById('hamburger');
        var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // ----- the drawer (phones and narrow windows) -----
        function focusables() { return drawer.querySelectorAll('a[href], button:not([disabled])'); }
        window.toggleMobileMenu = function () { drawer.classList.contains('open') ? window.closeMobileMenu() : openDrawer(); };
        function openDrawer() {
            drawer.classList.add('open'); scrim.classList.add('open'); burger.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden'; header.classList.remove('is-away');
            setTimeout(function () { drawer.focus({ preventScroll: true }); }, 60); // Tab then moves into the links
        }
        window.closeMobileMenu = function () {
            if (!drawer.classList.contains('open')) return;
            drawer.classList.remove('open'); scrim.classList.remove('open'); burger.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = ''; burger.focus();
        };
        drawer.addEventListener('keydown', function (e) { // keep Tab inside the open drawer
            if (e.key !== 'Tab') return;
            var f = focusables(); if (!f.length) return;
            var a = f[0], z = f[f.length - 1];
            if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); }
        });

        // ----- small menus -----
        window.awToggleMenu = function (btn, id) {
            var dd = document.getElementById(id); if (!dd) return;
            var open = !dd.classList.contains('open');
            document.querySelectorAll('.awh-menu.open').forEach(function (m) { if (m !== dd) awCloseMenu(m.id); });
            dd.classList.toggle('open', open); btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) header.classList.remove('is-away');
        };
        function awCloseMenu(id) {
            var dd = document.getElementById(id); if (!dd || !dd.classList.contains('open')) return null;
            dd.classList.remove('open');
            var b = document.querySelector('[aria-controls="' + id + '"]'); if (b) b.setAttribute('aria-expanded', 'false');
            return b;
        }
        document.addEventListener('click', function (e) {
            document.querySelectorAll('.awh-menu.open').forEach(function (m) { if (!m.parentNode.contains(e.target)) awCloseMenu(m.id); });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            window.closeMobileMenu();
            document.querySelectorAll('.awh-menu.open').forEach(function (m) { var b = awCloseMenu(m.id); if (b) b.focus(); });
        });

        // ----- the pill that glides to the link you point at -----
        var links = document.getElementById('navLinks'), ink = document.querySelector('.awh-nav .awh-ink');
        function inkTo(el) {
            if (!ink) return;
            if (!el) { ink.style.opacity = '0'; return; }
            var nr = ink.parentNode.getBoundingClientRect(), r = el.getBoundingClientRect();
            ink.style.width = r.width + 'px'; ink.style.top = (r.top - nr.top) + 'px'; ink.style.height = r.height + 'px';
            ink.style.transform = 'translateX(' + (r.left - nr.left) + 'px)'; ink.style.opacity = '1';
        }
        function home() { return links ? links.querySelector('.awh-link[aria-current="page"], .awh-link.is-active') : null; }
        if (links) {
            links.addEventListener('mouseover', function (e) { var a = e.target.closest('.awh-link'); if (a) inkTo(a); });
            links.addEventListener('focusin', function (e) { var a = e.target.closest('.awh-link'); if (a) inkTo(a); });
            links.addEventListener('mouseleave', function () { inkTo(home()); });
            window.addEventListener('load', function () { inkTo(home()); });
            window.addEventListener('resize', function () { inkTo(home()); }, { passive: true });
        }

        // ----- slimmer when scrolled; steps aside while you read down, comes back when you scroll up -----
        var lastY = window.scrollY, ticking = false;
        window.addEventListener('scroll', function () {
            if (ticking) return; ticking = true;
            requestAnimationFrame(function () {
                ticking = false;
                var y = window.scrollY, busy = document.querySelector('.awh-menu.open') || drawer.classList.contains('open') || header.contains(document.activeElement);
                header.classList.toggle('is-scrolled', y > 8);
                header.classList.toggle('scrolled', y > 8);
                if (!calm && !busy && y > 260 && y - lastY > 6) header.classList.add('is-away');
                else if (lastY - y > 6 || y < 120) header.classList.remove('is-away');
                lastY = y;
            });
        }, { passive: true });
        header.addEventListener('focusin', function () { header.classList.remove('is-away'); });

        // ----- theme: light, dark, or the device's setting -----
        var themeToggle = document.getElementById('themeToggle'), html = document.documentElement, themes = ['light', 'dark', 'system'], idx = 0;
        try { var st = localStorage.getItem('angelwrites_theme'); if (st && themes.indexOf(st) > -1) idx = themes.indexOf(st); } catch (e) {}
        function applyTheme(theme) {
            html.setAttribute('data-theme', theme === 'system' ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : theme);
            document.cookie = 'theme=' + theme + '; path=/; max-age=' + (365 * 24 * 60 * 60);
            try { localStorage.setItem('angelwrites_theme', theme); } catch (e) {}
            var i = themeToggle && themeToggle.querySelector('i');
            if (i) i.className = theme === 'dark' ? 'fas fa-sun' : (theme === 'light' ? 'fas fa-moon' : 'fas fa-circle-half-stroke');
        }
        applyTheme(themes[idx]);
        if (themeToggle) themeToggle.addEventListener('click', function () { idx = (idx + 1) % themes.length; applyTheme(themes[idx]); });
        if (window.matchMedia) window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
            try { if (localStorage.getItem('angelwrites_theme') === 'system') html.setAttribute('data-theme', e.matches ? 'dark' : 'light'); } catch (er) {}
        });
    })();
    </script>
