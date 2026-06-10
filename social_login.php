<?php
require_once 'vendor/autoload.php';
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/load_env.php';

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
            $_SESSION['user_id'] = $user['id'];
            header('Location: ' . SITE_URL . '/dashboard.php');
            exit;
        } else {
            $username = preg_replace('/[^a-zA-Z0-9_]/', '', $userProfile->displayName ?? $userProfile->email);
            $username .= rand(100, 999);
            $stmt = $db->prepare("INSERT INTO users (username, email, password, role, is_verified, created_at) VALUES (?, ?, ?, 'reader', 1, CURRENT_TIMESTAMP)");
            $stmt->execute([$username, $userProfile->email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
            $user_id = $db->lastInsertId();
            $_SESSION['user_id'] = $user_id;
            $ref_code = strtoupper(substr(md5($username . time()), 0, 8));
            $db->prepare("UPDATE users SET referral_code = ? WHERE id = ?")->execute([$ref_code, $user_id]);
            header('Location: ' . SITE_URL . '/dashboard.php');
            exit;
        }
    } catch (Exception $e) {
        die('Social login error: ' . $e->getMessage());
    }
} else {
    echo '<a href="social_login.php?provider=Google">Login with Google</a><br>';
    echo '<a href="social_login.php?provider=Facebook">Login with Facebook</a><br>';
}
?>