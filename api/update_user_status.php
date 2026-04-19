<?php
require_once '../config/db.php';
require_once '../config/helpers.php';

if (!function_exists('apiResponse')) {
    function apiResponse($success, $message, $data = null, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data
        ]);
        exit;
    }
}

function requireAdminAccess(mysqli $conn, int $adminUserId): array {
    if ($adminUserId <= 0) {
        apiResponse(false, 'admin_user_id is required', null, 400);
    }

    $stmt = $conn->prepare("
        SELECT id, role, company_id, branch_id, is_active
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $adminUserId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        apiResponse(false, 'Admin user not found', null, 404);
    }

    if ((int)$user['is_active'] !== 1) {
        apiResponse(false, 'Admin user is inactive', null, 403);
    }

    $allowedRoles = ['super_admin', 'company_admin', 'branch_manager'];
    if (!in_array($user['role'], $allowedRoles, true)) {
        apiResponse(false, 'Access denied', null, 403);
    }

    return $user;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiResponse(false, 'Invalid request method', null, 405);
}

$adminUserId = (int)($_POST['admin_user_id'] ?? 0);
$targetUserId = (int)($_POST['user_id'] ?? 0);
$isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : -1;

$admin = requireAdminAccess($conn, $adminUserId);

if ($targetUserId <= 0) {
    apiResponse(false, 'user_id is required', null, 400);
}

if (!in_array($isActive, [0, 1], true)) {
    apiResponse(false, 'is_active must be 0 or 1', null, 400);
}

$stmt = $conn->prepare("
    SELECT id, company_id, branch_id, role
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param('i', $targetUserId);
$stmt->execute();
$targetUser = $stmt->get_result()->fetch_assoc();

if (!$targetUser) {
    apiResponse(false, 'Target user not found', null, 404);
}

if ($admin['role'] === 'company_admin' && (int)$targetUser['company_id'] !== (int)$admin['company_id']) {
    apiResponse(false, 'You can only manage users in your company', null, 403);
}

if ($admin['role'] === 'branch_manager' && (int)$targetUser['branch_id'] !== (int)$admin['branch_id']) {
    apiResponse(false, 'You can only manage users in your branch', null, 403);
}

if ((int)$targetUser['id'] === (int)$admin['id'] && $isActive === 0) {
    apiResponse(false, 'You cannot deactivate your own account', null, 400);
}

$update = $conn->prepare("
    UPDATE users
    SET is_active = ?
    WHERE id = ?
");
$update->bind_param('ii', $isActive, $targetUserId);

if (!$update->execute()) {
    apiResponse(false, 'Failed to update user status', null, 500);
}

apiResponse(true, 'User status updated successfully', [
    'user_id' => $targetUserId,
    'is_active' => $isActive
]);
