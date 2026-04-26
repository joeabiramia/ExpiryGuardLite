<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin(): void
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: /index.php');
        exit();
    }
}

function isAdminUser(): bool
{
    return isset($_SESSION['role']) && in_array(
        $_SESSION['role'],
        ['super_admin', 'company_admin', 'branch_manager'],
        true
    );
}

function requireAdmin(): void
{
    requireLogin();
    if (!isAdminUser()) {
        http_response_code(403);
        die('Access denied.');
    }
}