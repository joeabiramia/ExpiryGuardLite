<?php include 'layout_top.php'; ?>
<?php require_once '../config/db.php'; ?>
<?php require_once '../config/branch_filter.php'; ?>
<?php
$sql = "SELECT p.*, u.full_name AS entered_by_name
        FROM products p
        LEFT JOIN users u ON p.entered_by = u.id
        WHERE p.status IN ('near_expiry', 'expired') AND p.is_removed = 0" . ($branchFilterValue !== null ? $branchFilterSqlAlias : '') . "
        ORDER BY p.expiry_date ASC";

if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $branchFilterValue);
    $stmt->execute();
    $items = $stmt->get_result();
} else {
    $items = $conn->query($sql);
}
?>

<h2 class="mb-4">Notifications</h2>
<?php if ($selectedBranch !== 'all'): ?>
    <div class="text-muted small mb-3">Viewing: <?= htmlspecialchars($selectedBranch) ?></div>
<?php endif; ?>
<div class="card border-0 shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-bordered align-middle">
            <thead><tr><th>ID</th><th>Barcode</th><th>Product</th><th>Expiry Date</th><th>Status</th><th>Entered By</th></tr></thead>
            <tbody>
            <?php while ($item = $items->fetch_assoc()): ?>
                <tr>
                    <td><?= $item['id'] ?></td>
                    <td><?= htmlspecialchars($item['barcode']) ?></td>
                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                    <td><?= htmlspecialchars($item['expiry_date']) ?></td>
                    <td><span class="badge bg-<?= $item['status'] === 'expired' ? 'danger' : 'warning text-dark' ?>"><?= htmlspecialchars($item['status']) ?></span></td>
                    <td><?= htmlspecialchars($item['entered_by_name'] ?? 'N/A') ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
setInterval(() => {
    location.reload();
}, 5000);
</script>
<?php include 'layout_bottom.php'; ?>
