<?php
require_once '../config/db.php';
require_once '../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

$full_name = trim($_POST['full_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$role = trim($_POST['role'] ?? 'employee');
$is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

if ($full_name === '' || $username === '' || $password === '') {
    jsonResponse(false, 'Full name, username, and password are required');
}

if (!in_array($role, ['admin', 'employee'])) {
    jsonResponse(false, 'Invalid role');
}

$checkStmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$checkStmt->bind_param('s', $username);
$checkStmt->execute();
if ($checkStmt->get_result()->num_rows > 0) {
    jsonResponse(false, 'Username already exists');
}

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare('INSERT INTO users (full_name, username, password, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
$stmt->bind_param('ssssi', $full_name, $username, $hashedPassword, $role, $is_active);

if ($stmt->execute()) {
    jsonResponse(true, 'User added successfully');
}

jsonResponse(false, 'Failed to add user');

