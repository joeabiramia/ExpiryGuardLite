<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

$admin = resolveAdminApiUser($conn);

$companyId = (int)$admin['company_id'];
$branchId  = (int)($_GET['branch_id'] ?? 0);

if (in_array($admin['role'], ['company_admin', 'branch_manager'], true)) {
    $companyId = (int)$admin['company_id'];
}
if ($admin['role'] === 'branch_manager') {
    $branchId = (int)$admin['branch_id'];
}

$where  = ' WHERE company_id = ?';
$params = [$companyId];
$types  = 'i';

if ($branchId > 0) {
    $where  .= ' AND branch_id = ?';
    $types  .= 'i';
    $params[] = $branchId;
}

// One aggregated query replaces 5 separate COUNT queries
$aggStmt = $conn->prepare("
    SELECT
        COUNT(*)                                          AS total_products,
        SUM(status = 'active'      AND is_removed = 0)   AS active_products,
        SUM(status = 'near_expiry' AND is_removed = 0)   AS near_expiry,
        SUM(status = 'expired'     AND is_removed = 0)   AS expired,
        SUM(is_removed = 1)                               AS removed
    FROM products $where
");
$aggStmt->bind_param($types, ...$params);
$aggStmt->execute();
$agg = $aggStmt->get_result()->fetch_assoc();
$aggStmt->close();

$totalProducts  = (int)$agg['total_products'];
$activeProducts = (int)$agg['active_products'];
$nearExpiry     = (int)$agg['near_expiry'];
$expired        = (int)$agg['expired'];
$removed        = (int)$agg['removed'];

$userWhere  = ' WHERE company_id = ?';
$userParams = [$companyId];
$userTypes  = 'i';
if ($branchId > 0) {
    $userWhere  .= ' AND (branch_id = ? OR branch_id IS NULL)';
    $userTypes  .= 'i';
    $userParams[] = $branchId;
}
$uStmt = $conn->prepare("SELECT COUNT(*) AS total FROM users $userWhere");
$uStmt->bind_param($userTypes, ...$userParams);
$uStmt->execute();
$totalUsers = (int)$uStmt->get_result()->fetch_assoc()['total'];
$uStmt->close();

// Status distribution chart
$statusStmt = $conn->prepare("SELECT status, COUNT(*) AS total FROM products $where AND is_removed = 0 GROUP BY status ORDER BY total DESC");
$statusStmt->bind_param($types, ...$params);
$statusStmt->execute();
$statusRes   = $statusStmt->get_result();
$statusChart = [];
while ($row = $statusRes->fetch_assoc()) {
    $statusChart[] = ['label' => $row['status'], 'value' => (int)$row['total']];
}
$statusRes->free();
$statusStmt->close();

// Branch distribution
$branchSql    = "SELECT b.branch_name, COUNT(p.id) AS total FROM products p INNER JOIN branches b ON p.branch_id = b.id WHERE p.company_id = ?";
$branchParams = [$companyId];
$branchTypes  = 'i';
if ($branchId > 0) {
    $branchSql    .= ' AND p.branch_id = ?';
    $branchTypes  .= 'i';
    $branchParams[] = $branchId;
}
$branchSql .= ' GROUP BY b.id, b.branch_name ORDER BY total DESC';
$branchStmt = $conn->prepare($branchSql);
$branchStmt->bind_param($branchTypes, ...$branchParams);
$branchStmt->execute();
$branchRes   = $branchStmt->get_result();
$branchChart = [];
while ($row = $branchRes->fetch_assoc()) {
    $branchChart[] = ['label' => $row['branch_name'], 'value' => (int)$row['total']];
}
$branchRes->free();
$branchStmt->close();

// Monthly removed
$monthStmt = $conn->prepare("SELECT DATE_FORMAT(removed_on, '%Y-%m') AS month_label, COUNT(*) AS total FROM products $where AND removed_on IS NOT NULL GROUP BY DATE_FORMAT(removed_on, '%Y-%m') ORDER BY month_label ASC");
$monthStmt->bind_param($types, ...$params);
$monthStmt->execute();
$monthRes     = $monthStmt->get_result();
$removedChart = [];
while ($row = $monthRes->fetch_assoc()) {
    $removedChart[] = ['label' => $row['month_label'], 'value' => (int)$row['total']];
}
$monthRes->free();
$monthStmt->close();

jsonResponse(true, 'Analytics loaded successfully', [
    'total_products'  => $totalProducts,
    'active_products' => $activeProducts,
    'near_expiry'     => $nearExpiry,
    'expired'         => $expired,
    'removed'         => $removed,
    'total_users'     => $totalUsers,
    'chart_data'      => [
        'status_distribution' => $statusChart,
        'branch_distribution' => $branchChart,
        'monthly_removed'     => $removedChart,
    ],
]);