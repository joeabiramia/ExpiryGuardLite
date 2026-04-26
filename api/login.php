<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    jsonResponse(false, 'Username and password are required', null, 400);
}

$stmt = $conn->prepare("
    SELECT id, full_name, username, password, role, company_id, branch_id, is_active
    FROM users
    WHERE username = ?
    LIMIT 1
");
$stmt->bind_param('s', $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    jsonResponse(false, 'Invalid credentials', null, 401);
}

if ((int)$user['is_active'] !== 1) {
    jsonResponse(false, 'Account is disabled', null, 403);
}

if (!password_verify($password, $user['password'])) {
    jsonResponse(false, 'Invalid credentials', null, 401);
}

// Regenerate session to prevent session fixation
session_regenerate_id(true);

$_SESSION['user_id']    = $user['id'];
$_SESSION['full_name']  = $user['full_name'];
$_SESSION['username']   = $user['username'];
$_SESSION['role']       = $user['role'];
$_SESSION['company_id'] = $user['company_id'];
$_SESSION['branch_id']  = $user['branch_id'];

// Track last login
$upd = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
$upd->bind_param('i', $user['id']);
$upd->execute();

// Generate a secure token for mobile app auth (30-day expiry)
$token      = bin2hex(random_bytes(32));
$expiresAt  = date('Y-m-d H:i:s', strtotime('+30 days'));
$tokenStmt  = $conn->prepare("
    INSERT INTO login_tokens (user_id, token, expires_at)
    VALUES (?, ?, ?)
");
$tokenStmt->bind_param('iss', $user['id'], $token, $expiresAt);
$tokenStmt->execute();

// Keep only the 5 most recent tokens per user
$cleanStmt = $conn->prepare("
    DELETE FROM login_tokens
    WHERE user_id = ? AND id NOT IN (
        SELECT id FROM (SELECT id FROM login_tokens WHERE user_id = ? ORDER BY created_at DESC LIMIT 5) t
    )
");
$cleanStmt->bind_param('ii', $user['id'], $user['id']);
$cleanStmt->execute();

jsonResponse(true, 'Login successful', [
    'user_id'    => (int)$user['id'],
    'full_name'  => $user['full_name'],
    'username'   => $user['username'],
    'role'       => $user['role'],
    'company_id' => (int)$user['company_id'],
    'branch_id'  => $user['branch_id'] !== null ? (int)$user['branch_id'] : null,
    'token'      => $token,
    'expires_at' => $expiresAt,
]);