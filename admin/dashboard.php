<?php include 'layout_top.php'; ?>
<?php require_once '../config/db.php'; ?>
<?php
$stats = [
    'total_products' => 0,
    'near_expiry' => 0,
    'expired' => 0,
    'removed' => 0,
    'total_users' => 0,
    'admins' => 0,
    'employees' => 0
];

$map = [
    'total_products' => "SELECT COUNT(*) AS total FROM products",
    'near_expiry' => "SELECT COUNT(*) AS total FROM products WHERE status='near_expiry' AND is_removed=0",
    'expired' => "SELECT COUNT(*) AS total FROM products WHERE status='expired' AND is_removed=0",
    'removed' => "SELECT COUNT(*) AS total FROM products WHERE status='removed' OR is_removed=1",
    'total_users' => "SELECT COUNT(*) AS total FROM users",
    'admins' => "SELECT COUNT(*) AS total FROM users WHERE role='admin'",
    'employees' => "SELECT COUNT(*) AS total FROM users WHERE role='employee'"
];

foreach ($map as $key => $sql) {
    $row = $conn->query($sql)->fetch_assoc();
    $stats[$key] = (int)$row['total'];
}
?>
<h2 class="mb-4">Dashboard</h2>
<div class="row g-3">
    <div class="col-md-3"><div class="card stat-card"><div class="card-body"><h6>Total Products</h6><h3><?= $stats['total_products'] ?? 0 ?></h3></div></div></div>
    <div class="col-md-3"><div class="card stat-card"><div class="card-body"><h6>Near Expiry</h6><h3><?= $stats['near_expiry'] ?? 0 ?></h3></div></div></div>
    <div class="col-md-3"><div class="card stat-card"><div class="card-body"><h6>Expired</h6><h3><?= $stats['expired'] ?? 0 ?></h3></div></div></div>
    <div class="col-md-3"><div class="card stat-card"><div class="card-body"><h6>Removed</h6><h3><?= $stats['removed'] ?? 0 ?></h3></div></div></div>
</div>
<div class="row g-3 mt-1">
    <div class="col-md-4"><div class="card stat-card"><div class="card-body"><h6>Total Users</h6><h3><?= $stats['total_users'] ?? 0 ?></h3></div></div></div>
    <div class="col-md-4"><div class="card stat-card"><div class="card-body"><h6>Admins</h6><h3><?= $stats['admins'] ?? 0 ?></h3></div></div></div>
    <div class="col-md-4"><div class="card stat-card"><div class="card-body"><h6>Employees</h6><h3><?= $stats['employees'] ?? 0 ?></h3></div></div></div>
</div>
<div class="card mt-4 border-0 shadow-sm">
    <div class="card-body">
        <h5>How it works</h5>
        <p class="mb-0">The Android app saves product data directly into the same MySQL database using <code>save_product.php</code>. The admin panel reads the same tables for product management, notifications, removed items, and user control.</p>
    </div>
</div>
<script>
setInterval(() => {
    location.reload();
}, 5000);
</script>
<?php include 'layout_bottom.php'; ?>
