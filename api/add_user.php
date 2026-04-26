<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

if (session_status() === PHP_SESSION_NONE) session_start();

// Accept both session (web) and admin_user_id param (mobile legacy)
$loggedInUserId = 0;
if (isset($_SESSION['user_id'])) {
    $loggedInUserId = (int)$_SESSION['user_id'];
} else {
    $loggedInUserId = (int)($_POST['admin_user_id'] ?? 0);
}

if ($loggedInUserId <= 0) {
    jsonResponse(false, 'Authentication required', null, 401);
}

requirePermission($conn, $loggedInUserId, 'manage_users');

$me = getLoggedInUser($conn, $loggedInUserId);
if (!$me) {
    jsonResponse(false, 'Logged-in user not found', null, 401);
}

$creatorRole      = $me['role'];
$creatorCompanyId = (int)($me['company_id'] ?? 0);
$creatorBranchId  = (int)($me['branch_id']  ?? 0);

$full_name = trim($_POST['full_name'] ?? '');
$username  = trim($_POST['username']  ?? '');
$password  = $_POST['password'] ?? '';
$role      = trim($_POST['role']      ?? 'employee');
$email     = trim($_POST['email']     ?? '');
$phone     = trim($_POST['phone']     ?? '');
$is_active = ((int)($_POST['is_active'] ?? 1) === 1) ? 1 : 0;
$company_id = (int)($_POST['company_id'] ?? 0);
$branch_id  = (int)($_POST['branch_id']  ?? 0);

if ($full_name === '' || $username === '' || $password === '') {
    jsonResponse(false, 'Full name, username, and password are required', null, 400);
}

$allowedRoles = ['super_admin', 'company_admin', 'branch_manager', 'employee', 'viewer'];
if (!in_array($role, $allowedRoles, true)) {
    jsonResponse(false, 'Invalid role', null, 400);
}

if (!canCreateRole($creatorRole, $role)) {
    jsonResponse(false, 'You are not allowed to create this role', null, 403);
}

// Company / branch scope enforcement
if ($creatorRole !== 'super_admin') {
    if ($company_id !== $creatorCompanyId) {
        jsonResponse(false, 'You cannot create users outside your company', null, 403);
    }
}

if ($creatorRole === 'branch_manager') {
    if ($branch_id !== $creatorBranchId) {
        jsonResponse(false, 'You can only create users in your branch', null, 403);
    }
}

// Nullable branch for certain roles
if (in_array($role, ['super_admin', 'company_admin', 'viewer'], true) && $branch_id <= 0) {
    $branch_id = null;
}

if (in_array($role, ['branch_manager', 'employee'], true) && $branch_id <= 0) {
    jsonResponse(false, 'Branch is required for this role', null, 400);
}

// Username duplicate check (scoped to company)
$checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND company_id = ? LIMIT 1");
$checkStmt->bind_param('si', $username, $company_id);
$checkStmt->execute();
$checkRes   = $checkStmt->get_result();
$checkCount = $checkRes->num_rows;
$checkRes->free();
$checkStmt->close();
if ($checkCount > 0) {
    jsonResponse(false, 'Username already exists in this company', null, 409);
}

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$created_by     = $loggedInUserId;

$stmt = $conn->prepare("
    INSERT INTO users
        (full_name, username, password, role, email, phone,
         company_id, branch_id, is_active, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

if (!$stmt) {
    jsonResponse(false, 'Database error', null, 500);
}

$stmt->bind_param(
    'ssssssiiii',
    $full_name, $username, $hashedPassword, $role,
    $email, $phone,
    $company_id, $branch_id, $is_active, $created_by
);

if (!$stmt->execute()) {
    jsonResponse(false, 'Failed to create user', null, 500);
}

$newUserId = $stmt->insert_id;

logActivity(
    $conn, $company_id, $branch_id, $loggedInUserId,
    'create_user', 'users', $newUserId,
    "Created user: $username ($role)"
);

// Return JSON for both web (AJAX) and mobile
jsonResponse(true, 'User created successfully', ['user_id' => $newUserId]);