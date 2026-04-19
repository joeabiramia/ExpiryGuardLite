<?php
session_start();

require_once '../config/db.php';
require_once '../config/helpers.php';

/*
|--------------------------------------------------------------------------
| Only POST allowed
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

/*
|--------------------------------------------------------------------------
| Session check
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    jsonResponse(false, 'Session expired. Please login again.');
}

$loggedInUserId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Permission check
|--------------------------------------------------------------------------
|
| Only users with manage_users can update users
|
*/

requirePermission($conn, $loggedInUserId, 'manage_users');

/*
|--------------------------------------------------------------------------
| Logged-in user info
|--------------------------------------------------------------------------
*/

$me = getLoggedInUser($conn, $loggedInUserId);

if (!$me) {
    jsonResponse(false, 'Logged-in user not found');
}

$myRole      = $me['role'];
$myCompanyId = (int) ($me['company_id'] ?? 0);
$myBranchId  = (int) ($me['branch_id'] ?? 0);
$myLevel     = getRoleLevel($myRole);

/*
|--------------------------------------------------------------------------
| Incoming data
|--------------------------------------------------------------------------
*/

$id         = (int) ($_POST['id'] ?? 0);
$full_name  = trim($_POST['full_name'] ?? '');
$username   = trim($_POST['username'] ?? '');
$password   = $_POST['password'] ?? '';
$role       = trim($_POST['role'] ?? 'employee');
$is_active  = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 1;

$company_id = isset($_POST['company_id']) ? (int) $_POST['company_id'] : 0;
$branch_id  = isset($_POST['branch_id']) ? (int) $_POST['branch_id'] : 0;

/*
|--------------------------------------------------------------------------
| Basic validation
|--------------------------------------------------------------------------
*/

if ($id <= 0 || $full_name === '' || $username === '') {
    jsonResponse(false, 'ID, full name and username are required');
}

$allowedRoles = [
    'super_admin',
    'company_admin',
    'branch_manager',
    'employee',
    'viewer'
];

if (!in_array($role, $allowedRoles)) {
    jsonResponse(false, 'Invalid role selected');
}

/*
|--------------------------------------------------------------------------
| Cannot edit yourself role dangerously
|--------------------------------------------------------------------------
|
| Optional: allow editing profile later separately
|
*/

if ($id === $loggedInUserId && $role !== $myRole) {
    jsonResponse(false, 'You cannot change your own role');
}

/*
|--------------------------------------------------------------------------
| Get target user
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        role,
        company_id,
        branch_id
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    jsonResponse(false, 'Target user not found');
}

$target = $result->fetch_assoc();

$targetLevel = getRoleLevel($target['role']);

/*
|--------------------------------------------------------------------------
| Role hierarchy protection
|--------------------------------------------------------------------------
|
| Cannot edit same or higher role users
|
*/

if ($targetLevel >= $myLevel && $id !== $loggedInUserId) {
    jsonResponse(false, 'You cannot edit this user');
}

/*
|--------------------------------------------------------------------------
| New role escalation protection
|--------------------------------------------------------------------------
|
| Cannot promote someone above your own level
|
*/

$newRoleLevel = getRoleLevel($role);

if ($newRoleLevel >= $myLevel && $myRole !== 'super_admin') {
    jsonResponse(false, 'You cannot assign this role');
}

/*
|--------------------------------------------------------------------------
| Role creation restrictions
|--------------------------------------------------------------------------
*/

if (!canCreateRole($myRole, $role) && $role !== $target['role']) {
    jsonResponse(false, 'You are not allowed to assign this role');
}

/*
|--------------------------------------------------------------------------
| Company scope protection
|--------------------------------------------------------------------------
*/

if ($myRole !== 'super_admin') {
    if ($company_id !== $myCompanyId) {
        jsonResponse(false, 'You cannot move users outside your company');
    }
}

/*
|--------------------------------------------------------------------------
| Branch Manager restriction
|--------------------------------------------------------------------------
*/

if ($myRole === 'branch_manager') {
    if ($branch_id !== $myBranchId) {
        jsonResponse(false, 'You can only manage users inside your branch');
    }
}

/*
|--------------------------------------------------------------------------
| Role target validation
|--------------------------------------------------------------------------
*/

if ($role === 'super_admin') {
    if ($company_id <= 0) {
        jsonResponse(false, 'Company is required for Super Admin');
    }

    if ($branch_id <= 0) {
        $branch_id = null;
    }
}

if ($role === 'company_admin') {
    if ($company_id <= 0) {
        jsonResponse(false, 'Company is required for Company Admin');
    }

    if ($branch_id <= 0) {
        $branch_id = null;
    }
}

if (in_array($role, ['branch_manager', 'employee'])) {
    if ($company_id <= 0) {
        jsonResponse(false, 'Company is required');
    }

    if ($branch_id <= 0) {
        jsonResponse(false, 'Branch is required');
    }
}

if ($role === 'viewer') {
    if ($company_id <= 0) {
        jsonResponse(false, 'Company is required for Viewer');
    }

    if ($branch_id <= 0) {
        $branch_id = null;
    }
}

/*
|--------------------------------------------------------------------------
| Username duplicate check
|--------------------------------------------------------------------------
*/

$checkStmt = $conn->prepare("
    SELECT id
    FROM users
    WHERE username = ?
      AND id != ?
    LIMIT 1
");

$checkStmt->bind_param("si", $username, $id);
$checkStmt->execute();

$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {
    jsonResponse(false, 'Username already used by another account');
}

/*
|--------------------------------------------------------------------------
| Update user
|--------------------------------------------------------------------------
*/

if ($password !== '') {
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $update = $conn->prepare("
        UPDATE users
        SET
            full_name = ?,
            username = ?,
            password = ?,
            role = ?,
            company_id = ?,
            branch_id = ?,
            is_active = ?
        WHERE id = ?
    ");

    $update->bind_param(
        "ssssiiii",
        $full_name,
        $username,
        $hashedPassword,
        $role,
        $company_id,
        $branch_id,
        $is_active,
        $id
    );

} else {

    $update = $conn->prepare("
        UPDATE users
        SET
            full_name = ?,
            username = ?,
            role = ?,
            company_id = ?,
            branch_id = ?,
            is_active = ?
        WHERE id = ?
    ");

    $update->bind_param(
        "sssiiii",
        $full_name,
        $username,
        $role,
        $company_id,
        $branch_id,
        $is_active,
        $id
    );
}

if (!$update->execute()) {
    jsonResponse(false, 'Failed to update user', [
        'error' => $update->error
    ]);
}

jsonResponse(true, 'User updated successfully');