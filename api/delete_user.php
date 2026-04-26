<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

if (session_status() === PHP_SESSION_NONE) session_start();

$loggedInUserId = isset($_SESSION['user_id'])
    ? (int)$_SESSION['user_id']
    : (int)($_POST['admin_user_id'] ?? 0);

if ($loggedInUserId <= 0) {
    jsonResponse(false, 'Authentication required', null, 401);
}

requirePermission($conn, $loggedInUserId, 'manage_users');

$me = getLoggedInUser($conn, $loggedInUserId);
if (!$me) {
    jsonResponse(false, 'Logged-in user not found', null, 401);
}

$targetUserId = (int)($_POST['id'] ?? 0);
if ($targetUserId <= 0) {
    jsonResponse(false, 'Invalid user', null, 400);
}

if ($targetUserId === $loggedInUserId) {
    jsonResponse(false, 'You cannot delete yourself', null, 400);
}

$stmt = $conn->prepare("
    SELECT id, role, company_id, branch_id, created_by
    FROM users WHERE id = ? LIMIT 1
");
$stmt->bind_param('i', $targetUserId);
$stmt->execute();
$target = $stmt->get_result()->fetch_assoc();

if (!$target) {
    jsonResponse(false, 'Target user not found', null, 404);
}

// Role hierarchy check
$myLevel     = getRoleLevel($me['role']);
$targetLevel = getRoleLevel($target['role']);

if ($targetLevel >= $myLevel) {
    jsonResponse(false, 'You cannot delete a user of equal or higher rank', null, 403);
}

// Company scope
if ($me['role'] !== 'super_admin' && (int)$me['company_id'] !== (int)$target['company_id']) {
    jsonResponse(false, 'Cross-company deletion not allowed', null, 403);
}

// Branch manager can only delete users they personally created
if ($me['role'] === 'branch_manager') {
    if ((int)$me['branch_id'] !== (int)$target['branch_id']) {
        jsonResponse(false, 'You can only manage users in your branch', null, 403);
    }
    if ((int)$target['created_by'] !== $loggedInUserId) {
        jsonResponse(false, 'You can only delete users you created', null, 403);
    }
}

$delete = $conn->prepare("DELETE FROM users WHERE id = ?");
$delete->bind_param('i', $targetUserId);

if (!$delete->execute()) {
    jsonResponse(false, 'Failed to delete user', null, 500);
}

logActivity(
    $conn,
    (int)$me['company_id'],
    (int)$me['branch_id'],
    $loggedInUserId,
    'delete_user',
    'users',
    $targetUserId,
    "Deleted user ID $targetUserId"
);

jsonResponse(true, 'User deleted successfully');