<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

$admin = resolveAdminApiUser($conn);

$q          = trim($_GET['q']          ?? $_GET['search'] ?? '');
$status     = trim($_GET['status']     ?? '');
$companyId  = (int)($_GET['company_id'] ?? 0);
$branchId   = (int)($_GET['branch_id']  ?? 0);
$category   = trim($_GET['category']   ?? '');
$sort       = trim($_GET['sort']       ?? 'newest');

// Enforce scope
if (in_array($admin['role'], ['company_admin', 'branch_manager'], true)) {
    $companyId = (int)$admin['company_id'];
}
if ($admin['role'] === 'branch_manager') {
    $branchId = (int)$admin['branch_id'];
}

$sql = "
    SELECT
        p.id, p.company_id, p.branch_id, p.barcode, p.product_name,
        p.batch_code, p.category, p.quantity, p.unit, p.expiry_date,
        p.status, p.entered_by, p.entered_on, p.is_removed,
        p.removed_by, p.removed_on, p.notes,
        c.company_name, b.branch_name,
        u.full_name AS entered_by_name
    FROM products p
    INNER JOIN companies c ON p.company_id = c.id
    INNER JOIN branches  b ON p.branch_id  = b.id
    LEFT JOIN  users     u ON p.entered_by  = u.id
    WHERE 1 = 1
";
$params = [];
$types  = '';

if ($companyId > 0) {
    $sql   .= ' AND p.company_id = ?';
    $types .= 'i';
    $params[] = $companyId;
}
if ($branchId > 0) {
    $sql   .= ' AND p.branch_id = ?';
    $types .= 'i';
    $params[] = $branchId;
}
if ($status !== '') {
    $sql   .= ' AND p.status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($category !== '') {
    $sql   .= ' AND p.category = ?';
    $types .= 's';
    $params[] = $category;
}
if ($q !== '') {
    $sql   .= ' AND (p.barcode LIKE ? OR p.product_name LIKE ? OR p.category LIKE ? OR b.branch_name LIKE ? OR c.company_name LIKE ?)';
    $types .= 'sssss';
    $like   = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

switch ($sort) {
    case 'oldest':     $sql .= ' ORDER BY p.entered_on ASC  LIMIT 500'; break;
    case 'near_expiry': $sql .= ' ORDER BY p.expiry_date ASC LIMIT 500'; break;
    default:           $sql .= ' ORDER BY p.expiry_date ASC LIMIT 500';
}

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();

$data   = [];
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

jsonResponse(true, 'Products loaded successfully', $data);