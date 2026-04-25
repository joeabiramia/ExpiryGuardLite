<?php include 'layout_top.php'; ?>
<?php require_once '../config/db.php'; ?>
<?php require_once '../config/branch_filter.php'; ?>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_product'])) {
    $id = (int)$_POST['product_id'];
    $removed_by = (int)($_SESSION['user_id'] ?? 0);
    $status = 'removed';

    $stmt = $conn->prepare("
        UPDATE products
        SET
            status = ?,
            is_removed = 1,
            removed_by = ?,
            removed_on = NOW()
        WHERE id = ?
    ");

    $stmt->bind_param('sii', $status, $removed_by, $id);
    $stmt->execute();

    $redirect = 'products.php';
    if ($selectedBranch !== 'all') {
        $redirect .= '?branch=' . urlencode($selectedBranch);
    }

    header('Location: ' . $redirect);
    exit();
}

$sql = "
    SELECT
        p.*,
        u.full_name AS entered_by_name,
        ru.full_name AS removed_by_name
    FROM products p
    LEFT JOIN users u ON p.entered_by = u.id
    LEFT JOIN users ru ON p.removed_by = ru.id
    WHERE 1 = 1
";

if ($branchFilterValue !== null) {
    $sql .= $branchFilterSqlAlias;
}

$sql .= " ORDER BY p.id DESC";

if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $branchFilterValue);
    $stmt->execute();
    $products = $stmt->get_result();
} else {
    $products = $conn->query($sql);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Products</h2>

        <?php if ($selectedBranch !== 'all'): ?>
            <div class="text-muted small">
                Viewing branch:
                <strong><?= htmlspecialchars($selectedBranch) ?></strong>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body table-responsive">

        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Barcode</th>
                    <th>Product</th>
                    <th>Expiry Date</th>
                    <th>Status</th>
                    <th>Entered By</th>
                    <th>Entered On</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody id="productsTableBody">
                <?php while ($product = $products->fetch_assoc()): ?>
                    <tr>
                        <td><?= $product['id'] ?></td>

                        <td><?= htmlspecialchars($product['barcode']) ?></td>

                        <td><?= htmlspecialchars($product['product_name']) ?></td>

                        <td><?= htmlspecialchars($product['expiry_date']) ?></td>

                        <td>
                            <span class="badge bg-<?php
                                echo $product['status'] === 'active'
                                    ? 'success'
                                    : (
                                        $product['status'] === 'near_expiry'
                                            ? 'warning text-dark'
                                            : (
                                                $product['status'] === 'expired'
                                                    ? 'danger'
                                                    : 'secondary'
                                            )
                                    );
                            ?>">
                                <?= htmlspecialchars($product['status']) ?>
                            </span>
                        </td>

                        <td>
                            <?= htmlspecialchars($product['entered_by_name'] ?? 'N/A') ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($product['entered_on']) ?>
                        </td>

                        <td>
                            <?php if ((int)$product['is_removed'] === 0): ?>
                                <form
                                    method="POST"
                                    class="d-inline"
                                    onsubmit="return confirm('Mark this product as removed?');"
                                >
                                    <input
                                        type="hidden"
                                        name="product_id"
                                        value="<?= $product['id'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="branch"
                                        value="<?= htmlspecialchars($selectedBranch) ?>"
                                    >

                                    <button
                                        name="remove_product"
                                        class="btn btn-sm btn-outline-danger"
                                    >
                                        Mark Removed
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted small">
                                    Removed by
                                    <?= htmlspecialchars($product['removed_by_name'] ?? 'N/A') ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>

        </table>
    </div>
</div>

<script>
function getStatusBadge(status) {
    if (status === 'active') {
        return '<span class="badge bg-success">active</span>';
    }

    if (status === 'near_expiry') {
        return '<span class="badge bg-warning text-dark">near_expiry</span>';
    }

    if (status === 'expired') {
        return '<span class="badge bg-danger">expired</span>';
    }

    return '<span class="badge bg-secondary">' + status + '</span>';
}

function loadProductsLive() {
    const branch = "<?= htmlspecialchars($selectedBranch) ?>";

    fetch(`../api/live_products.php?branch=${encodeURIComponent(branch)}`)
        .then(response => response.json())
        .then(result => {
            if (!result.success) {
                console.error(result.message);
                return;
            }

            let html = '';

            result.data.forEach(product => {
                let actions = '';

                if (parseInt(product.is_removed) === 0) {
                    actions = `
                        <form method="POST"
                              class="d-inline"
                              onsubmit="return confirm('Mark this product as removed?');">
                            <input type="hidden"
                                   name="product_id"
                                   value="${product.id}">

                            <input type="hidden"
                                   name="branch"
                                   value="${branch}">

                            <button name="remove_product"
                                    class="btn btn-sm btn-outline-danger">
                                Mark Removed
                            </button>
                        </form>
                    `;
                } else {
                    actions = `
                        <span class="text-muted small">
                            Removed by ${product.removed_by_name ?? 'N/A'}
                        </span>
                    `;
                }

                html += `
                    <tr>
                        <td>${product.id}</td>
                        <td>${product.barcode}</td>
                        <td>${product.product_name}</td>
                        <td>${product.expiry_date}</td>
                        <td>${getStatusBadge(product.status)}</td>
                        <td>${product.entered_by_name ?? 'N/A'}</td>
                        <td>${product.entered_on}</td>
                        <td>${actions}</td>
                    </tr>
                `;
            });

            document.getElementById('productsTableBody').innerHTML = html;
        })
        .catch(error => {
            console.error('Live update failed:', error);
        });
}

setInterval(loadProductsLive, 10000);
</script>

<?php include 'layout_bottom.php'; ?>