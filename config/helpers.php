<?php

function jsonResponse($success, $message, $data = null, int $code = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if ($data === null) {
        $data = new stdClass();
    }

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit();
}


function sanitize($value): string
{
    return htmlspecialchars(trim($value ?? ''), ENT_QUOTES, 'UTF-8');
}


function getRoleLevel(string $role): int
{
    $levels = [
        'viewer'         => 1,
        'employee'       => 2,
        'branch_manager' => 3,
        'company_admin'  => 4,
        'super_admin'    => 5,
    ];

    return $levels[$role] ?? 0;
}


function userHasPermission(mysqli $conn, int $userId, string $permissionKey): bool
{
    $sql = "
        SELECT
            CASE
                WHEN u.role = 'super_admin' THEN 1
                WHEN up.is_allowed IS NOT NULL THEN up.is_allowed
                WHEN rp.is_allowed IS NOT NULL THEN rp.is_allowed
                ELSE 0
            END AS is_allowed
        FROM users u

        INNER JOIN permissions p
            ON p.permission_key = ?

        LEFT JOIN user_permissions up
            ON up.user_id = u.id
           AND up.permission_id = p.id

        LEFT JOIN role_permissions rp
            ON rp.role = u.role
           AND rp.permission_id = p.id

        WHERE u.id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('si', $permissionKey, $userId);
    $stmt->execute();

    $result = $stmt->get_result();

    if (!$result) {
        $stmt->close();
        return false;
    }

    if ($result->num_rows === 0) {
        $result->free();
        $stmt->close();
        return false;
    }

    $row = $result->fetch_assoc();
    $result->free();
    $stmt->close();

    return isset($row['is_allowed']) && (int)$row['is_allowed'] === 1;
}


function requirePermission(mysqli $conn, int $userId, string $permissionKey): void
{
    if (!userHasPermission($conn, $userId, $permissionKey)) {
        jsonResponse(false, 'Access denied', null, 403);
    }
}


function canCreateRole(string $creatorRole, string $targetRole): bool
{
    $rules = [
        'super_admin'    => ['super_admin', 'company_admin', 'branch_manager', 'employee', 'viewer'],
        'company_admin'  => ['company_admin', 'branch_manager', 'employee', 'viewer'],
        'branch_manager' => ['employee', 'viewer'],
        'employee'       => [],
        'viewer'         => [],
    ];

    if (!isset($rules[$creatorRole])) {
        return false;
    }

    return in_array($targetRole, $rules[$creatorRole]);
}


function getLoggedInUser(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare("
        SELECT id, full_name, username, role, company_id, branch_id, is_active, created_by
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();

    if (!$result) {
        $stmt->close();
        return null;
    }

    if ($result->num_rows === 0) {
        $result->free();
        $stmt->close();
        return null;
    }

    $row = $result->fetch_assoc();
    $result->free();
    $stmt->close();

    return $row;
}


function refreshProductStatuses(mysqli $conn, int $companyId): int
{
    // Recalculate status for all non-removed products based on today's date + category rules
    $stmt = $conn->prepare("
        UPDATE products p
        LEFT JOIN category_rules cr ON p.category = cr.category_name
        SET p.status =
            CASE
                WHEN p.expiry_date < CURDATE()
                    THEN 'expired'
                WHEN p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL COALESCE(cr.alert_days_before, 4) DAY)
                    THEN 'near_expiry'
                ELSE 'active'
            END
        WHERE p.company_id = ?
          AND p.is_removed = 0
    ");

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    return $affected;
}


function logActivity(
    mysqli $conn,
    int $companyId,
    ?int $branchId,
    ?int $userId,
    string $actionType,
    string $targetTable,
    ?int $targetId = null,
    ?string $description = null
): void {
    // Convert 0 to null — FK constraint requires a valid branch/user ID or NULL
    $branchIdVal = ($branchId  && $branchId  > 0) ? $branchId  : null;
    $userIdVal   = ($userId    && $userId    > 0) ? $userId    : null;
    $targetIdVal = ($targetId  && $targetId  > 0) ? $targetId  : null;

    $stmt = $conn->prepare("
        INSERT INTO activity_logs
            (company_id, branch_id, user_id, action_type, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('iiissis', $companyId, $branchIdVal, $userIdVal, $actionType, $targetTable, $targetIdVal, $description);

    if (!$stmt->execute()) {
        // Log failure silently — don't break the main operation
        error_log('[logActivity] Failed: ' . $stmt->error);
    }

    $stmt->close();
}
