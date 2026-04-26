<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Revoke mobile token if present
$token = '';
if (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
    $token = trim($_SERVER['HTTP_X_AUTH_TOKEN']);
} elseif (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth = trim($_SERVER['HTTP_AUTHORIZATION']);
    if (stripos($auth, 'Bearer ') === 0) {
        $token = trim(substr($auth, 7));
    }
}

if ($token !== '') {
    $stmt = $conn->prepare("UPDATE login_tokens SET revoked = 1 WHERE token = ?");
    $stmt->bind_param('s', $token);
    $stmt->execute();
}

// Destroy web session
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
session_destroy();

jsonResponse(true, 'Logged out successfully');