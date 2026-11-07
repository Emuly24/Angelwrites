<?php
require_once 'vendor/autoload.php';
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/load_env.php';
require_once 'includes/auth.php';

// F102: Google/Facebook sign-in needs outbound HTTPS from the host to the providers, which is
// not confirmed on InfinityFree. It is off unless SOCIAL_LOGIN_ENABLED=1 is set in .env
// (decision D-M3), and any failure returns to the login page instead of printing the error.
if (getenv('SOCIAL_LOGIN_ENABLED') !== '1') {
    header('Location: ' . SITE_URL . '/login.php?social=unavailable');
    exit;
}

use Hybridauth\Hybridauth;
use Hybridauth\Provider\Google;
use Hybridauth\Provider\Facebook;

$config = [
    'callback' => SITE_URL . '/social_login.php',
    'providers' => [
        'Google' => [
            'enabled' => true,
            'keys' => [
                'id' => getenv('GOOGLE_CLIENT_ID'),
                'secret' => getenv('GOOGLE_CLIENT_SECRET')
            ],
        ],
        'Facebook' => [
            'enabled' => true,
            'keys' => [
                'id' => getenv('FACEBOOK_APP_ID'),
                'secret' => getenv('FACEBOOK_APP_SECRET')
            ],
        ],
    ],
];

$hybridauth = new Hybridauth($config);
$provider = isset($_GET['provider']) ? $_GET['provider'] : '';

if ($provider) {
    try {
        $adapter = $hybridauth->authenticate($provider);
        $userProfile = $adapter->getUserProfile();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$userProfile->email]);
        $user = $stmt->fetch();
        if ($user) {
            // F102: set the whole session like login.php (only user_id was set: no role or name)
            $full = $db->prepare("SELECT id, username, name, first_name, last_name, role FROM users WHERE id = ?");
            $full->execute([$user['id']]);
            $fu = $full->fetch(PDO::FETCH_ASSOC);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $fu['id'];
            $_SESSION['username'] = $fu['username'];
            $_SESSION['name'] = awDisplayName($fu);
            $_SESSION['role'] = $fu['role'];
            header('Location: ' . SITE_URL . '/dashboard.php');
            exit;
        } else {
            $username = preg_replace('/[^a-zA-Z0-9_]/', '', $userProfile->displayName ?? $userProfile->email);
            $username .= rand(100, 999);
            $stmt = $db->prepare("INSERT INTO users (username, email, password, role, is_verified, created_at) VALUES (?, ?, ?, 'reader', 1, CURRENT_TIMESTAMP)");
            $stmt->execute([$username, $userProfile->email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
            $user_id = $db->lastInsertId();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user_id;
            $_SESSION['username'] = $username;   // F102
            $_SESSION['name'] = $userProfile->displayName ?: $username;
            $_SESSION['role'] = 'reader';
            $ref_code = strtoupper(substr(md5($username . time()), 0, 8));
            $db->prepare("UPDATE users SET referral_code = ? WHERE id = ?")->execute([$ref_code, $user_id]);
            header('Location: ' . SITE_URL . '/dashboard.php');
            exit;
        }
    } catch (Throwable $e) {
        error_log('Social login error: ' . $e->getMessage()); // F102: was printed to the visitor
        header('Location: ' . SITE_URL . '/login.php?social=unavailable');
        exit;
    }
} else {
    echo '<a href="social_login.php?provider=Google">Login with Google</a><br>';
    echo '<a href="social_login.php?provider=Facebook">Login with Facebook</a><br>';
}
?>