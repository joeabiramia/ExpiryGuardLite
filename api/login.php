<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

session_start();
require_once '../config/db.php';
require_once '../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    jsonResponse(false, 'Username and password are required');
}

$stmt = $conn->prepare('SELECT id, full_name, username, password, role, is_active FROM users WHERE username = ? LIMIT 1');
$stmt->bind_param('s', $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    jsonResponse(false, 'User not found');
}

if ((int)$user['is_active'] !== 1) {
    jsonResponse(false, 'User account is disabled');
}

if (!password_verify($password, $user['password'])) {
    jsonResponse(false, 'Incorrect password');
}

$_SESSION['user_id'] = $user['id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['username'] = $user['username'];
$_SESSION['role'] = $user['role'];

jsonResponse(true, 'Login successful', [
    'user_id' => (int)$user['id'],
    'full_name' => $user['full_name'],
    'username' => $user['username'],
    'role' => $user['role']
]);