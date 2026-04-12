<?php include 'layout_top.php'; ?>
<?php require_once '../config/db.php'; ?>
<?php
$sql = "SELECT p.*, u.full_name AS entered_by_name, ru.full_name AS removed_by_name
        FROM products p
        LEFT JOIN users u ON p.entered_by = u.id
        LEFT JOIN users ru ON p.removed_by = ru.id
        WHERE p.status = 'removed' OR p.is_removed = 1
        ORDER BY p.removed_on DESC";
$items = $conn->query($sql);
?>
<h2 class="mb-4">Removed Products</h2>
<div class="card border-0 shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-striped align-middle">
            <thead><tr><th>ID</th><th>Barcode</th><th>Product</th><th>Expiry Date</th><th>Entered By</th><th>Removed By</th><th>Removed On</th></tr></thead>
            <tbody>
            <?php while ($item = $items->fetch_assoc()): ?>
                <tr>
                    <td><?= $item['id'] ?></td>
                    <td><?= htmlspecialchars($item['barcode']) ?></td>
                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                    <td><?= htmlspecialchars($item['expiry_date']) ?></td>
                    <td><?= htmlspecialchars($item['entered_by_name'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($item['removed_by_name'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($item['removed_on']) ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include 'layout_bottom.php'; ?>
