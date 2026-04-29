<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

$apiUser    = resolveApiUser($conn);
$company_id = (int)$apiUser['company_id'];
$branch_id  = (int)$apiUser['branch_id'];

// Respect ?branch= filter but enforce user's scope
$selectedBranch = trim($_GET['branch'] ?? 'all');

$sql    = "
    SELECT p.*,
           u.full_name  AS entered_by_name,
           ru.full_name AS removed_by_name,
           b.branch_name
    FROM products p
    LEFT JOIN users    u  ON p.entered_by = u.id
    LEFT JOIN users    ru ON p.removed_by = ru.id
    LEFT JOIN branches b  ON p.branch_id  = b.id
    WHERE p.company_id = ?
";
$params = [$company_id];
$types  = 'i';

if (!in_array($apiUser['role'], ['super_admin', 'company_admin'], true) && $branch_id > 0) {
    // branch_manager and below are locked to their branch regardless of ?branch= param
    $sql   .= ' AND p.branch_id = ?';
    $types .= 'i';
    $params[] = $branch_id;
} elseif ($selectedBranch !== 'all') {
    $sql   .= ' AND p.branch_id = ?';
    $types .= 'i';
    $params[] = (int)$selectedBranch;
}

$sql .= ' ORDER BY p.id DESC LIMIT 500';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();

$products = [];
$result   = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

jsonResponse(true, 'Products loaded successfully', $products);