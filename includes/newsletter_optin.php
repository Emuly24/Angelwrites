<?php
// F176: double opt-in for the newsletter. Used by newsletter.php (footer form) and the
// homepage form in index.php. A new or lapsed address is stored inactive and only becomes
// active when its owner clicks the emailed link (newsletter.php?confirm=<token>), so nobody
// can subscribe someone else. The token is the subscriber's existing unsubscribe_token.
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/newsletter.php'; // N6: rate limit and the branded confirmation email, wherever this is used

/**
 * Returns ['ok' => bool, 'message' => string].
 */
function newsletter_request_optin($db, $email, $name = '') {
    $stmt = $db->prepare("SELECT id, is_active, unsubscribe_token FROM newsletter WHERE email = ?");
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    // N6: the answer no longer says whether an address is on the list, and a confirmation email is
    // sent at most once every 10 minutes per address (the form could be used to flood an inbox).
    $neutral = ['ok' => true, 'message' => 'Thank you! Please check your inbox and click the confirmation link. (If you are already subscribed, there is nothing more to do.)'];
    if ($existing && $existing['is_active'] == 1) {
        return $neutral;
    }
    if (function_exists('aw_nl_rate_ok') && !aw_nl_rate_ok('optin_' . md5(strtolower($email)), 1, 600)) {
        return $neutral;
    }

    if ($existing) {
        $token = $existing['unsubscribe_token'];
        if (!$token) {
            $token = bin2hex(random_bytes(32));
            $db->prepare("UPDATE newsletter SET unsubscribe_token = ? WHERE id = ?")->execute([$token, $existing['id']]);
        }
    } else {
        $token = bin2hex(random_bytes(32));
        $stmt = $db->prepare("INSERT INTO newsletter (email, name, is_active, unsubscribe_token) VALUES (?, ?, 0, ?)");
        if (!$stmt->execute([$email, $name, $token])) {
            return ['ok' => false, 'message' => 'Something went wrong. Please try again.'];
        }
    }

    $link = SITE_URL . '/newsletter.php?confirm=' . urlencode($token);
    $subject = 'Please confirm your AngelWrites newsletter subscription';
    if (function_exists('aw_nl_render')) { // N6: the branded email when includes/newsletter.php is loaded
        $m = aw_nl_render($db, $subject, '<p>Hello {{first_name}},</p><p>Please confirm that you would like to receive the AngelWrites newsletter.</p>'
            . '<p><a href="' . htmlspecialchars($link) . '" style="display:inline-block;background:#DBA1A2;color:#fff;padding:12px 22px;border-radius:999px;text-decoration:none;font-family:Arial,sans-serif;">Yes, subscribe me</a></p>'
            . '<p style="font-size:14px;color:#6b5a5a;">If you did not ask for this, ignore this email and you will not be subscribed.</p>',
            ['email' => $email, 'name' => $name], ['preview' => true, 'signature' => true]);
        aw_nl_mail($email, $subject, $m['html'], $m['text']);
    } else {
        $body = "Hello " . ($name ?: 'friend') . ",\n\n"
              . "Please confirm that you want to receive the AngelWrites newsletter by opening this link:\n\n"
              . $link . "\n\n"
              . "If you did not ask for this, ignore this email and you will not be subscribed.\n\n"
              . "Blessings,\nAngella Bottoman\nAngelWrites";
        sendEmail($email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites', false);
    }

    return $neutral;
}
