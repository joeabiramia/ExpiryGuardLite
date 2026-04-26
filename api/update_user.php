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

$myRole      = $me['role'];
$myCompanyId = (int)($me['company_id'] ?? 0);
$myBranchId  = (int)($me['branch_id']  ?? 0);
$myLevel     = getRoleLevel($myRole);

$id         = (int)($_POST['id']        ?? 0);
$full_name  = trim($_POST['full_name']  ?? '');
$username   = trim($_POST['username']   ?? '');
$password   = $_POST['password'] ?? '';
$role       = trim($_POST['role']       ?? 'employee');
$email      = trim($_POST['email']      ?? '');
$phone      = trim($_POST['phone']      ?? '');
$is_active  = ((int)($_POST['is_active'] ?? 1) === 1) ? 1 : 0;
$company_id = (int)($_POST['company_id'] ?? 0);
$branch_id  = (int)($_POST['branch_id']  ?? 0);

if ($id <= 0 || $full_name === '' || $username === '') {
    jsonResponse(false, 'ID, full name, and username are required', null, 400);
}

$allowedRoles = ['super_admin', 'company_admin', 'branch_manager', 'employee', 'viewer'];
if (!in_array($role, $allowedRoles, true)) {
    jsonResponse(false, 'Invalid role', null, 400);
}

if ($id === $loggedInUserId && $role !== $myRole) {
    jsonResponse(false, 'You cannot change your own role', null, 400);
}

// Fetch target user — close statement immediately after
$tStmt = $conn->prepare("SELECT id, role, company_id, branch_id, created_by FROM users WHERE id = ? LIMIT 1");
$tStmt->bind_param('i', $id);
$tStmt->execute();
$tRes   = $tStmt->get_result();
$target = $tRes->fetch_assoc();
$tRes->free();
$tStmt->close();

if (!$target) {
    jsonResponse(false, 'Target user not found', null, 404);
}

$targetLevel  = getRoleLevel($target['role']);
$newRoleLevel = getRoleLevel($role);

if ($targetLevel >= $myLevel && $id !== $loggedInUserId) {
    jsonResponse(false, 'You cannot edit a user of equal or higher rank', null, 403);
}
if ($newRoleLevel >= $myLevel && $myRole !== 'super_admin') {
    jsonResponse(false, 'You cannot assign this role', null, 403);
}
if (!canCreateRole($myRole, $role) && $role !== $target['role']) {
    jsonResponse(false, 'You are not allowed to assign this role', null, 403);
}
if ($myRole !== 'super_admin' && $company_id !== $myCompanyId) {
    jsonResponse(false, 'You cannot move users outside your company', null, 403);
}
if ($myRole === 'branch_manager') {
    if ($branch_id !== $myBranchId) {
        jsonResponse(false, 'You can only manage users in your branch', null, 403);
    }
    if ((int)$target['created_by'] !== $loggedInUserId && $id !== $loggedInUserId) {
        jsonResponse(false, 'You can only edit users you created', null, 403);
    }
}

// Nullable branch for roles that don't require one
if (in_array($role, ['super_admin', 'company_admin', 'viewer'], true) && $branch_id <= 0) {
    $branch_id = null;
}
if (in_array($role, ['branch_manager', 'employee'], true) && $branch_id <= 0) {
    jsonResponse(false, 'Branch is required for this role', null, 400);
}

// Username duplicate check — close statement before running UPDATE
$chkStmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
$chkStmt->bind_param('si', $username, $id);
$chkStmt->execute();
$chkRes   = $chkStmt->get_result();
$chkCount = $chkRes->num_rows;
$chkRes->free();
$chkStmt->close();

if ($chkCount > 0) {
    jsonResponse(false, 'Username already taken by another account', null, 409);
}

// Run UPDATE
if ($password !== '') {
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $upd = $conn->prepare("
        UPDATE users
        SET full_name=?, username=?, password=?, role=?,
            email=?, phone=?, company_id=?, branch_id=?, is_active=?
        WHERE id=?
    ");
    // 6 strings + 4 integers = ssssssiiii
    $upd->bind_param('ssssssiiii',
        $full_name, $username, $hashedPassword, $role,
        $email, $phone, $company_id, $branch_id, $is_active, $id);
} else {
    $upd = $conn->prepare("
        UPDATE users
        SET full_name=?, username=?, role=?,
            email=?, phone=?, company_id=?, branch_id=?, is_active=?
        WHERE id=?
    ");
    // 5 strings + 4 integers = sssssiiii
    $upd->bind_param('sssssiiii',
        $full_name, $username, $role,
        $email, $phone, $company_id, $branch_id, $is_active, $id);
}

if (!$upd->execute()) {
    jsonResponse(false, 'Failed to update user', null, 500);
}
$upd->close();

logActivity($conn, $myCompanyId, $myBranchId, $loggedInUserId,
    'update_user', 'users', $id, "Updated user: $username");

jsonResponse(true, 'User updated successfully');
