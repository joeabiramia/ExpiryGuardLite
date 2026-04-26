<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

$admin = resolveAdminApiUser($conn);

if ($admin['role'] === 'super_admin') {
    $stmt = $conn->prepare("
        SELECT id, company_name, company_code
        FROM companies WHERE is_active = 1
        ORDER BY company_name ASC
    ");
    $stmt->execute();
} else {
    $stmt = $conn->prepare("
        SELECT id, company_name, company_code
        FROM companies WHERE is_active = 1 AND id = ?
        ORDER BY company_name ASC
    ");
    $stmt->bind_param('i', $admin['company_id']);
    $stmt->execute();
}

$data = [];
while ($row = $stmt->get_result()->fetch_assoc()) {
    $data[] = $row;
}

jsonResponse(true, 'Companies loaded successfully', $data);