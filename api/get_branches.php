<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

$admin     = resolveAdminApiUser($conn);
$companyId = (int)($_GET['company_id'] ?? 0);

if (in_array($admin['role'], ['company_admin', 'branch_manager'], true)) {
    $companyId = (int)$admin['company_id'];
}

$sql    = "SELECT id, company_id, branch_name, branch_code, city, country FROM branches WHERE is_active = 1";
$params = [];
$types  = '';

if ($companyId > 0) {
    $sql   .= ' AND company_id = ?';
    $types .= 'i';
    $params[] = $companyId;
}

if ($admin['role'] === 'branch_manager') {
    $sql   .= ' AND id = ?';
    $types .= 'i';
    $params[] = (int)$admin['branch_id'];
}

$sql .= ' ORDER BY branch_name ASC';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();

$result = $stmt->get_result();
$data   = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
$result->free();
$stmt->close();

jsonResponse(true, 'Branches loaded successfully', $data);