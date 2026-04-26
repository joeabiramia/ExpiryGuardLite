<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

$apiUser = resolveApiUser($conn);

$company_id = (int)$apiUser['company_id'];

$sql    = "
    SELECT p.*, u.full_name AS entered_by_name, b.branch_name
    FROM products p
    LEFT JOIN users    u ON p.entered_by = u.id
    LEFT JOIN branches b ON p.branch_id  = b.id
    WHERE p.status IN ('near_expiry', 'expired')
      AND p.is_removed = 0
      AND p.company_id = ?
";
$params = [$company_id];
$types  = 'i';

// branch_manager and below see only their branch
if (!in_array($apiUser['role'], ['super_admin', 'company_admin'], true) && $apiUser['branch_id']) {
    $sql   .= ' AND p.branch_id = ?';
    $types .= 'i';
    $params[] = (int)$apiUser['branch_id'];
}

$sql .= ' ORDER BY p.expiry_date ASC';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();

$notifications = [];
$result        = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

jsonResponse(true, 'Notifications fetched successfully', $notifications);