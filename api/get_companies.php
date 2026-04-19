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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiResponse(false, 'Invalid request method', null, 405);
}

$adminUserId = (int)($_GET['admin_user_id'] ?? 0);
$admin = requireAdminAccess($conn, $adminUserId);

if ($admin['role'] === 'super_admin') {
    $stmt = $conn->prepare("
        SELECT id, company_name, company_code
        FROM companies
        WHERE is_active = 1
        ORDER BY company_name ASC
    ");
} else {
    $stmt = $conn->prepare("
        SELECT id, company_name, company_code
        FROM companies
        WHERE is_active = 1 AND id = ?
        ORDER BY company_name ASC
    ");
    $stmt->bind_param('i', $admin['company_id']);
}

$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

apiResponse(true, 'Companies loaded successfully', $data);