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

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$companyId = (int)($_GET['company_id'] ?? 0);
$branchId = (int)($_GET['branch_id'] ?? 0);
$category = trim($_GET['category'] ?? '');
$sort = trim($_GET['sort'] ?? 'newest');

if ($admin['role'] === 'company_admin' || $admin['role'] === 'branch_manager') {
    $companyId = (int)$admin['company_id'];
}
if ($admin['role'] === 'branch_manager') {
    $branchId = (int)$admin['branch_id'];
}

$sql = "
    SELECT
        p.id,
        p.company_id,
        p.branch_id,
        p.barcode,
        p.product_name,
        p.batch_code,
        p.category,
        p.quantity,
        p.unit,
        p.expiry_date,
        p.status,
        p.entered_by,
        p.entered_on,
        p.is_removed,
        p.removed_by,
        p.removed_on,
        p.notes,
        c.company_name,
        b.branch_name,
        u.full_name AS entered_by_name
    FROM products p
    INNER JOIN companies c ON p.company_id = c.id
    INNER JOIN branches b ON p.branch_id = b.id
    LEFT JOIN users u ON p.entered_by = u.id
    WHERE 1 = 1
";

$params = [];
$types = '';

if ($companyId > 0) {
    $sql .= " AND p.company_id = ? ";
    $types .= 'i';
    $params[] = $companyId;
}
if ($branchId > 0) {
    $sql .= " AND p.branch_id = ? ";
    $types .= 'i';
    $params[] = $branchId;
}
if ($status !== '') {
    $sql .= " AND p.status = ? ";
    $types .= 's';
    $params[] = $status;
}
if ($category !== '') {
    $sql .= " AND p.category = ? ";
    $types .= 's';
    $params[] = $category;
}
if ($q !== '') {
    $sql .= " AND (
        p.barcode LIKE ?
        OR p.product_name LIKE ?
        OR p.category LIKE ?
        OR b.branch_name LIKE ?
        OR c.company_name LIKE ?
    ) ";
    $types .= 'sssss';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

switch ($sort) {
    case 'oldest':
        $sql .= " ORDER BY p.entered_on ASC ";
        break;
    case 'near_expiry':
        $sql .= " ORDER BY p.expiry_date ASC ";
        break;
    default:
        $sql .= " ORDER BY p.entered_on DESC ";
        break;
}

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

apiResponse(true, 'Products loaded successfully', $data);