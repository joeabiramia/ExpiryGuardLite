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

function runCount(mysqli $conn, string $sql, string $types, array $params): int
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

$stats = [
    'total_products'  => runCount($conn, $base, $types, $params),
    'active_products' => runCount($conn, "$base AND status = 'active' AND is_removed = 0", $types, $params),
    'near_expiry'     => runCount($conn, "$base AND status = 'near_expiry' AND is_removed = 0", $types, $params),
    'expired'         => runCount($conn, "$base AND status = 'expired' AND is_removed = 0", $types, $params),
    'removed'         => runCount($conn, "$base AND (status = 'removed' OR is_removed = 1)", $types, $params),
    'total_users'     => runCount($conn, "SELECT COUNT(*) AS total FROM users WHERE company_id = ?", 'i', [$company_id]),
];

jsonResponse(true, 'Dashboard stats fetched successfully', $stats);