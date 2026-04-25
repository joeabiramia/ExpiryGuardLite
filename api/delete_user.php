<?php
session_start();

require_once '../config/db.php';
require_once '../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

if (!isset($_SESSION['user_id'])) {
    jsonResponse(false, 'Please login again');
}

$loggedInUserId = (int) $_SESSION['user_id'];

requirePermission($conn, $loggedInUserId, 'manage_users');

$targetUserId = (int) ($_POST['id'] ?? 0);

if ($targetUserId <= 0) {
    jsonResponse(false, 'Invalid user');
}

/*
|--------------------------------------------------------------------------
| Cannot delete yourself
|--------------------------------------------------------------------------
*/

if ($targetUserId === $loggedInUserId) {
    jsonResponse(false, 'You cannot delete yourself');
}

/*
|--------------------------------------------------------------------------
| Get logged-in user
|--------------------------------------------------------------------------
*/

$me = getLoggedInUser($conn, $loggedInUserId);

if (!$me) {
    jsonResponse(false, 'User not found');
}

/*
|--------------------------------------------------------------------------
| Get target user
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT id, role, company_id, branch_id
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $targetUserId);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    jsonResponse(false, 'Target user not found');
}

$target = $result->fetch_assoc();

/*
|--------------------------------------------------------------------------
| Role hierarchy check
|--------------------------------------------------------------------------
*/

$myLevel = getRoleLevel($me['role']);
$targetLevel = getRoleLevel($target['role']);

if ($targetLevel >= $myLevel) {
    jsonResponse(false, 'You cannot delete this user');
}

/*
|--------------------------------------------------------------------------
| Company scope check
|--------------------------------------------------------------------------
*/

if ($me['role'] !== 'super_admin') {
    if ((int)$me['company_id'] !== (int)$target['company_id']) {
        jsonResponse(false, 'Cross-company deletion not allowed');
    }
}

/*
|--------------------------------------------------------------------------
| Branch manager branch restriction
|--------------------------------------------------------------------------
*/

if ($me['role'] === 'branch_manager') {
    if ((int)$me['branch_id'] !== (int)$target['branch_id']) {
        jsonResponse(false, 'You can only manage users in your branch');
    }
}

/*
|--------------------------------------------------------------------------
| Delete user
|--------------------------------------------------------------------------
*/

$delete = $conn->prepare("
    DELETE FROM users
    WHERE id = ?
");

$delete->bind_param("i", $targetUserId);

if (!$delete->execute()) {
    header("Location: ../admin/users.php?error=user_delete_failed");
    exit;
}

header("Location: ../admin/users.php?success=user_deleted");
exit;