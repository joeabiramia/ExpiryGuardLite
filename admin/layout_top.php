<?php
require_once '../config/auth.php';
require_once '../config/helpers.php';
require_once '../config/db.php';
requireLogin();

// Refresh product statuses every 10 hours per session
$_lastRefresh = (int)($_SESSION['last_status_refresh'] ?? 0);
if (time() - $_lastRefresh > 36000) {
    refreshProductStatuses($conn, (int)($_SESSION['company_id'] ?? 1));
    $_SESSION['last_status_refresh'] = time();
}

$currentPage  = basename($_SERVER['PHP_SELF']);
$userRole     = $_SESSION['role']      ?? 'viewer';
$userName     = htmlspecialchars($_SESSION['full_name'] ?? 'User');
$userInitial  = strtoupper(substr(strip_tags($userName), 0, 1));
$companyId    = (int)($_SESSION['company_id'] ?? 0);
$branchId     = (int)($_SESSION['branch_id']  ?? 0);

// Branch selector (admins can switch; others are locked to their branch)
$canSwitchBranch = in_array($userRole, ['super_admin', 'company_admin'], true);
$selectedBranch  = $canSwitchBranch ? trim($_GET['branch'] ?? 'all') : ($branchId > 0 ? (string)$branchId : 'all');

// Load branches for selector
$branchSql    = "SELECT id, branch_name FROM branches WHERE is_active = 1";
$branchParams = [];
$branchTypes  = '';
if ($userRole !== 'super_admin' && $companyId > 0) {
    $branchSql   .= ' AND company_id = ?';
    $branchTypes .= 'i';
    $branchParams[] = $companyId;
}
if (!$canSwitchBranch && $branchId > 0) {
    $branchSql   .= ' AND id = ?';
    $branchTypes .= 'i';
    $branchParams[] = $branchId;
}
$branchSql .= ' ORDER BY branch_name ASC';
$brStmt = $conn->prepare($branchSql);
if ($branchTypes !== '') $brStmt->bind_param($branchTypes, ...$branchParams);
$brStmt->execute();
$brResult = $brStmt->get_result();
$branches = $brResult->fetch_all(MYSQLI_ASSOC);
$brResult->free();
$brStmt->close();

// Notification count (near-expiry + expired, scoped to user)
$notifSql    = "SELECT COUNT(*) AS total FROM products WHERE status IN ('near_expiry','expired') AND is_removed = 0";
$notifParams = [];
$notifTypes  = '';
if ($companyId > 0) { $notifSql .= ' AND company_id = ?'; $notifTypes .= 'i'; $notifParams[] = $companyId; }
if (!$canSwitchBranch && $branchId > 0) { $notifSql .= ' AND branch_id = ?'; $notifTypes .= 'i'; $notifParams[] = $branchId; }
$nStmt = $conn->prepare($notifSql);
if ($notifTypes !== '') $nStmt->bind_param($notifTypes, ...$notifParams);
$nStmt->execute();
$nResult    = $nStmt->get_result();
$notifCount = (int)$nResult->fetch_assoc()['total'];
$nResult->free();
$nStmt->close();

// Nav items (role-aware visibility)
$navItems = [
    ['file' => 'dashboard.php',     'label' => 'Dashboard',        'icon' => 'bi-speedometer2',  'roles' => []],
    ['file' => 'products.php',      'label' => 'Products',         'icon' => 'bi-box-seam',       'roles' => []],
    ['file' => 'notifications.php', 'label' => 'Alerts',           'icon' => 'bi-bell',           'roles' => [], 'badge' => $notifCount],
    ['file' => 'removed.php',       'label' => 'Removed',         'icon' => 'bi-trash3',        'roles' => []],
    ['file' => 'category_rules.php','label' => 'Category Rules',  'icon' => 'bi-tags',          'roles' => ['super_admin','company_admin','branch_manager']],
    ['file' => 'analytics.php',     'label' => 'Analytics',       'icon' => 'bi-bar-chart-line','roles' => []],
    ['file' => 'catalog.php',       'label' => 'Product Catalog', 'icon' => 'bi-upc-scan',      'roles' => ['super_admin','company_admin','branch_manager']],
    ['file' => 'import.php',        'label' => 'Bulk Import',     'icon' => 'bi-cloud-upload',  'roles' => ['super_admin','company_admin','branch_manager']],
    ['file' => 'users.php',         'label' => 'Users',           'icon' => 'bi-people',        'roles' => ['super_admin','company_admin','branch_manager']],
];

// Branch query string helper
$bqs = ($selectedBranch !== 'all') ? '?branch=' . urlencode($selectedBranch) : '';

// Role label helper
$roleLabels = [
    'super_admin'    => 'Owner',
    'company_admin'  => 'Company Admin',
    'branch_manager' => 'Branch Manager',
    'employee'       => 'Employee',
    'viewer'         => 'Viewer',
];
$roleLabelDisplay = $roleLabels[$userRole] ?? ucfirst($userRole);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ExpiryGuard Pro</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.4/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" rel="preload" as="script">
<link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<div id="toast-container"></div>

<div class="admin-shell">

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">

  <div class="brand">
    <div class="brand-logo"><i class="bi bi-shield-lock-fill"></i></div>
    <div>
      <div class="brand-name">ExpiryGuard</div>
      <div class="brand-sub">Pro · <?= htmlspecialchars($roleLabelDisplay) ?></div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>
    <?php foreach ($navItems as $item):
        if (!empty($item['roles']) && !in_array($userRole, $item['roles'], true)) continue;
        $href    = $item['file'] . $bqs;
        $isActive = $currentPage === $item['file'];
        $badge   = $item['badge'] ?? 0;
    ?>
    <a href="<?= $href ?>" class="sidebar-link <?= $isActive ? 'active' : '' ?>">
      <i class="bi <?= $item['icon'] ?>"></i>
      <span><?= $item['label'] ?></span>
      <?php if ($badge > 0): ?>
        <span class="nav-badge"><?= $badge > 99 ? '99+' : $badge ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="avatar"><?= $userInitial ?></div>
      <div>
        <div class="u-name"><?= $userName ?></div>
        <div class="u-role"><?= htmlspecialchars($roleLabelDisplay) ?></div>
      </div>
    </div>
    <a href="../logout.php" class="btn-signout">
      <i class="bi bi-box-arrow-right"></i> Sign out
    </a>
  </div>

</aside><!-- /sidebar -->

<!-- Main -->
<div class="page-content">

  <!-- Topbar -->
  <header class="topbar">
    <div class="topbar-left">
      <!-- Mobile hamburger -->
      <button class="topbar-btn d-lg-none me-2" onclick="document.getElementById('sidebar').classList.toggle('open')">
        <i class="bi bi-list" style="font-size:1.1rem"></i>
      </button>
      <div>
        <h1 class="topbar-title mb-0"><?= htmlspecialchars('ExpiryGuard Pro') ?></h1>
        <p class="topbar-sub mb-0">Expiry tracking &amp; inventory management</p>
      </div>
    </div>

    <div class="topbar-right">
      <!-- Branch selector (only for users who can switch) -->
      <?php if (!empty($branches)): ?>
      <form method="GET" id="branchForm">
        <?php foreach ($_GET as $k => $v):
            if ($k === 'branch' || is_array($v)) continue; ?>
        <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
        <?php endforeach; ?>
        <select name="branch" class="branch-select"
          onchange="document.getElementById('branchForm').submit()"
          <?= !$canSwitchBranch ? 'disabled' : '' ?>>
          <?php if ($canSwitchBranch): ?>
            <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
          <?php endif; ?>
          <?php foreach ($branches as $br): ?>
            <option value="<?= (int)$br['id'] ?>"
              <?= (string)$selectedBranch === (string)$br['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($br['branch_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>

      <!-- Notification bell -->
      <a href="notifications.php<?= $bqs ?>" class="topbar-btn">
        <i class="bi bi-bell" style="font-size:.95rem"></i>
        <?php if ($notifCount > 0): ?><span class="notif-dot"></span><?php endif; ?>
      </a>

      <!-- User chip -->
      <div class="topbar-chip">
        <div class="avatar"><?= $userInitial ?></div>
        <div>
          <div class="chip-name"><?= $userName ?></div>
          <div class="chip-role"><?= htmlspecialchars($roleLabelDisplay) ?></div>
        </div>
      </div>
    </div>
  </header>

  <div class="page-body">
<?php
// Set branch filter variables used by dashboard, products, notifications, removed pages
$branchColumn         = 'branch_id';
$branchFilterSql      = '';
$branchFilterSqlAlias = '';
$branchFilterValue    = null;
if ($selectedBranch !== 'all') {
    $branchFilterSql      = ' AND `branch_id` = ?';
    $branchFilterSqlAlias = ' AND p.`branch_id` = ?';
    $branchFilterValue    = $selectedBranch;
}