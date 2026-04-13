<?php
include 'layout_top.php';
require_once '../config/db.php';

$search = trim($_GET['q'] ?? '');

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

<!-- Search + Export -->
<form method="GET" class="mb-4">
    <div class="input-group shadow-sm">
        <input
            type="text"
            class="form-control"
            name="q"
            placeholder="Search barcode, product, expiry date, status..."
            value="<?= htmlspecialchars($search) ?>"
        >
        <button class="btn btn-primary" type="submit">Search</button>

        <a
            href="export_csv.php<?= $search ? '?q=' . urlencode($search) : '' ?>"
            class="btn btn-success"
        >
            Export CSV
        </a>

        <?php if ($search): ?>
            <a href="dashboard.php" class="btn btn-outline-secondary">Clear</a>
        <?php endif; ?>
    </div>
</form>

<!-- Stats -->
<div class="row g-3">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h6>Total Products</h6>
                <h3><?= $stats['total_products'] ?></h3>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h6>Near Expiry</h6>
                <h3><?= $stats['near_expiry'] ?></h3>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h6>Expired</h6>
                <h3><?= $stats['expired'] ?></h3>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h6>Removed</h6>
                <h3><?= $stats['removed'] ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <h6>Total Users</h6>
                <h3><?= $stats['total_users'] ?></h3>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <h6>Admins</h6>
                <h3><?= $stats['admins'] ?></h3>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <h6>Employees</h6>
                <h3><?= $stats['employees'] ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- How it works -->
<?php if (!$search): ?>
<div class="card mt-4 border-0 shadow-sm">
    <div class="card-body">
        <h5>How it works</h5>
        <p class="mb-0">
            The Android app saves product data directly into the same MySQL database using
            <code>save_product.php</code>.
            The admin panel reads the same tables for product management, notifications,
            removed items, and user control.
        </p>
    </div>
</div>
<?php endif; ?>

<!-- Search Results -->
<?php if ($search): ?>
<?php
$stmt = $conn->prepare("
    SELECT barcode, product_name, expiry_date, status
    FROM products
    WHERE barcode LIKE CONCAT('%', ?, '%')
       OR product_name LIKE CONCAT('%', ?, '%')
       OR expiry_date LIKE CONCAT('%', ?, '%')
       OR status LIKE CONCAT('%', ?, '%')
    ORDER BY entered_on DESC
");
$stmt->bind_param("ssss", $search, $search, $search, $search);
$stmt->execute();
$result = $stmt->get_result();
?>

<div class="card mt-4 border-0 shadow-sm">
    <div class="card-body">
        <h5>Search Results (<?= $result->num_rows ?>)</h5>

        <?php if ($result->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Barcode</th>
                            <th>Product</th>
                            <th>Expiry Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['barcode']) ?></td>
                                <td><?= htmlspecialchars($row['product_name']) ?></td>
                                <td><?= htmlspecialchars($row['expiry_date']) ?></td>
                                <td><?= htmlspecialchars($row['status']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">No matching products found.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Auto refresh -->
<?php if (!$search): ?>
<script>
setInterval(() => {
    location.reload();
}, 5000);
</script>
<?php endif; ?>

<?php include 'layout_bottom.php'; ?>