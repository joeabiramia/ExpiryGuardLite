<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

$admin = resolveAdminApiUser($conn);

$sql    = "
    SELECT u.id, u.full_name, u.username, u.role, u.email, u.phone,
           u.is_active, u.last_login, u.created_at, u.created_by,
           c.id AS company_id, c.company_name,
           b.id AS branch_id, b.branch_name,
           cb.full_name AS created_by_name
    FROM users u
    INNER JOIN companies c ON u.company_id = c.id
    LEFT JOIN  branches  b ON u.branch_id  = b.id
    LEFT JOIN  users     cb ON u.created_by = cb.id
    WHERE 1 = 1
";
$params = [];
$types  = '';

if (in_array($admin['role'], ['company_admin', 'branch_manager'], true)) {
    $sql   .= ' AND u.company_id = ?';
    $types .= 'i';
    $params[] = (int)$admin['company_id'];
}

if ($admin['role'] === 'branch_manager') {
    // Branch managers see only users in their branch that they created
    $sql   .= ' AND u.branch_id = ? AND u.created_by = ?';
    $types .= 'ii';
    $params[] = (int)$admin['branch_id'];
    $params[] = (int)$admin['id'];
}

$sql .= ' ORDER BY u.created_at DESC LIMIT 500';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();

$data = [];
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

jsonResponse(true, 'Users loaded successfully', $data);