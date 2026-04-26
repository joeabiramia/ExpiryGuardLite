<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

$apiUser = resolveApiUser($conn);

$company_id = (int)$apiUser['company_id'];
$branch_id  = (int)$apiUser['branch_id'];

// super_admin can filter across companies; everyone else is locked to their own
$filter_company = ($apiUser['role'] === 'super_admin')
    ? (int)($_GET['company_id'] ?? $company_id)
    : $company_id;

$filter_branch = in_array($apiUser['role'], ['super_admin', 'company_admin'], true)
    ? (int)($_GET['branch_id'] ?? 0)
    : $branch_id;

$sql  = "
    SELECT p.*, u.full_name AS entered_by_name, ru.full_name AS removed_by_name,
           c.company_name, b.branch_name
    FROM products p
    LEFT JOIN users u  ON p.entered_by  = u.id
    LEFT JOIN users ru ON p.removed_by  = ru.id
    LEFT JOIN companies c ON p.company_id = c.id
    LEFT JOIN branches  b ON p.branch_id  = b.id
    WHERE p.company_id = ?
";
$params = [$filter_company];
$types  = 'i';

if ($filter_branch > 0) {
    $sql   .= ' AND p.branch_id = ?';
    $types .= 'i';
    $params[] = $filter_branch;
}

$sql .= ' ORDER BY p.id DESC';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();

$products = [];
$result   = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

jsonResponse(true, 'Products fetched successfully', $products);