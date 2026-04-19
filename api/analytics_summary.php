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

$companyId = (int)($_GET['company_id'] ?? 0);
$branchId = (int)($_GET['branch_id'] ?? 0);

if ($admin['role'] === 'company_admin' || $admin['role'] === 'branch_manager') {
    $companyId = (int)$admin['company_id'];
}
if ($admin['role'] === 'branch_manager') {
    $branchId = (int)$admin['branch_id'];
}

$where = " WHERE 1 = 1 ";
$params = [];
$types = '';

if ($companyId > 0) {
    $where .= " AND company_id = ? ";
    $types .= 'i';
    $params[] = $companyId;
}
if ($branchId > 0) {
    $where .= " AND branch_id = ? ";
    $types .= 'i';
    $params[] = $branchId;
}

function fetchCount(mysqli $conn, string $sql, string $types = '', array $params = []): int {
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

$totalProducts = fetchCount($conn, "SELECT COUNT(*) AS total FROM products $where", $types, $params);
$activeProducts = fetchCount($conn, "SELECT COUNT(*) AS total FROM products $where AND status = 'active' AND is_removed = 0", $types, $params);
$nearExpiry = fetchCount($conn, "SELECT COUNT(*) AS total FROM products $where AND status = 'near_expiry' AND is_removed = 0", $types, $params);
$expired = fetchCount($conn, "SELECT COUNT(*) AS total FROM products $where AND status = 'expired' AND is_removed = 0", $types, $params);
$removed = fetchCount($conn, "SELECT COUNT(*) AS total FROM products $where AND (status = 'removed' OR is_removed = 1)", $types, $params);

$userWhere = " WHERE 1 = 1 ";
$userParams = [];
$userTypes = '';

if ($companyId > 0) {
    $userWhere .= " AND company_id = ? ";
    $userTypes .= 'i';
    $userParams[] = $companyId;
}
if ($branchId > 0) {
    $userWhere .= " AND (branch_id = ? OR branch_id IS NULL) ";
    $userTypes .= 'i';
    $userParams[] = $branchId;
}

$totalUsers = fetchCount($conn, "SELECT COUNT(*) AS total FROM users $userWhere", $userTypes, $userParams);

$statusSql = "
    SELECT status, COUNT(*) AS total
    FROM products
    $where AND is_removed = 0
    GROUP BY status
    ORDER BY total DESC
";
$stmt = $conn->prepare($statusSql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$statusRows = $stmt->get_result();

$statusChart = [];
while ($row = $statusRows->fetch_assoc()) {
    $statusChart[] = [
        'label' => $row['status'],
        'value' => (int)$row['total']
    ];
}

$branchChartSql = "
    SELECT b.branch_name, COUNT(p.id) AS total
    FROM products p
    INNER JOIN branches b ON p.branch_id = b.id
    WHERE 1 = 1
";

$branchChartParams = [];
$branchChartTypes = '';

if ($companyId > 0) {
    $branchChartSql .= " AND p.company_id = ? ";
    $branchChartTypes .= 'i';
    $branchChartParams[] = $companyId;
}
if ($branchId > 0) {
    $branchChartSql .= " AND p.branch_id = ? ";
    $branchChartTypes .= 'i';
    $branchChartParams[] = $branchId;
}

$branchChartSql .= " GROUP BY b.id, b.branch_name ORDER BY total DESC";

$stmt = $conn->prepare($branchChartSql);
if ($branchChartTypes !== '') {
    $stmt->bind_param($branchChartTypes, ...$branchChartParams);
}
$stmt->execute();
$branchRows = $stmt->get_result();

$branchChart = [];
while ($row = $branchRows->fetch_assoc()) {
    $branchChart[] = [
        'label' => $row['branch_name'],
        'value' => (int)$row['total']
    ];
}

$monthlyRemovedSql = "
    SELECT DATE_FORMAT(removed_on, '%Y-%m') AS month_label, COUNT(*) AS total
    FROM products
    $where AND removed_on IS NOT NULL
    GROUP BY DATE_FORMAT(removed_on, '%Y-%m')
    ORDER BY month_label ASC
";
$stmt = $conn->prepare($monthlyRemovedSql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$removedRows = $stmt->get_result();

$removedChart = [];
while ($row = $removedRows->fetch_assoc()) {
    $removedChart[] = [
        'label' => $row['month_label'],
        'value' => (int)$row['total']
    ];
}

apiResponse(true, 'Analytics loaded successfully', [
    'total_products' => $totalProducts,
    'active_products' => $activeProducts,
    'near_expiry' => $nearExpiry,
    'expired' => $expired,
    'removed' => $removed,
    'total_users' => $totalUsers,
    'chart_data' => [
        'status_distribution' => $statusChart,
        'branch_distribution' => $branchChart,
        'monthly_removed' => $removedChart
    ]
]);