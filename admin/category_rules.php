<?php
include 'layout_top.php';
require_once '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_name = trim($_POST['category_name'] ?? '');
    $alert_days_before = (int)($_POST['alert_days_before'] ?? 4);
    $auto_remove_days_before = (int)($_POST['auto_remove_days_before'] ?? 0);

    if ($category_name !== '') {
        $stmt = $conn->prepare("
            INSERT INTO category_rules
            (category_name, alert_days_before, auto_remove_days_before)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                alert_days_before = VALUES(alert_days_before),
                auto_remove_days_before = VALUES(auto_remove_days_before)
        ");
        $stmt->bind_param(
            "sii",
            $category_name,
            $alert_days_before,
            $auto_remove_days_before
        );
        $stmt->execute();
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    $stmt = $conn->prepare("DELETE FROM category_rules WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    header("Location: category_rules.php");
    exit;
}

$result = $conn->query("
    SELECT *
    FROM category_rules
    ORDER BY category_name ASC
");
?>

<h2 class="mb-4">Category Expiry Rules</h2>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <h5 class="mb-3">Add / Update Rule</h5>

        <form method="POST" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Category Name</label>
                <input
                    type="text"
                    class="form-control"
                    name="category_name"
                    placeholder="e.g. Dairy"
                    required
                >
            </div>

            <div class="col-md-3">
                <label class="form-label">Alert Days Before</label>
                <input
                    type="number"
                    class="form-control"
                    name="alert_days_before"
                    min="1"
                    value="4"
                    required
                >
            </div>

            <div class="col-md-3">
                <label class="form-label">Auto Remove Days Before</label>
                <input
                    type="number"
                    class="form-control"
                    name="auto_remove_days_before"
                    min="0"
                    value="0"
                    required
                >
            </div>

            <div class="col-md-2 d-grid">
                <label class="form-label invisible">Save</label>
                <button class="btn btn-primary">Save Rule</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <h5 class="mb-3">Current Rules</h5>

        <?php if ($result->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Alert Before</th>
                            <th>Auto Remove Before</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['category_name']) ?></td>
                                <td><?= $row['alert_days_before'] ?> days</td>
                                <td><?= $row['auto_remove_days_before'] ?> days</td>
                                <td><?= $row['created_at'] ?></td>
                                <td>
                                    <a
                                        href="?delete=<?= $row['id'] ?>"
                                        class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('Delete this rule?')"
                                    >
                                        Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">No category rules added yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php include 'layout_bottom.php'; ?>