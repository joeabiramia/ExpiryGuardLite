<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../admin/login.php');
        exit();
    }
}

function isAdminUser() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'super_admin'], true);
}

function requireAdmin() {
    requireLogin();
    if (!isAdminUser()) {
        die('Access denied. Admin only.');
    }
}