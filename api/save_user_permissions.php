<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

$me = resolveAdminApiUser($conn);

$targetUserId = (int)($_POST['user_id'] ?? 0);
if ($targetUserId <= 0) {
    jsonResponse(false, 'user_id is required', null, 400);
}

// Fetch target user — close immediately
$tStmt = $conn->prepare("SELECT id, role, company_id, branch_id, created_by FROM users WHERE id = ? LIMIT 1");
$tStmt->bind_param('i', $targetUserId);
$tStmt->execute();
$tRes   = $tStmt->get_result();
$target = $tRes->fetch_assoc();
$tRes->free();
$tStmt->close();

if (!$target) {
    jsonResponse(false, 'Target user not found', null, 404);
}

if ($me['role'] !== 'super_admin' && (int)$target['company_id'] !== (int)$me['company_id']) {
    jsonResponse(false, 'Access denied', null, 403);
}
if ($me['role'] === 'branch_manager') {
    if ((int)$target['branch_id'] !== (int)$me['branch_id']) {
        jsonResponse(false, 'You can only manage permissions for users in your branch', null, 403);
    }
    if ((int)$target['created_by'] !== (int)$me['id']) {
        jsonResponse(false, 'You can only manage permissions for users you created', null, 403);
    }
}
if (getRoleLevel($target['role']) >= getRoleLevel($me['role'])) {
    jsonResponse(false, 'You cannot modify permissions for this user', null, 403);
}

$permissionsJson = $_POST['permissions'] ?? '[]';
$permissions     = json_decode($permissionsJson, true);
if (!is_array($permissions)) {
    jsonResponse(false, 'Invalid permissions payload', null, 400);
}

// Load grantable permissions — close immediately
if ($me['role'] === 'super_admin') {
    $gStmt = $conn->prepare("SELECT id FROM permissions WHERE is_active = 1");
    $gStmt->execute();
} else {
    $gStmt = $conn->prepare("
        SELECT p.id FROM permissions p
        WHERE p.is_active = 1
          AND (
              EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.permission_id = p.id AND rp.role = ? AND rp.is_allowed = 1)
              OR EXISTS (SELECT 1 FROM user_permissions up WHERE up.permission_id = p.id AND up.user_id = ? AND up.is_allowed = 1)
          )
    ");
    $gStmt->bind_param('si', $me['role'], $me['id']);
    $gStmt->execute();
}

$gRes         = $gStmt->get_result();
$grantableIds = [];
while ($row = $gRes->fetch_assoc()) {
    $grantableIds[] = (int)$row['id'];
}
$gRes->free();
$gStmt->close();

// Delete then re-insert
$delStmt = $conn->prepare("DELETE FROM user_permissions WHERE user_id = ?");
$delStmt->bind_param('i', $targetUserId);
$delStmt->execute();
$delStmt->close();

if (!empty($permissions)) {
    $insStmt = $conn->prepare("
        INSERT INTO user_permissions (user_id, permission_id, is_allowed)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed)
    ");

    foreach ($permissions as $perm) {
        $permId    = (int)($perm['permission_id'] ?? 0);
        $isAllowed = in_array((int)($perm['is_allowed'] ?? 0), [0, 1], true) ? (int)$perm['is_allowed'] : 0;
        if ($permId <= 0 || !in_array($permId, $grantableIds, true)) continue;
        $insStmt->bind_param('iii', $targetUserId, $permId, $isAllowed);
        $insStmt->execute();
    }
    $insStmt->close();
}

logActivity($conn, (int)$me['company_id'], (int)$me['branch_id'], (int)$me['id'],
    'update_user', 'user_permissions', $targetUserId,
    "Updated permissions for user ID $targetUserId");

jsonResponse(true, 'Permissions saved successfully');
