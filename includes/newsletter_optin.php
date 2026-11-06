<?php
// F176: double opt-in for the newsletter. Used by newsletter.php (footer form) and the
// homepage form in index.php. A new or lapsed address is stored inactive and only becomes
// active when its owner clicks the emailed link (newsletter.php?confirm=<token>), so nobody
// can subscribe someone else. The token is the subscriber's existing unsubscribe_token.
require_once __DIR__ . '/mail_helper.php';

/**
 * Returns ['ok' => bool, 'message' => string].
 */
function newsletter_request_optin($db, $email, $name = '') {
    $stmt = $db->prepare("SELECT id, is_active, unsubscribe_token FROM newsletter WHERE email = ?");
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing && $existing['is_active'] == 1) {
        return ['ok' => false, 'message' => 'This email is already subscribed.'];
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
    $body = "Hello " . ($name ?: 'friend') . ",\n\n"
          . "Please confirm that you want to receive the AngelWrites newsletter by opening this link:\n\n"
          . $link . "\n\n"
          . "If you did not ask for this, ignore this email and you will not be subscribed.\n\n"
          . "Blessings,\nAngella Bottoman\nAngelWrites";
    sendEmail($email, 'Please confirm your AngelWrites newsletter subscription', $body, 'angelwrites@zohomail.com', 'AngelWrites', false);

    return ['ok' => true, 'message' => 'Almost done: please check your inbox and click the confirmation link to start receiving the newsletter.'];
}
