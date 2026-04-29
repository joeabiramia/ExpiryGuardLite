<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

$apiUser = resolveApiUser($conn);

$company_id = (int)$apiUser['company_id'];
$branch_id  = (int)$apiUser['branch_id'];

// Build scoped WHERE clause
$where  = 'WHERE company_id = ?';
$params = [$company_id];
$types  = 'i';

if (!in_array($apiUser['role'], ['super_admin', 'company_admin'], true) && $branch_id > 0) {
    $where  .= ' AND branch_id = ?';
    $types  .= 'i';
    $params[] = $branch_id;
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

$usersStmt = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE company_id = ?");
$usersStmt->bind_param('i', $company_id);
$usersStmt->execute();
$totalUsers = (int)$usersStmt->get_result()->fetch_assoc()['total'];
$usersStmt->close();

$stats = [
    'total_products'  => (int)$agg['total_products'],
    'active_products' => (int)$agg['active_products'],
    'near_expiry'     => (int)$agg['near_expiry'],
    'expired'         => (int)$agg['expired'],
    'removed'         => (int)$agg['removed'],
    'total_users'     => $totalUsers,
];

jsonResponse(true, 'Dashboard stats fetched successfully', $stats);