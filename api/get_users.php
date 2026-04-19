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

$sql = "
    SELECT
        u.id,
        u.full_name,
        u.username,
        u.role,
        u.email,
        u.phone,
        u.is_active,
        u.last_login,
        u.created_at,
        c.id AS company_id,
        c.company_name,
        b.id AS branch_id,
        b.branch_name
    FROM users u
    INNER JOIN companies c ON u.company_id = c.id
    LEFT JOIN branches b ON u.branch_id = b.id
    WHERE 1 = 1
";

$params = [];
$types = '';

if ($admin['role'] === 'company_admin' || $admin['role'] === 'branch_manager') {
    $sql .= " AND u.company_id = ?";
    $types .= 'i';
    $params[] = (int)$admin['company_id'];
}

if ($admin['role'] === 'branch_manager') {
    $sql .= " AND (u.branch_id = ? OR u.branch_id IS NULL)";
    $types .= 'i';
    $params[] = (int)$admin['branch_id'];
}

$sql .= " ORDER BY u.created_at DESC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

apiResponse(true, 'Users loaded successfully', $data);