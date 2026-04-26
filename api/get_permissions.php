<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

$me = resolveAdminApiUser($conn);

/*
 * Returns the permission list the caller is ALLOWED to grant.
 * super_admin sees all permissions.
 * Others see only permissions they personally hold (no escalation).
 */

if ($me['role'] === 'super_admin') {
    $stmt = $conn->prepare("
        SELECT id, permission_key, permission_label, module_name, description
        FROM permissions
        WHERE is_active = 1
        ORDER BY module_name, permission_label
    ");
    $stmt->execute();
} else {
    // Load only the permissions this user actually has
    $stmt = $conn->prepare("
        SELECT p.id, p.permission_key, p.permission_label, p.module_name, p.description
        FROM permissions p
        WHERE p.is_active = 1
          AND (
              -- from role defaults
              EXISTS (
                  SELECT 1 FROM role_permissions rp
                  WHERE rp.permission_id = p.id AND rp.role = ? AND rp.is_allowed = 1
              )
              -- or explicitly granted at user level
              OR EXISTS (
                  SELECT 1 FROM user_permissions up
                  WHERE up.permission_id = p.id AND up.user_id = ? AND up.is_allowed = 1
              )
          )
        ORDER BY p.module_name, p.permission_label
    ");
    $stmt->bind_param('si', $me['role'], $me['id']);
    $stmt->execute();
}

$permissions = [];
$result      = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $module = $row['module_name'] ?? 'general';
    if (!isset($permissions[$module])) {
        $permissions[$module] = [];
    }
    $permissions[$module][] = [
        'id'               => (int)$row['id'],
        'permission_key'   => $row['permission_key'],
        'permission_label' => $row['permission_label'],
        'description'      => $row['description'],
    ];
}

jsonResponse(true, 'Permissions loaded', $permissions);