<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

$me           = resolveAdminApiUser($conn);
$targetUserId = (int)($_GET['user_id'] ?? 0);

if ($targetUserId <= 0) {
    jsonResponse(false, 'user_id is required', null, 400);
}

// Verify scope — close statement immediately
$uStmt = $conn->prepare("SELECT id, role, company_id, branch_id, created_by FROM users WHERE id = ? LIMIT 1");
$uStmt->bind_param('i', $targetUserId);
$uStmt->execute();
$uRes   = $uStmt->get_result();
$target = $uRes->fetch_assoc();
$uRes->free();
$uStmt->close();

if (!$target) {
    jsonResponse(false, 'User not found', null, 404);
}

if ($me['role'] !== 'super_admin' && (int)$target['company_id'] !== (int)$me['company_id']) {
    jsonResponse(false, 'Access denied', null, 403);
}
if ($me['role'] === 'branch_manager' && (int)$target['created_by'] !== (int)$me['id']) {
    jsonResponse(false, 'Access denied', null, 403);
}

// Fetch effective permissions
$pStmt = $conn->prepare("
    SELECT
        p.id,
        p.permission_key,
        p.permission_label,
        p.module_name,
        CASE
            WHEN u.role = 'super_admin'    THEN 1
            WHEN up.is_allowed IS NOT NULL THEN up.is_allowed
            WHEN rp.is_allowed IS NOT NULL THEN rp.is_allowed
            ELSE 0
        END AS is_allowed,
        CASE WHEN up.id IS NOT NULL THEN 1 ELSE 0 END AS is_override
    FROM users u
    CROSS JOIN permissions p
    LEFT JOIN user_permissions up ON up.user_id = u.id AND up.permission_id = p.id
    LEFT JOIN role_permissions rp ON rp.role = u.role AND rp.permission_id = p.id
    WHERE u.id = ? AND p.is_active = 1
    ORDER BY p.module_name, p.permission_label
");
$pStmt->bind_param('i', $targetUserId);
$pStmt->execute();

$pRes = $pStmt->get_result();
$data = [];
while ($row = $pRes->fetch_assoc()) {
    $data[] = [
        'id'               => (int)$row['id'],
        'permission_key'   => $row['permission_key'],
        'permission_label' => $row['permission_label'],
        'module_name'      => $row['module_name'],
        'is_allowed'       => (int)$row['is_allowed'],
        'is_override'      => (int)$row['is_override'],
    ];
}
$pRes->free();
$pStmt->close();

jsonResponse(true, 'User permissions loaded', $data);
