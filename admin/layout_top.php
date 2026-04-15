<?php
require_once '../config/auth.php';
require_once '../config/db.php';
requireLogin();

$currentPage = basename($_SERVER['PHP_SELF']);
$companyName = 'ExpiryGuard Pro';

$userName = htmlspecialchars($_SESSION['full_name'] ?? 'Administrator');
$userRole = htmlspecialchars($_SESSION['role'] ?? 'Admin');
$userInitial = strtoupper(substr(strip_tags($userName), 0, 1));

$selectedBranch = trim($_GET['branch'] ?? 'all');

$branchColumn = null;
$branchCandidates = ['branch', 'branch_name', 'branch_code', 'branch_id'];

foreach ($branchCandidates as $candidate) {
    $result = $conn->query("SHOW COLUMNS FROM products LIKE '" . $conn->real_escape_string($candidate) . "'");
    if ($result && $result->num_rows > 0) {
        $branchColumn = $candidate;
        break;
    }
}

$branches = [];

if ($branchColumn === 'branch_id') {
    $branchQuery = $conn->query("
        SELECT id, branch_name
        FROM branches
        ORDER BY branch_name ASC
    ");

    while ($row = $branchQuery->fetch_assoc()) {
        $branches[] = [
            'id' => $row['id'],
            'name' => $row['branch_name']
        ];
    }
} elseif ($branchColumn) {
    $branchQuery = $conn->query("
        SELECT DISTINCT `$branchColumn` AS branch_value
        FROM products
        WHERE `$branchColumn` IS NOT NULL
          AND `$branchColumn` <> ''
        ORDER BY `$branchColumn` ASC
    ");

    while ($row = $branchQuery->fetch_assoc()) {
        $branches[] = [
            'id' => $row['branch_value'],
            'name' => $row['branch_value']
        ];
    }
}

$navItems = [
    ['file' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => 'bi-speedometer2'],
    ['file' => 'products.php', 'label' => 'Products', 'icon' => 'bi-box-seam'],
    ['file' => 'notifications.php', 'label' => 'Notifications', 'icon' => 'bi-bell'],
    ['file' => 'removed.php', 'label' => 'Removed Products', 'icon' => 'bi-trash3'],
    ['file' => 'category_rules.php', 'label' => 'Category Rules', 'icon' => 'bi-tags'],
    ['file' => 'users.php', 'label' => 'Users', 'icon' => 'bi-people'],
    // ['file' => 'reports.php', 'label' => 'Reports', 'icon' => 'bi-file-earmark-text'],
    ['file' => 'analytics.php', 'label' => 'Analytics', 'icon' => 'bi-bar-chart-line']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($companyName) ?> Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.4/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="admin-shell d-flex min-vh-100">

    <aside class="sidebar d-flex flex-column">
        <div>
            <div class="brand d-flex align-items-center gap-3 mb-4">
                <div class="brand-logo">
                    <i class="bi bi-shield-lock-fill"></i>
                </div>
                <div>
                    <h5 class="mb-0 fw-bold"><?= htmlspecialchars($companyName) ?></h5>
                    <small class="text-muted">Enterprise inventory</small>
                </div>
            </div>

            <nav class="nav flex-column">
                <?php foreach ($navItems as $item): ?>
                    <?php $navHref = $item['file'] . ($selectedBranch !== 'all' ? '?branch=' . urlencode($selectedBranch) : ''); ?>
                    <a class="nav-link d-flex align-items-center gap-2 <?= $currentPage === $item['file'] ? 'active' : '' ?>" href="<?= $navHref ?>">
                        <i class="bi <?= $item['icon'] ?>"></i>
                        <span><?= $item['label'] ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <div class="sidebar-footer mt-auto">
            <div class="small text-muted mb-3">Secure branch workflows</div>
            <a href="../logout.php" class="btn btn-outline-secondary w-100">
                <i class="bi bi-box-arrow-right me-2"></i>Sign out
            </a>
        </div>
    </aside>

    <main class="page-content flex-grow-1">
        <header class="topbar">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <small class="text-muted d-block mb-1">Welcome back</small>
                    <h2 class="mb-1 fw-bold"><?= htmlspecialchars($companyName) ?> Admin</h2>
                    <p class="text-muted mb-0 small">Enterprise inventory insights and analytics.</p>
                </div>

                <div class="d-flex align-items-center gap-3 flex-wrap bg-white border rounded-4 px-3 py-2 shadow-sm">
                    <form method="GET" class="branch-selector d-flex flex-column">
                        <small class="text-muted d-block mb-1">Branch</small>

                        <?php
                        foreach ($_GET as $key => $value) {
                            if ($key === 'branch') {
                                continue;
                            }
                            if (is_array($value)) {
                                continue;
                            }
                            echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
                        }
                        ?>

                        <select name="branch" class="form-select form-select-sm" onchange="this.form.submit()" <?= !$branchColumn ? 'disabled' : '' ?>>
                            <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All branches</option>
                            <?php foreach ($branches as $branch): ?>
    <option
        value="<?= htmlspecialchars($branch['id']) ?>"
        <?= (string)$selectedBranch === (string)$branch['id'] ? 'selected' : '' ?>
    >
        <?= htmlspecialchars($branch['name']) ?>
    </option>
<?php endforeach; ?>
                        </select>
                    </form>

                    <div class="user-chip d-flex align-items-center gap-2">
                        <div class="avatar">
                            <?= htmlspecialchars($userInitial) ?>
                        </div>
                        <div>
                            <div class="fw-semibold small"><?= $userName ?></div>
                            <small class="text-muted text-uppercase"><?= htmlspecialchars($userRole) ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="page-body container-fluid">