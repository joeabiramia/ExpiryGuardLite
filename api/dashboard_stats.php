<?php
require_once '../config/db.php';
require_once '../config/helpers.php';

$stats = [
    'total_products' => 0,
    'active_products' => 0,
    'near_expiry' => 0,
    'expired' => 0,
    'removed' => 0,
    'total_users' => 0,
    'admins' => 0,
    'employees' => 0
];

$queries = [
    'total_products' => "SELECT COUNT(*) AS total FROM products",
    'active_products' => "SELECT COUNT(*) AS total FROM products WHERE status = 'active' AND is_removed = 0",
    'near_expiry' => "SELECT COUNT(*) AS total FROM products WHERE status = 'near_expiry' AND is_removed = 0",
    'expired' => "SELECT COUNT(*) AS total FROM products WHERE status = 'expired' AND is_removed = 0",
    'removed' => "SELECT COUNT(*) AS total FROM products WHERE status = 'removed' OR is_removed = 1",
    'total_users' => "SELECT COUNT(*) AS total FROM users",
    'admins' => "SELECT COUNT(*) AS total FROM users WHERE role = 'admin'",
    'employees' => "SELECT COUNT(*) AS total FROM users WHERE role = 'employee'"
];

foreach ($queries as $key => $sql) {
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $stats[$key] = (int)$row['total'];
}

jsonResponse(true, 'Dashboard stats fetched successfully', $stats);

