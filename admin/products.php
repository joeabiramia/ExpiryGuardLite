<?php include 'layout_top.php'; ?>
<?php require_once '../config/db.php'; ?>
<?php
$sql = "SELECT p.*, u.full_name AS entered_by_name, ru.full_name AS removed_by_name
        FROM products p
        LEFT JOIN users u ON p.entered_by = u.id
        LEFT JOIN users ru ON p.removed_by = ru.id
        ORDER BY p.id DESC";
$products = $conn->query($sql);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_product'])) {
    $id = (int)$_POST['product_id'];
    $removed_by = (int)$_SESSION['user_id'];
    $status = 'removed';
    $stmt = $conn->prepare('UPDATE products SET status=?, is_removed=1, removed_by=?, removed_on=NOW() WHERE id=?');
    $stmt->bind_param('sii', $status, $removed_by, $id);
    $stmt->execute();
    header('Location: products.php'); exit();
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Products</h2>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>ID</th><th>Barcode</th><th>Product</th><th>Expiry Date</th><th>Status</th><th>Entered By</th><th>Entered On</th><th>Actions</th></tr></thead>
            <tbody>
            <?php while ($product = $products->fetch_assoc()): ?>
                <tr>
                    <td><?= $product['id'] ?></td>
                    <td><?= htmlspecialchars($product['barcode']) ?></td>
                    <td><?= htmlspecialchars($product['product_name']) ?></td>
                    <td><?= htmlspecialchars($product['expiry_date']) ?></td>
                    <td><span class="badge bg-<?php
                        echo $product['status'] === 'active' ? 'success' :
                            ($product['status'] === 'near_expiry' ? 'warning text-dark' :
                            ($product['status'] === 'expired' ? 'danger' : 'secondary'));
                    ?>"><?= htmlspecialchars($product['status']) ?></span></td>
                    <td><?= htmlspecialchars($product['entered_by_name'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($product['entered_on']) ?></td>
                    <td>
                        <?php if ((int)$product['is_removed'] === 0): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Mark this product as removed?');">
                            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                            <button name="remove_product" class="btn btn-sm btn-outline-danger">Mark Removed</button>
                        </form>
                        <?php else: ?>
                        <span class="text-muted small">Removed by <?= htmlspecialchars($product['removed_by_name'] ?? 'N/A') ?></span>
                        <?php endif; ?>
                    </td>
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
