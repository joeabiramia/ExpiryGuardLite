<?php

/*
|--------------------------------------------------------------------------
| API Authentication + Security Headers
|--------------------------------------------------------------------------
|
| resolveApiUser() supports three auth methods in priority order:
|   1. PHP session  (web dashboard)
|   2. X-Auth-Token header  (mobile app, Phase 5+)
|   3. admin_user_id param  (legacy mobile compat — removed after Phase 5)
|
*/

function addSecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit();
    }
}


function resolveApiUser(mysqli $conn): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $userId = 0;

    // 1. Session-based auth (web dashboard)
    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
        $userId = (int)$_SESSION['user_id'];
    }

    // 2. Token-based auth (mobile: X-Auth-Token header or Bearer token)
    if ($userId === 0) {
        $token = '';

        if (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
            $token = trim($_SERVER['HTTP_X_AUTH_TOKEN']);
        } elseif (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = trim($_SERVER['HTTP_AUTHORIZATION']);
            if (stripos($auth, 'Bearer ') === 0) {
                $token = trim(substr($auth, 7));
            }
        }

        if ($token !== '') {
            $stmt = $conn->prepare("
                SELECT user_id
                FROM login_tokens
                WHERE token = ? AND expires_at > NOW() AND revoked = 0
                LIMIT 1
            ");
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $tRes = $stmt->get_result();
            $row  = $tRes->fetch_assoc();
            $tRes->free();
            $stmt->close();
            if ($row) {
                $userId = (int)$row['user_id'];
            }
        }
    }

    // 3. Legacy admin_user_id param (backward compat for current mobile app — Phase 5 removes this)
    if ($userId === 0) {
        $legacyId = (int)($_GET['admin_user_id'] ?? $_POST['admin_user_id'] ?? 0);
        if ($legacyId > 0) {
            $userId = $legacyId;
        }
    }

    if ($userId === 0) {
        jsonResponse(false, 'Authentication required', null, 401);
    }

    $stmt = $conn->prepare("
        SELECT id, full_name, username, role, company_id, branch_id, is_active, created_by
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res  = $stmt->get_result();
    $user = $res->fetch_assoc();
    $res->free();
    $stmt->close();

    if (!$user) {
        jsonResponse(false, 'User not found', null, 401);
    }

    if ((int)$user['is_active'] !== 1) {
        jsonResponse(false, 'Account is inactive', null, 403);
    }

    return $user;
}


function resolveAdminApiUser(mysqli $conn): array
{
    $user = resolveApiUser($conn);

    $adminRoles = ['super_admin', 'company_admin', 'branch_manager'];
    if (!in_array($user['role'], $adminRoles, true)) {
        jsonResponse(false, 'Access denied', null, 403);
    }

    return $user;
}
