<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

$admin        = resolveAdminApiUser($conn);
$targetUserId = (int)($_POST['user_id']   ?? 0);
$isActive     = isset($_POST['is_active']) ? (int)$_POST['is_active'] : -1;

if ($targetUserId <= 0) {
    jsonResponse(false, 'user_id is required', null, 400);
}

if (!in_array($isActive, [0, 1], true)) {
    jsonResponse(false, 'is_active must be 0 or 1', null, 400);
}

$stmt = $conn->prepare("SELECT id, company_id, branch_id, role, created_by FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $targetUserId);
$stmt->execute();
$targetUser = $stmt->get_result()->fetch_assoc();

if (!$targetUser) {
    jsonResponse(false, 'Target user not found', null, 404);
}

// Company scope
if ($admin['role'] === 'company_admin' && (int)$targetUser['company_id'] !== (int)$admin['company_id']) {
    jsonResponse(false, 'You can only manage users in your company', null, 403);
}

// Branch manager scope — can only toggle users they created
if ($admin['role'] === 'branch_manager') {
    if ((int)$targetUser['branch_id'] !== (int)$admin['branch_id']) {
        jsonResponse(false, 'You can only manage users in your branch', null, 403);
    }
    if ((int)$targetUser['created_by'] !== (int)$admin['id']) {
        jsonResponse(false, 'You can only manage users you created', null, 403);
    }
}

if ((int)$targetUser['id'] === (int)$admin['id'] && $isActive === 0) {
    jsonResponse(false, 'You cannot deactivate your own account', null, 400);
}

$update = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
$update->bind_param('ii', $isActive, $targetUserId);

if (!$update->execute()) {
    jsonResponse(false, 'Failed to update user status', null, 500);
}

jsonResponse(true, 'User status updated successfully', [
    'user_id'   => $targetUserId,
    'is_active' => $isActive,
]);