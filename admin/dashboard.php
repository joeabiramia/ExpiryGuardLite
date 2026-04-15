<?php
include 'layout_top.php';
require_once '../config/branch_filter.php';

$search = trim($_GET['q'] ?? '');

$stats = [
    'total_products' => 0,
    'active_products' => 0,
    'near_expiry' => 0,
    'expired' => 0,
    'removed' => 0,
    'total_users' => 0
];

$map = [
    'total_products' => "SELECT COUNT(*) AS total FROM products WHERE 1=1" . $branchFilterSql,
    'active_products' => "SELECT COUNT(*) AS total FROM products WHERE status='active' AND is_removed=0" . $branchFilterSql,
    'near_expiry' => "SELECT COUNT(*) AS total FROM products WHERE status='near_expiry' AND is_removed=0" . $branchFilterSql,
    'expired' => "SELECT COUNT(*) AS total FROM products WHERE status='expired' AND is_removed=0" . $branchFilterSql,
    'removed' => "SELECT COUNT(*) AS total FROM products WHERE (status='removed' OR is_removed=1)" . $branchFilterSql,
    'total_users' => "SELECT COUNT(*) AS total FROM users"
];

foreach ($map as $key => $sql) {
    if ($branchFilterValue !== null && strpos($sql, '?') !== false) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $branchFilterValue);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
    } else {
        $row = $conn->query($sql)->fetch_assoc();
    }
    $stats[$key] = (int)$row['total'];
}

$statusSql = "SELECT status, COUNT(*) AS total FROM products WHERE is_removed = 0" . $branchFilterSql . " GROUP BY status";
if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($statusSql);
    $stmt->bind_param('s', $branchFilterValue);
    $stmt->execute();
    $statusResults = $stmt->get_result();
} else {
    $statusResults = $conn->query($statusSql);
}

$statuses = [];
while ($row = $statusResults->fetch_assoc()) {
    $statuses[] = [$row['status'], (int)$row['total']];
}

$trendSql = "SELECT DATE(entered_on) AS day, COUNT(*) AS total
    FROM products
    WHERE entered_on >= DATE_SUB(CURDATE(), INTERVAL 10 DAY)" . $branchFilterSql . "
    GROUP BY day
    ORDER BY day ASC";

if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($trendSql);
    $stmt->bind_param('s', $branchFilterValue);
    $stmt->execute();
    $trendResults = $stmt->get_result();
} else {
    $trendResults = $conn->query($trendSql);
}

$trendLabels = [];
$trendData = [];
while ($row = $trendResults->fetch_assoc()) {
    $trendLabels[] = $row['day'];
    $trendData[] = (int)$row['total'];
}

$topProductsSql = "SELECT product_name, COUNT(*) AS total
    FROM products
    WHERE 1=1" . $branchFilterSql . "
    GROUP BY product_name
    ORDER BY total DESC
    LIMIT 5";

if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($topProductsSql);
    $stmt->bind_param('s', $branchFilterValue);
    $stmt->execute();
    $topProductsResults = $stmt->get_result();
} else {
    $topProductsResults = $conn->query($topProductsSql);
}

$topProducts = [];
while ($row = $topProductsResults->fetch_assoc()) {
    $topProducts[] = $row;
}

$topBranches = [];
if ($branchColumn) {
    $topBranchesSql = "SELECT `$branchColumn` AS branch, COUNT(*) AS total
        FROM products
        WHERE 1=1" . $branchFilterSql . "
        GROUP BY `$branchColumn`
        ORDER BY total DESC
        LIMIT 5";

    if ($branchFilterValue !== null) {
        $stmt = $conn->prepare($topBranchesSql);
        $stmt->bind_param('s', $branchFilterValue);
        $stmt->execute();
        $branchResults = $stmt->get_result();
    } else {
        $branchResults = $conn->query($topBranchesSql);
    }

    while ($row = $branchResults->fetch_assoc()) {
        $topBranches[] = $row;
    }
}
?>

<div class="dashboard-hero mb-4">
    <div class="card border-0 shadow-sm py-3 px-4">
        <p class="text-uppercase text-muted mb-2 small">Executive insights</p>
        <h1 class="h3 mb-2 fw-bold">Inventory command centre</h1>
        <p class="text-muted mb-0">
            Premium analytics for supermarkets, pharmacies, warehouses and retail enterprises.
            <?php if ($selectedBranch !== 'all'): ?>
                <span class="fw-semibold">Viewing: <?= htmlspecialchars($selectedBranch) ?></span>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php
$cards = [
    ['Total Products', $stats['total_products'], 'Inventory', 'secondary', 'Across all batches'],
    ['Active Products', $stats['active_products'], 'Live', 'success', 'Currently valid'],
    ['Near Expiry', $stats['near_expiry'], 'Urgent', 'warning', 'Requires action'],
    ['Expired', $stats['expired'], 'Critical', 'danger', 'Immediate removal'],
    ['Removed', $stats['removed'], 'Archived', 'secondary', 'Historical records'],
    ['Total Users', $stats['total_users'], 'Team', 'info', 'Admin + employees']
];
?>

<div class="row g-3 mb-4">
    <?php foreach ($cards as [$title, $value, $badge, $color, $meta]): ?>
        <div class="col-lg-4 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <small class="text-uppercase text-muted"><?= $title ?></small>
                        <span class="badge rounded-pill bg-<?= $color ?> <?= in_array($color, ['warning', 'secondary']) ? 'text-dark' : 'text-white' ?>">
                            <?= $badge ?>
                        </span>
                    </div>
                    <div class="display-4 fw-bold"><?= $value ?></div>
                    <div class="small text-muted mt-2"><?= $meta ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h5 class="mb-1 fw-semibold">Products by status</h5>
                <p class="text-muted small mb-3">Inventory health snapshot</p>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h5 class="mb-1 fw-semibold">Products added per day</h5>
                <p class="text-muted small mb-3">Last 10 days</p>
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h5 class="mb-1">Inventory toolkit</h5>
                <p class="text-muted mb-0 small">Search and operational quick actions</p>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <a href="export_csv.php?<?= http_build_query(['q' => $search, 'branch' => $selectedBranch]) ?>" class="btn btn-outline-secondary btn-sm">
                    Export CSV
                </a>
                <a href="analytics.php?<?= http_build_query(['branch' => $selectedBranch]) ?>" class="btn btn-outline-secondary btn-sm">
                    Analytics
                </a>
                <a href="products.php?<?= http_build_query(['branch' => $selectedBranch]) ?>" class="btn btn-primary btn-sm">
                    Add Product
                </a>
            </div>
        </div>

        <form method="GET" class="row g-2">
            <input type="hidden" name="branch" value="<?= htmlspecialchars($selectedBranch) ?>">
            <div class="col-lg-8">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input
                        type="text"
                        name="q"
                        class="form-control"
                        placeholder="Search products, barcode, status"
                        value="<?= htmlspecialchars($search) ?>"
                    >
                </div>
            </div>
            <div class="col-lg-4 d-grid">
                <button class="btn btn-primary">Apply Search</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <h5 class="mb-3">Natural language SQL query</h5>
        <p class="text-muted small mb-3">Ask the system for inventory data in plain English. Only SELECT queries are allowed.</p>
        <form id="nlpQueryForm" class="row g-3">
            <div class="col-lg-10">
                <input type="text" id="query" name="query" class="form-control" placeholder="e.g., What are the near expiry items in April in Jbeil branch?" required>
            </div>
            <div class="col-lg-2 d-grid">
                <button type="submit" class="btn btn-primary">Run Query</button>
            </div>
        </form>
        <div id="nlpStatus" class="mt-3 text-muted small"></div>
        <div id="nlpResults" class="mt-3"></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h5 class="mb-1 fw-semibold">Priority inventory signals</h5>
                <p class="text-muted small mb-3">Top recurring products in operational flow</p>

                <?php if (count($topProducts)): ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($topProducts as $index => $row): ?>
                            <li class="list-group-item border-0 px-0 py-3 d-flex justify-content-between">
                                <div>
                                    <strong><?= htmlspecialchars($row['product_name']) ?></strong>
                                    <div class="small text-muted">Rank <?= $index + 1 ?></div>
                                </div>
                                <span class="badge bg-secondary text-dark rounded-pill"><?= $row['total'] ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="text-muted small">No product insights yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h5 class="mb-1 fw-semibold">Branch performance</h5>
                <p class="text-muted small mb-3">Highest activity by location</p>

                <?php if ($branchColumn && count($topBranches)): ?>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Branch</th>
                                    <th class="text-end">Entries</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topBranches as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['branch'] ?: 'Unknown') ?></td>
                                        <td class="text-end"><?= $row['total'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-muted small">Branch tracking not configured yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($search): ?>
<?php
$searchSql = "
    SELECT barcode, product_name, expiry_date, status
    FROM products
    WHERE (
        barcode LIKE CONCAT('%', ?, '%')
        OR product_name LIKE CONCAT('%', ?, '%')
        OR expiry_date LIKE CONCAT('%', ?, '%')
        OR status LIKE CONCAT('%', ?, '%')
    )
";

if ($branchColumn && $selectedBranch !== 'all') {
    $searchSql .= " AND `$branchColumn` = ?";
}

$searchSql .= " ORDER BY entered_on DESC";

$stmt = $conn->prepare($searchSql);

if ($branchColumn && $selectedBranch !== 'all') {
    $stmt->bind_param("sssss", $search, $search, $search, $search, $selectedBranch);
} else {
    $stmt->bind_param("ssss", $search, $search, $search, $search);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<div class="card mt-4 border-0 shadow-sm">
    <div class="card-body">
        <h5 class="mb-3">Search Results (<?= $result->num_rows ?>)</h5>

        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
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
                            <td>
                                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill">
                                    <?= htmlspecialchars($row['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const statusLabels = <?= json_encode(array_column($statuses, 0)) ?>;
const statusValues = <?= json_encode(array_column($statuses, 1)) ?>;
const trendLabels = <?= json_encode($trendLabels) ?>;
const trendValues = <?= json_encode($trendData) ?>;

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: statusLabels,
        datasets: [{
            data: statusValues,
            backgroundColor: ['#7c3aed', '#22c55e', '#f59e0b', '#ef4444'],
            borderWidth: 0
        }]
    },
    options: {
        plugins: {
            legend: {
                position: 'bottom',
                labels: { color: '#64748b' }
            }
        },
        maintainAspectRatio: false
    }
});

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: trendLabels,
        datasets: [{
            data: trendValues,
            borderColor: '#7c3aed',
            backgroundColor: 'rgba(124,58,237,0.12)',
            fill: true,
            tension: 0.35
        }]
    },
    options: {
        scales: {
            x: {
                ticks: { color: '#64748b' },
                grid: { color: 'rgba(148,163,184,0.15)' }
            },
            y: {
                ticks: { color: '#64748b' },
                grid: { color: 'rgba(148,163,184,0.15)' },
                beginAtZero: true
            }
        },
        plugins: {
            legend: { display: false }
        },
        maintainAspectRatio: false
    }
});

const nlpForm = document.getElementById('nlpQueryForm');
const nlpStatus = document.getElementById('nlpStatus');
const nlpResults = document.getElementById('nlpResults');

nlpForm.addEventListener('submit', async function (e) {
    e.preventDefault();
    const query = document.getElementById('query').value.trim();
    if (!query) {
        return;
    }

    nlpStatus.textContent = 'Running query...';
    nlpResults.innerHTML = '';

    try {
        const response = await fetch('../api/nlp_query.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({ query })
        });

        const text = await response.text();
        let result;

        try {
            result = JSON.parse(text);
        } catch (parseError) {
            throw new Error(text || 'Invalid JSON response from server.');
        }

        if (result.error) {
            nlpStatus.textContent = result.error;
            nlpResults.innerHTML = '';
            return;
        }

        nlpStatus.textContent = `SQL generated: ${result.sql}`;

        if (!Array.isArray(result.data) || result.data.length === 0) {
            nlpResults.innerHTML = '<div class="text-muted">No rows returned.</div>';
            return;
        }

        const columns = Object.keys(result.data[0]);
        let html = '<div class="table-responsive"><table class="table table-sm table-striped">';
        html += '<thead><tr>' + columns.map(column => `<th>${column}</th>`).join('') + '</tr></thead>';
        html += '<tbody>';

        result.data.forEach(row => {
            html += '<tr>' + columns.map(column => `<td>${row[column] !== null ? htmlspecialchars(row[column]) : ''}</td>`).join('') + '</tr>';
        });

        html += '</tbody></table></div>';
        nlpResults.innerHTML = html;
    } catch (error) {
        nlpStatus.textContent = 'An error occurred while running the query.';
        nlpResults.innerHTML = '';
        console.error(error);
    }
});

function htmlspecialchars(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}
</script>

<?php include 'layout_bottom.php'; ?>