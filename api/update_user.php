<?php
require_once '../config/db.php';
require_once '../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

$id = (int)($_POST['id'] ?? 0);
$full_name = trim($_POST['full_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$role = trim($_POST['role'] ?? 'employee');
$is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
$password = $_POST['password'] ?? '';

if ($id <= 0 || $full_name === '' || $username === '') {
    jsonResponse(false, 'ID, full name, and username are required');
}

if (!in_array($role, ['admin', 'employee'])) {
    jsonResponse(false, 'Invalid role');
}

$checkStmt = $conn->prepare('SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1');
$checkStmt->bind_param('si', $username, $id);
$checkStmt->execute();
if ($checkStmt->get_result()->num_rows > 0) {
    jsonResponse(false, 'Username already used by another account');
}

if ($password !== '') {
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET full_name = ?, username = ?, password = ?, role = ?, is_active = ? WHERE id = ?');
    $stmt->bind_param('ssssii', $full_name, $username, $hashedPassword, $role, $is_active, $id);
} else {
    $stmt = $conn->prepare('UPDATE users SET full_name = ?, username = ?, role = ?, is_active = ? WHERE id = ?');
    $stmt->bind_param('sssii', $full_name, $username, $role, $is_active, $id);
}

if ($stmt->execute()) {
    jsonResponse(true, 'User updated successfully');
}

jsonResponse(false, 'Failed to update user');
