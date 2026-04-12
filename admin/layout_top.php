<?php
require_once '../config/auth.php';
requireLogin();
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ExpiryGuard Lite Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="d-flex">
    <div class="sidebar bg-dark text-white p-3">
        <h4 class="mb-4">ExpiryGuard Lite</h4>
        <div class="small text-light mb-3">Logged in as: <?php echo htmlspecialchars($_SESSION['full_name']); ?> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</div>
        <a class="sidebar-link <?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">Dashboard</a>
        <a class="sidebar-link <?php echo $currentPage === 'users.php' ? 'active' : ''; ?>" href="users.php">Users</a>
        <a class="sidebar-link <?php echo $currentPage === 'products.php' ? 'active' : ''; ?>" href="products.php">Products</a>
        <a class="sidebar-link <?php echo $currentPage === 'notifications.php' ? 'active' : ''; ?>" href="notifications.php">Notifications</a>
        <a class="sidebar-link <?php echo $currentPage === 'removed.php' ? 'active' : ''; ?>" href="removed.php">Removed</a>
        <a class="sidebar-link mt-3 text-warning" href="../logout.php">Logout</a>
    </div>
    <div class="content p-4 w-100 bg-light min-vh-100">
