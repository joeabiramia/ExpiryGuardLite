<?php
require_once '../config/db.php';
require_once '../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    jsonResponse(false, 'User ID is required');
}

$usageCheck = $conn->prepare('SELECT COUNT(*) AS total FROM products WHERE entered_by = ? OR removed_by = ?');
$usageCheck->bind_param('ii', $id, $id);
$usageCheck->execute();
$used = $usageCheck->get_result()->fetch_assoc();

if ((int)$used['total'] > 0) {
    jsonResponse(false, 'Cannot delete user because this user is linked to product records. Disable the account instead.');
}

$stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
$stmt->bind_param('i', $id);

if ($stmt->execute()) {
    jsonResponse(true, 'User deleted successfully');
}

jsonResponse(false, 'Failed to delete user');

