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

function analyticsCount(mysqli $conn, string $sql, string $types, array $params): int
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

$base = "SELECT COUNT(*) AS total FROM products $where";

$totalProducts  = analyticsCount($conn, $base, $types, $params);
$activeProducts = analyticsCount($conn, "$base AND status = 'active' AND is_removed = 0", $types, $params);
$nearExpiry     = analyticsCount($conn, "$base AND status = 'near_expiry' AND is_removed = 0", $types, $params);
$expired        = analyticsCount($conn, "$base AND status = 'expired' AND is_removed = 0", $types, $params);
$removed        = analyticsCount($conn, "$base AND (status = 'removed' OR is_removed = 1)", $types, $params);

$userWhere  = ' WHERE company_id = ?';
$userParams = [$companyId];
$userTypes  = 'i';
if ($branchId > 0) {
    $userWhere  .= ' AND (branch_id = ? OR branch_id IS NULL)';
    $userTypes  .= 'i';
    $userParams[] = $branchId;
}
$totalUsers = analyticsCount($conn, "SELECT COUNT(*) AS total FROM users $userWhere", $userTypes, $userParams);

// Status distribution chart
$statusStmt = $conn->prepare("SELECT status, COUNT(*) AS total FROM products $where AND is_removed = 0 GROUP BY status ORDER BY total DESC");
$statusStmt->bind_param($types, ...$params);
$statusStmt->execute();
$statusChart = [];
while ($row = $statusStmt->get_result()->fetch_assoc()) {
    $statusChart[] = ['label' => $row['status'], 'value' => (int)$row['total']];
}

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
$branchChart = [];
while ($row = $branchStmt->get_result()->fetch_assoc()) {
    $branchChart[] = ['label' => $row['branch_name'], 'value' => (int)$row['total']];
}

// Monthly removed
$monthStmt = $conn->prepare("SELECT DATE_FORMAT(removed_on, '%Y-%m') AS month_label, COUNT(*) AS total FROM products $where AND removed_on IS NOT NULL GROUP BY DATE_FORMAT(removed_on, '%Y-%m') ORDER BY month_label ASC");
$monthStmt->bind_param($types, ...$params);
$monthStmt->execute();
$removedChart = [];
while ($row = $monthStmt->get_result()->fetch_assoc()) {
    $removedChart[] = ['label' => $row['month_label'], 'value' => (int)$row['total']];
}

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