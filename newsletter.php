<?php
// ===== LOAD CONFIGURATION FIRST =====
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php'; // Now safely loaded after config
require_once 'includes/newsletter_optin.php';
require_once 'includes/newsletter.php'; // N6: rate limits, branded welcome email

// ===== F176: CONFIRM A SUBSCRIPTION FROM THE EMAILED LINK =====
if (isset($_GET['confirm'])) {
    $stmt = $db->prepare("SELECT id, email, name, is_active FROM newsletter WHERE unsubscribe_token = ?");
    $stmt->execute([trim((string)$_GET['confirm'])]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sub) {
        $confirm_error = 'This confirmation link is not valid. Please subscribe again.';
    } else {
        if ($sub['is_active'] != 1) {
            $db->prepare("UPDATE newsletter SET is_active = 1, unsubscribed_at = NULL WHERE id = ?")->execute([$sub['id']]);
            sendEmail(aw_admin_email(), 'New Newsletter Subscriber (confirmed)',
                "A subscriber confirmed their newsletter subscription.\n\nEmail: {$sub['email']}\nName: " . ($sub['name'] ?: 'Not provided'),
                'angelwrites@zohomail.com', 'AngelWrites', false);
            // N6: a branded welcome email, once, when the subscription becomes active.
            $sub['unsubscribe_token'] = trim((string)$_GET['confirm']);
            $welcome = aw_nl_render($db, 'Welcome to the AngelWrites newsletter',
                '<p>Dear {{first_name}},</p><p>Thank you for joining the AngelWrites family. You will receive new reflections, poems and news about books and reading groups, written with love by Angella.</p>'
                . '<p>While you wait for the next letter, you are welcome to <a href="' . SITE_URL . '/reflections.php">read the latest reflections</a> or <a href="' . SITE_URL . '/poetry.php">a poem</a>.</p>',
                $sub, ['preview' => true, 'signature' => true, 'latest' => true, 'junk_note' => true,
                       'preheader' => 'Thank you for joining. Here is what to expect, and a few things to read while you wait.']);
            aw_nl_mail($sub['email'], 'Welcome to the AngelWrites newsletter', $welcome['html'], $welcome['text'], aw_nl_unsubscribe_url($sub['unsubscribe_token']));
            aw_nl_audit($db, 'confirm_subscription', $sub['email']);
        }
        $confirm_success = 'Thank you! Your subscription is confirmed. You can unsubscribe at any time from the link in each newsletter.';
    }
    $pageTitle = 'Newsletter';
    require_once 'includes/header.php';
    $ok = empty($confirm_error);
    echo '<main id="main" style="max-width:600px;margin:56px auto;padding:0 16px;"><div style="background:var(--card-bg,#fff);border:1px solid var(--border,#eadede);border-radius:24px;padding:36px 30px;text-align:center;box-shadow:0 18px 50px rgba(61,46,46,.1)">'
       . '<div style="font-size:2.6rem;color:' . ($ok ? '#2f7a4a' : '#a8432f') . '"><i class="fas ' . ($ok ? 'fa-circle-check' : 'fa-circle-exclamation') . '" aria-hidden="true"></i></div>'
       . '<h1 style="font-family:\'Playfair Display\',Georgia,serif;margin:10px 0">' . ($ok ? 'You are on the list' : 'That link did not work') . '</h1>'
       . '<p style="font-size:1.06rem;line-height:1.6">' . htmlspecialchars($ok ? $confirm_success : $confirm_error) . '</p>'
       . ($ok ? '<p style="color:var(--text-light,#6b5757)">A welcome letter is on its way. If it lands in Junk, please mark it “Not spam” so the next ones reach your inbox. <a href="' . SITE_URL . '/email_help.php">How</a></p>' : '')
       . '<p style="margin-top:20px"><a class="btn btn-primary" href="' . SITE_URL . ($ok ? '/reflections.php">Read the latest reflections' : '/newsletter.php">Subscribe again') . '</a> <a class="btn btn-outline" href="' . SITE_URL . '/index.php">Home</a></p></div></main>';
    require_once 'includes/footer.php';
    exit;
}

// ===== THE NEWSLETTER PAGE (a plain visit): what the letter is, how often, past letters, sign up =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    require_once 'includes/subscribe_control.php';
    $nl_flash_ok = (string)($_SESSION['newsletter_success'] ?? ''); $nl_flash_err = (string)($_SESSION['newsletter_error'] ?? '');
    unset($_SESSION['newsletter_success'], $_SESSION['newsletter_error']);
    $nl_member = aw_member_email($db);
    $nl_state = $nl_member !== '' ? aw_subscription_state($db, $nl_member) : 'none';
    $nl_count = 0; $nl_past = [];
    try { $nl_count = (int)$db->query("SELECT COUNT(*) FROM newsletter WHERE is_active = 1")->fetchColumn(); } catch (Throwable $e) {}
    try {
        foreach ($db->query("SELECT a.subject, a.sent_at, a.email_queue_id, q.preheader FROM newsletter_archive a LEFT JOIN email_queue q ON q.id = a.email_queue_id
                             WHERE a.email_queue_id IS NOT NULL AND COALESCE(q.send_to_type, '') <> 'single' ORDER BY a.sent_at DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC) as $r)
            $nl_past[] = $r + ['url' => aw_nl_web_url($db, (int)$r['email_queue_id'])];
    } catch (Throwable $e) { error_log('newsletter page past: ' . $e->getMessage()); }
    $pageTitle = 'The AngelWrites Letter';
    $og_description = 'A gentle letter from Angella Bottoman: new poems and reflections, a verse for your week, books, reading groups and events. Free, once or twice a month.';
    require_once 'includes/header.php';
    ?>
    <main class="nlp" id="main">
        <section class="nlp-hero">
            <div class="nlp-hero-text">
                <p class="nlp-kicker"><i class="fas fa-envelope-open-text" aria-hidden="true"></i> The <?php echo htmlspecialchars(aw_site_name()); ?> Letter</p>
                <h1>Words for the heart, delivered gently.</h1>
                <p class="nlp-lead">A letter from Angella once or twice a month: new poems and reflections, a verse for your week, and news of books, reading groups and gatherings. Free, and you can leave with one click.</p>
                <?php if ($nl_count >= 10): ?><p class="nlp-proof"><i class="fas fa-heart" aria-hidden="true"></i> Read by <?php echo number_format($nl_count); ?> people</p><?php endif; ?>
            </div>
            <div class="nlp-form" id="join">
                <?php if ($nl_flash_ok): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($nl_flash_ok); ?></div><?php endif; ?>
                <?php if ($nl_flash_err): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($nl_flash_err); ?></div><?php endif; ?>
                <?php if ($nl_state === 'active'): ?>
                    <div class="nlp-done"><i class="fas fa-circle-check" aria-hidden="true"></i><h2>You are subscribed</h2><p>The next letter will come to <strong><?php echo htmlspecialchars($nl_member); ?></strong>. Look for it in your inbox, and in Junk the first time.</p><a href="<?php echo SITE_URL; ?>/email_help.php">Keep our letters out of Junk</a></div>
                <?php else: ?>
                    <h2>Join the letter</h2>
                    <form method="POST" action="<?php echo SITE_URL; ?>/newsletter.php" class="nlp-signup">
                        <input type="hidden" name="redirect" value="/newsletter.php#join">
                        <div class="nlp-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                        <?php if ($nl_member !== ''): ?>
                            <input type="hidden" name="use_account" value="1">
                            <p>We will send it to your account email, <strong><?php echo htmlspecialchars($nl_member); ?></strong>.</p>
                            <?php if ($nl_state === 'pending'): ?><p class="nlp-note">We sent you a confirmation email. Press the button in it (check Junk too), or ask for it again here.</p><?php endif; ?>
                        <?php else: ?>
                            <label for="nlpName">First name <small>(so we can greet you)</small></label>
                            <input type="text" id="nlpName" name="name" maxlength="100" autocomplete="given-name">
                            <label for="nlpEmail">Email</label>
                            <input type="email" id="nlpEmail" name="email" required autocomplete="email" placeholder="you@example.com">
                        <?php endif; ?>
                        <button type="submit" class="nlp-btn"><i class="fas fa-paper-plane" aria-hidden="true"></i> <?php echo $nl_state === 'pending' ? 'Send the confirmation again' : 'Subscribe'; ?></button>
                        <p class="nlp-small">We email you once to confirm. We never share your address. Unsubscribe at any time from any letter.</p>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="nlp-what" aria-labelledby="nlpWhat">
            <h2 id="nlpWhat">What each letter brings</h2>
            <div class="nlp-grid">
                <div><i class="fas fa-feather" aria-hidden="true"></i><h3>New writing</h3><p>The newest poems and reflections, before anyone else hears about them.</p></div>
                <div><i class="fas fa-book-bible" aria-hidden="true"></i><h3>A verse for your week</h3><p>A Bible verse and a few quiet words to carry with you.</p></div>
                <div><i class="fas fa-book-open" aria-hidden="true"></i><h3>Books and reading groups</h3><p>New books, what we are reading together, and how to join in.</p></div>
                <div><i class="fas fa-calendar-days" aria-hidden="true"></i><h3>Gatherings</h3><p>Readings, launches and online meetings, with the details you need.</p></div>
            </div>
        </section>

        <?php if ($nl_past): ?>
        <section class="nlp-past" aria-labelledby="nlpPast">
            <h2 id="nlpPast">Past letters</h2>
            <div class="nlp-list">
                <?php foreach ($nl_past as $pl): ?>
                    <a class="nlp-item" href="<?php echo htmlspecialchars($pl['url']); ?>" target="_blank" rel="noopener">
                        <span class="nlp-date"><?php echo htmlspecialchars(date('j F Y', strtotime($pl['sent_at']))); ?></span>
                        <strong><?php echo htmlspecialchars($pl['subject']); ?></strong>
                        <?php if (!empty($pl['preheader'])): ?><span class="nlp-pre"><?php echo htmlspecialchars($pl['preheader']); ?></span><?php endif; ?>
                        <span class="nlp-read">Read the letter <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="nlp-faq" aria-labelledby="nlpFaq">
            <h2 id="nlpFaq">Good to know</h2>
            <details><summary>How often will I hear from you?</summary><p>Once or twice a month, never more than once a week.</p></details>
            <details><summary>I cannot find the confirmation email.</summary><p>Look in your Junk or Spam folder and mark it “Not spam”. <a href="<?php echo SITE_URL; ?>/email_help.php">Here is how, for each email service.</a></p></details>
            <details><summary>How do I stop the letters?</summary><p>Every letter has an Unsubscribe link at the bottom. One click, and you will not hear from us again.</p></details>
            <details><summary>What do you do with my address?</summary><p>We use it only to send the letter. We never sell or share it. See the <a href="<?php echo SITE_URL; ?>/privacy.php">privacy policy</a>.</p></details>
        </section>
    </main>
    <style>
    .nlp{max-width:1080px;margin:0 auto;padding:36px 16px 72px;color:var(--text,#3d2e2e)}
    .nlp-hero{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:32px;align-items:center;background:linear-gradient(135deg,#fbf1f0,#f2dedd);border:1px solid var(--border,#eadede);border-radius:28px;padding:40px}
    .nlp-kicker{margin:0;font-size:.8rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#9c5457}
    .nlp-hero h1{font-family:'Playfair Display',Georgia,serif;font-size:clamp(2rem,4.4vw,3rem);line-height:1.12;margin:10px 0 14px}
    .nlp-lead{font-size:1.12rem;line-height:1.65;margin:0}.nlp-proof{margin:16px 0 0;font-weight:600;color:#9c5457}
    .nlp-form{background:var(--card-bg,#fff);border-radius:22px;padding:26px;box-shadow:0 18px 50px rgba(61,46,46,.12)}
    .nlp-form h2{font-family:'Playfair Display',Georgia,serif;margin:0 0 14px;font-size:1.5rem}
    .nlp-signup label{display:block;font-weight:600;margin:10px 0 6px}
    .nlp-signup input[type=text],.nlp-signup input[type=email]{width:100%;box-sizing:border-box;min-height:48px;border-radius:12px;border:1px solid var(--border,#e2d2d2);padding:10px 14px;font:inherit;background:var(--card-bg,#fff);color:inherit}
    .nlp-btn{display:flex;justify-content:center;align-items:center;gap:8px;width:100%;min-height:50px;margin-top:16px;border:0;border-radius:999px;background:#9c5457;color:#fff;font:700 1rem Inter,system-ui,sans-serif;cursor:pointer}
    .nlp-btn:hover{background:#86464a}.nlp-btn:focus-visible{outline:3px solid #3d2e2e;outline-offset:2px}
    .nlp-small,.nlp-note{font-size:.85rem;color:var(--text-light,#6b5757);margin:12px 0 0}
    .nlp-hp{position:absolute!important;left:-10000px!important;width:1px;height:1px;overflow:hidden}
    .nlp-done{text-align:center}.nlp-done>i{font-size:2.4rem;color:#2f7a4a}.nlp-done h2{margin:8px 0}
    .nlp-what,.nlp-past,.nlp-faq{margin-top:44px}
    .nlp-what h2,.nlp-past h2,.nlp-faq h2{font-family:'Playfair Display',Georgia,serif;font-size:1.7rem;margin:0 0 18px}
    .nlp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}
    .nlp-grid>div{background:var(--card-bg,#fff);border:1px solid var(--border,#eadede);border-radius:18px;padding:20px}
    .nlp-grid i{font-size:1.3rem;color:#9c5457}.nlp-grid h3{margin:10px 0 6px;font-size:1.05rem}.nlp-grid p{margin:0;color:var(--text-light,#6b5757);line-height:1.6}
    .nlp-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
    .nlp-item{display:flex;flex-direction:column;gap:6px;padding:18px 20px;border-radius:18px;background:var(--card-bg,#fff);border:1px solid var(--border,#eadede);text-decoration:none;color:inherit;transition:transform .15s,box-shadow .15s}
    .nlp-item:hover,.nlp-item:focus-visible{transform:translateY(-2px);box-shadow:0 10px 26px rgba(61,46,46,.1)}
    .nlp-item strong{font:700 1.08rem/1.35 'Playfair Display',Georgia,serif}.nlp-date{font-size:.78rem;color:var(--text-light,#6b5757);text-transform:uppercase;letter-spacing:.06em}
    .nlp-pre{color:var(--text-light,#6b5757);font-size:.92rem}.nlp-read{margin-top:auto;font-weight:700;color:#9c5457;font-size:.9rem}
    .nlp-faq details{background:var(--card-bg,#fff);border:1px solid var(--border,#eadede);border-radius:14px;padding:4px 18px;margin-bottom:10px}
    .nlp-faq summary{cursor:pointer;font-weight:600;min-height:48px;display:flex;align-items:center}.nlp-faq p{margin:0 0 14px;line-height:1.6}
    [data-theme="dark"] .nlp{color:#f3e6e6}[data-theme="dark"] .nlp-hero{background:linear-gradient(135deg,#2e2224,#3a2a2c);border-color:#4a3739}
    [data-theme="dark"] .nlp-form,[data-theme="dark"] .nlp-grid>div,[data-theme="dark"] .nlp-item,[data-theme="dark"] .nlp-faq details{background:#2a2122;border-color:#4a3739}
    [data-theme="dark"] .nlp-kicker,[data-theme="dark"] .nlp-grid i,[data-theme="dark"] .nlp-read,[data-theme="dark"] .nlp-proof{color:#f0b9ba}
    @media (max-width:820px){.nlp-hero{grid-template-columns:1fr;padding:26px 20px}}
    @media (prefers-reduced-motion:reduce){.nlp-item{transition:none}.nlp-item:hover{transform:none}}
    </style>
    <?php
    require_once 'includes/footer.php';
    exit;
}

$error = '';
$success = '';

// ===== HANDLE SUBSCRIPTION =====
require_once 'includes/subscribe_control.php'; // J1: member email and subscription state
$aw_wants_json = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $name = trim(strip_tags((string)($_POST['name'] ?? '')));
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 100) : substr($name, 0, 100);
    // J1: a signed-in member subscribes with their account email (one click); a posted address
    // is ignored then, so nobody can sign up someone else from a member's session.
    if (!empty($_POST['use_account']) && isLoggedIn()) {
        $email = aw_member_email($db);
        if ($name === '') $name = (string)($_SESSION['name'] ?? '');
    }

    if (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!empty($_POST['website'])) {
        $success = 'Thank you!'; // N6: hidden honeypot field filled in: a bot, nothing is stored or sent
    } elseif (!aw_nl_rate_ok('subscribe_ip_' . ($_SERVER['REMOTE_ADDR'] ?? ''), 5, 600)) {
        $error = 'Too many attempts from your connection. Please try again in a few minutes.'; // N6
    } else {
        // F176: double opt-in; the address is activated from the emailed link (?confirm= handler above).
        $optin = newsletter_request_optin($db, $email, $name);
        if ($optin['ok']) {
            $success = $optin['message'];
        } else {
            $error = $optin['message'];
        }
    }
}

// ===== J1: ANSWER IN PLACE WHEN ASKED (the subscribe control's inline submit) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aw_wants_json) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    // the real state only for a member's own account address; a guest always gets the neutral
    // answer, so the form never reveals whether an address is on the list (N6)
    $aw_own = !empty($_POST['use_account']) && isLoggedIn();
    $aw_state = ($error === '' && $aw_own && filter_var($email, FILTER_VALIDATE_EMAIL)) ? aw_subscription_state($db, $email) : 'pending';
    if ($error === '' && $aw_state === 'active' && !empty($_POST['use_account'])) {
        $success = 'You are already subscribed. Thank you!';
    }
    echo json_encode(['ok' => $error === '', 'message' => $error !== '' ? $error : $success,
                      'state' => $error === '' ? ($aw_state === 'none' ? 'pending' : $aw_state) : 'none']);
    exit;
}

// ===== DETERMINE WHERE TO REDIRECT =====
$redirect = isset($_POST['redirect']) ? $_POST['redirect'] : '/index.php';
// S10: only same-site paths. SITE_URL . $redirect must not let the value change the host
// (e.g. "@evil.example/" turns SITE_URL into userinfo). Require a leading "/" and no
// backslash, CR/LF or other control characters.
if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/' || preg_match('/[\\\\\x00-\x1f\x7f]/', $redirect) /* the earlier pattern did not compile, so this check never ran */) {
    $redirect = '/index.php';
}

// Store message in session to display after redirect
if (session_status() !== PHP_SESSION_ACTIVE) session_start(); // auth.php has usually started it already
if ($error) {
    $_SESSION['newsletter_error'] = $error;
} elseif ($success) {
    $_SESSION['newsletter_success'] = $success;
}

// Redirect back to the page
header('Location: ' . SITE_URL . $redirect);
exit;