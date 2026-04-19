<?php
session_start();

require_once '../config/db.php';
require_once '../config/helpers.php';

/*
|--------------------------------------------------------------------------
| Only POST requests allowed
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

/*
|--------------------------------------------------------------------------
| Logged-in user security check
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
| Only users with manage_users can create users
|
*/

requirePermission($conn, $loggedInUserId, 'manage_users');

/*
|--------------------------------------------------------------------------
| Get logged-in user info
|--------------------------------------------------------------------------
*/

$loggedInUser = getLoggedInUser($conn, $loggedInUserId);

if (!$loggedInUser) {
    jsonResponse(false, 'Logged-in user not found');
}

$creatorRole      = $loggedInUser['role'];
$creatorCompanyId = (int) ($loggedInUser['company_id'] ?? 0);
$creatorBranchId  = (int) ($loggedInUser['branch_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Incoming form data
|--------------------------------------------------------------------------
*/

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

if ($full_name === '' || $username === '' || $password === '') {
    jsonResponse(false, 'Full name, username and password are required');
}

/*
|--------------------------------------------------------------------------
| Allowed roles validation
|--------------------------------------------------------------------------
*/

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
| Role creation restrictions
|--------------------------------------------------------------------------
|
| Example:
| company_admin cannot create super_admin
|
*/

if (!canCreateRole($creatorRole, $role)) {
    jsonResponse(false, 'You are not allowed to create this role');
}

/*
|--------------------------------------------------------------------------
| Company / Branch scope enforcement
|--------------------------------------------------------------------------
|
| Prevent cross-company creation
|
*/

if ($creatorRole !== 'super_admin') {
    if ($company_id !== $creatorCompanyId) {
        jsonResponse(false, 'You cannot create users outside your company');
    }
}

/*
|--------------------------------------------------------------------------
| Branch Manager restriction
|--------------------------------------------------------------------------
|
| Branch manager can only create users in same branch
|
*/

if ($creatorRole === 'branch_manager') {
    if ($branch_id !== $creatorBranchId) {
        jsonResponse(false, 'You can only create users inside your branch');
    }
}

/*
|--------------------------------------------------------------------------
| Target role validation
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
    LIMIT 1
");

$checkStmt->bind_param("s", $username);
$checkStmt->execute();

$result = $checkStmt->get_result();

if ($result->num_rows > 0) {
    jsonResponse(false, 'Username already exists');
}

/*
|--------------------------------------------------------------------------
| Hash password
|--------------------------------------------------------------------------
*/

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

/*
|--------------------------------------------------------------------------
| Insert user
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    INSERT INTO users (
        full_name,
        username,
        password,
        role,
        company_id,
        branch_id,
        is_active,
        created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
");

if (!$stmt) {
    jsonResponse(false, 'Database prepare failed');
}

$stmt->bind_param(
    "ssssiii",
    $full_name,
    $username,
    $hashedPassword,
    $role,
    $company_id,
    $branch_id,
    $is_active
);

if (!$stmt->execute()) {
    jsonResponse(false, 'Failed to add user', [
        'error' => $stmt->error
    ]);
}

$newUserId = $stmt->insert_id;

/*
|--------------------------------------------------------------------------
| Optional: Save custom permission overrides later
|--------------------------------------------------------------------------
|
| Next upgrade:
| save user_permissions here
|
*/

jsonResponse(true, 'User added successfully', [
    'user_id' => $newUserId
]);