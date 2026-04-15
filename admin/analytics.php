<?php
include 'layout_top.php';
require_once '../config/db.php';
require_once '../config/branch_filter.php';

$branchSelect = $branchColumn ? "$branchColumn AS branch" : "'All branches' AS branch";
$branchGroup = $branchColumn ? $branchColumn : "'All branches'";

$kpiMap = [
    'total_products' => "SELECT COUNT(*) AS total FROM products WHERE 1=1" . $branchFilterSql,
    'active_products' => "SELECT COUNT(*) AS total FROM products WHERE status='active' AND is_removed=0" . $branchFilterSql,
    'near_expiry' => "SELECT COUNT(*) AS total FROM products WHERE status='near_expiry' AND is_removed=0" . $branchFilterSql,
    'expired' => "SELECT COUNT(*) AS total FROM products WHERE status='expired' AND is_removed=0" . $branchFilterSql,
    'removed' => "SELECT COUNT(*) AS total FROM products WHERE status='removed' OR is_removed=1" . $branchFilterSql,
    'total_users' => "SELECT COUNT(*) AS total FROM users"
];

$kpis = [];
foreach ($kpiMap as $key => $sql) {
    if ($branchFilterValue !== null && strpos($sql, '?') !== false) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $branchFilterValue);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
    } else {
        $row = $conn->query($sql)->fetch_assoc();
    }
    $kpis[$key] = (int)$row['total'];
}

$statusSql = "SELECT status, COUNT(*) AS total FROM products WHERE is_removed=0" . $branchFilterSql . " GROUP BY status";
if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($statusSql);
    $stmt->bind_param('s', $branchFilterValue);
    $stmt->execute();
    $statusResult = $stmt->get_result();
} else {
    $statusResult = $conn->query($statusSql);
}
$statusData = [];
while ($row = $statusResult->fetch_assoc()) {
    $statusData[] = $row;
}

$entriesSql = "SELECT DATE(entered_on) AS day, COUNT(*) AS total FROM products WHERE entered_on >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" . $branchFilterSql . " GROUP BY day ORDER BY day ASC";
if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($entriesSql);
    $stmt->bind_param('s', $branchFilterValue);
    $stmt->execute();
    $entriesResult = $stmt->get_result();
} else {
    $entriesResult = $conn->query($entriesSql);
}
$entriesData = [];
while ($row = $entriesResult->fetch_assoc()) {
    $entriesData[] = $row;
}

$expiredBranchSql = "SELECT $branchSelect, COUNT(*) AS total FROM products WHERE status='expired'" . $branchFilterSql . " GROUP BY $branchGroup ORDER BY total DESC LIMIT 6";
if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($expiredBranchSql);
    $stmt->bind_param('s', $branchFilterValue);
    $stmt->execute();
    $expiredBranchResult = $stmt->get_result();
} else {
    $expiredBranchResult = $conn->query($expiredBranchSql);
}
$expiredByBranch = [];
while ($row = $expiredBranchResult->fetch_assoc()) {
    $expiredByBranch[] = $row;
}

$nearExpiryBranchSql = "SELECT $branchSelect, COUNT(*) AS total FROM products WHERE status='near_expiry'" . $branchFilterSql . " GROUP BY $branchGroup ORDER BY total DESC LIMIT 6";
if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($nearExpiryBranchSql);
    $stmt->bind_param('s', $branchFilterValue);
    $stmt->execute();
    $nearExpiryBranchResult = $stmt->get_result();
} else {
    $nearExpiryBranchResult = $conn->query($nearExpiryBranchSql);
}
$nearExpiryByBranch = [];
while ($row = $nearExpiryBranchResult->fetch_assoc()) {
    $nearExpiryByBranch[] = $row;
}

$topUsersSql = "SELECT u.full_name AS user_name, COUNT(*) AS entries FROM products p JOIN users u ON p.entered_by = u.id WHERE 1=1" . ($branchFilterValue !== null ? $branchFilterSqlAlias : '') . " GROUP BY u.id ORDER BY entries DESC LIMIT 6";
if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($topUsersSql);
    $stmt->bind_param('s', $branchFilterValue);
    $stmt->execute();
    $topUsersResult = $stmt->get_result();
} else {
    $topUsersResult = $conn->query($topUsersSql);
}
$topUsers = [];
while ($row = $topUsersResult->fetch_assoc()) {
    $topUsers[] = $row;
}

$topProductsSql = "SELECT product_name, COUNT(*) AS total FROM products WHERE 1=1" . $branchFilterSql . " GROUP BY product_name ORDER BY total DESC LIMIT 6";
if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($topProductsSql);
    $stmt->bind_param('s', $branchFilterValue);
    $stmt->execute();
    $topProductsResult = $stmt->get_result();
} else {
    $topProductsResult = $conn->query($topProductsSql);
}
$topProducts = [];
while ($row = $topProductsResult->fetch_assoc()) {
    $topProducts[] = $row;
}
?>

<div class="dashboard-header mb-4">
    <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3">
        <div>
            <p class="text-muted mb-1">Analytics hub</p>
            <h2 class="fw-bold mb-2">Pro metrics</h2>
            <p class="text-muted mb-0">Chart-driven performance tracking for expiry workflows and branch operations.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="dashboard.php<?= $selectedBranch !== 'all' ? '?branch=' . urlencode($selectedBranch) : '' ?>" class="btn btn-outline-light"><i class="bi bi-arrow-left me-2"></i> Back to dashboard</a>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4 col-sm-6">
        <div class="card stat-card border-0 shadow-sm p-4">
            <small class="text-uppercase text-muted">Total Products</small>
            <h3 class="display-6 mb-2"><?= $kpis['total_products'] ?></h3>
            <p class="text-muted mb-0">All items stored in the system.</p>
        </div>
    </div>
    <div class="col-lg-4 col-sm-6">
        <div class="card stat-card border-0 shadow-sm p-4">
            <small class="text-uppercase text-muted">Active Products</small>
            <h3 class="display-6 mb-2"><?= $kpis['active_products'] ?></h3>
            <p class="text-muted mb-0">Currently valid inventory.</p>
        </div>
    </div>
    <div class="col-lg-4 col-sm-6">
        <div class="card stat-card border-0 shadow-sm p-4">
            <small class="text-uppercase text-muted">Near Expiry</small>
            <h3 class="display-6 mb-2"><?= $kpis['near_expiry'] ?></h3>
            <p class="text-muted mb-0">Products needing attention soon.</p>
        </div>
    </div>
    <div class="col-lg-4 col-sm-6">
        <div class="card stat-card border-0 shadow-sm p-4">
            <small class="text-uppercase text-muted">Expired</small>
            <h3 class="display-6 mb-2"><?= $kpis['expired'] ?></h3>
            <p class="text-muted mb-0">Expired records in the database.</p>
        </div>
    </div>
    <div class="col-lg-4 col-sm-6">
        <div class="card stat-card border-0 shadow-sm p-4">
            <small class="text-uppercase text-muted">Removed</small>
            <h3 class="display-6 mb-2"><?= $kpis['removed'] ?></h3>
            <p class="text-muted mb-0">Items archived from active inventory.</p>
        </div>
    </div>
    <div class="col-lg-4 col-sm-6">
        <div class="card stat-card border-0 shadow-sm p-4">
            <small class="text-uppercase text-muted">Total Users</small>
            <h3 class="display-6 mb-2"><?= $kpis['total_users'] ?></h3>
            <p class="text-muted mb-0">Team members and administrators.</p>
        </div>
    </div>
</div>

<div class="row g-3 mt-4">
    <div class="col-xl-6">
        <div class="card chart-card border-0 shadow-sm p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="mb-1">Products by status</h5>
                    <small class="text-muted">Active, near expiry and expired breakdown.</small>
                </div>
                <span class="badge bg-purple-soft text-purple">Status</span>
            </div>
            <div class="chart-container">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card chart-card border-0 shadow-sm p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="mb-1">Entries by date</h5>
                    <small class="text-muted">Daily product saves for the last 30 days.</small>
                </div>
                <span class="badge bg-info bg-opacity-10 text-info">Trend</span>
            </div>
            <div class="chart-container">
                <canvas id="entriesChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-4">
    <div class="col-xl-6">
        <div class="card chart-card border-0 shadow-sm p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="mb-1">Expired count by branch</h5>
                    <small class="text-muted">Branch-aware expiry risk.</small>
                </div>
                <span class="badge bg-danger bg-opacity-10 text-danger">Expired</span>
            </div>
            <div class="chart-container">
                <canvas id="expiredBranchChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card chart-card border-0 shadow-sm p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="mb-1">Near expiry by branch</h5>
                    <small class="text-muted">Where attention is most needed.</small>
                </div>
                <span class="badge bg-warning bg-opacity-10 text-warning">Near Expiry</span>
            </div>
            <div class="chart-container">
                <canvas id="nearExpiryBranchChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h5 class="mb-3">Top users by entries</h5>
                <?php if (count($topUsers)): ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($topUsers as $user): ?>
                            <li class="list-group-item bg-surface border-0 d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?= htmlspecialchars($user['user_name']) ?></strong>
                                </div>
                                <span class="badge bg-purple-soft text-purple"><?= $user['entries'] ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="empty-state text-center py-5">
                        <i class="bi bi-people-fill fs-1 text-muted mb-3"></i>
                        <p class="text-muted mb-0">No user entry metrics available yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h5 class="mb-3">Most entered products</h5>
                <?php if (count($topProducts)): ?>
                    <div class="table-responsive">
                        <table class="table table-borderless mb-0">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topProducts as $product): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($product['product_name']) ?></td>
                                        <td class="text-end"><?= $product['total'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state text-center py-5">
                        <i class="bi bi-box-seam fs-1 text-muted mb-3"></i>
                        <p class="text-muted mb-0">No product entry activity available.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const statusLabels = <?= json_encode(array_column($statusData, 'status')) ?>;
const statusValues = <?= json_encode(array_column($statusData, 'total')) ?>;
const entriesLabels = <?= json_encode(array_column($entriesData, 'day')) ?>;
const entriesValues = <?= json_encode(array_column($entriesData, 'total')) ?>;
const expiredBranchLabels = <?= json_encode(array_column($expiredByBranch, 'branch')) ?>;
const expiredBranchValues = <?= json_encode(array_column($expiredByBranch, 'total')) ?>;
const nearExpiryBranchLabels = <?= json_encode(array_column($nearExpiryByBranch, 'branch')) ?>;
const nearExpiryBranchValues = <?= json_encode(array_column($nearExpiryByBranch, 'total')) ?>;

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: statusLabels,
        datasets: [{
            data: statusValues,
            backgroundColor: ['#8a53ff', '#20c997', '#ffc107', '#dc3545'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom', labels: { color: '#cbd5e1' } } },
        maintainAspectRatio: false
    }
});

new Chart(document.getElementById('entriesChart'), {
    type: 'line',
    data: {
        labels: entriesLabels,
        datasets: [{
            label: 'Entries',
            data: entriesValues,
            borderColor: '#8a53ff',
            backgroundColor: 'rgba(138,83,255,0.18)',
            fill: true,
            tension: 0.35,
            pointRadius: 3
        }]
    },
    options: {
        responsive: true,
        scales: {
            x: { ticks: { color: '#cbd5e1' }, grid: { color: 'rgba(255,255,255,0.05)' } },
            y: { ticks: { color: '#cbd5e1' }, grid: { color: 'rgba(255,255,255,0.05)' }, beginAtZero: true }
        },
        plugins: { legend: { display: false } },
        maintainAspectRatio: false
    }
});

new Chart(document.getElementById('expiredBranchChart'), {
    type: 'bar',
    data: {
        labels: expiredBranchLabels,
        datasets: [{
            label: 'Expired',
            data: expiredBranchValues,
            backgroundColor: '#dc3545'
        }]
    },
    options: {
        responsive: true,
        scales: {
            x: { ticks: { color: '#cbd5e1' }, grid: { display: false } },
            y: { ticks: { color: '#cbd5e1' }, grid: { color: 'rgba(255,255,255,0.05)' }, beginAtZero: true }
        },
        plugins: { legend: { display: false } },
        maintainAspectRatio: false
    }
});

new Chart(document.getElementById('nearExpiryBranchChart'), {
    type: 'bar',
    data: {
        labels: nearExpiryBranchLabels,
        datasets: [{
            label: 'Near Expiry',
            data: nearExpiryBranchValues,
            backgroundColor: '#ffc107'
        }]
    },
    options: {
        responsive: true,
        scales: {
            x: { ticks: { color: '#cbd5e1' }, grid: { display: false } },
            y: { ticks: { color: '#cbd5e1' }, grid: { color: 'rgba(255,255,255,0.05)' }, beginAtZero: true }
        },
        plugins: { legend: { display: false } },
        maintainAspectRatio: false
    }
});
</script>

<?php include 'layout_bottom.php'; ?>