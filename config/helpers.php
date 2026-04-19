<?php

/*
|--------------------------------------------------------------------------
| Existing JSON Response
|--------------------------------------------------------------------------
*/

function jsonResponse($success, $message, $data = null)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if ($data === null) {
        $data = new stdClass();
    }

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit();
}


/*
|--------------------------------------------------------------------------
| Existing Sanitize Function
|--------------------------------------------------------------------------
*/

function sanitize($value)
{
    return htmlspecialchars(trim($value ?? ''), ENT_QUOTES, 'UTF-8');
}


/*
|--------------------------------------------------------------------------
| Permission System
|--------------------------------------------------------------------------
|
| Priority:
|
| 1. super_admin => full access
| 2. user_permissions override
| 3. role_permissions default
| 4. otherwise deny
|
*/


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

    $stmt->bind_param("si", $permissionKey, $userId);
    $stmt->execute();

    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        return false;
    }

    $row = $result->fetch_assoc();

    return isset($row['is_allowed']) && (int)$row['is_allowed'] === 1;
}


/*
|--------------------------------------------------------------------------
| Hard Block Access
|--------------------------------------------------------------------------
|
| Example:
|
| requirePermission($conn, $_SESSION['user_id'], 'manage_users');
|
*/


function requirePermission(mysqli $conn, int $userId, string $permissionKey)
{
    if (!userHasPermission($conn, $userId, $permissionKey)) {
        jsonResponse(false, 'Access denied');
    }
}


/*
|--------------------------------------------------------------------------
| Role Creation Rules
|--------------------------------------------------------------------------
|
| Controls who can create which role
|
| super_admin -> all roles
| company_admin -> company_admin, branch_manager, employee, viewer
| branch_manager -> employee, viewer
| employee -> none
| viewer -> none
|
*/


function canCreateRole(string $creatorRole, string $targetRole): bool
{
    $rules = [

        'super_admin' => [
            'super_admin',
            'company_admin',
            'branch_manager',
            'employee',
            'viewer'
        ],

        'company_admin' => [
            'company_admin',
            'branch_manager',
            'employee',
            'viewer'
        ],

        'branch_manager' => [
            'employee',
            'viewer'
        ],

        'employee' => [],

        'viewer' => []
    ];

    if (!isset($rules[$creatorRole])) {
        return false;
    }

    return in_array($targetRole, $rules[$creatorRole]);
}


/*
|--------------------------------------------------------------------------
| Get Logged In User
|--------------------------------------------------------------------------
*/


function getLoggedInUser(mysqli $conn, int $userId)
{
    $sql = "
        SELECT
            id,
            full_name,
            username,
            role,
            company_id,
            branch_id,
            is_active
        FROM users
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return null;
    }

    function getRoleLevel(string $role): int
{
    $levels = [
        'viewer' => 1,
        'employee' => 2,
        'branch_manager' => 3,
        'company_admin' => 4,
        'super_admin' => 5
    ];

    return $levels[$role] ?? 0;
}

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        return null;
    }

    return $result->fetch_assoc();
}