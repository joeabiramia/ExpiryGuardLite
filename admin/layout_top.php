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
$userName     = htmlspecialchars($_SESSION['full_name'] ?? 'User', ENT_QUOTES, 'UTF-8');
$userInitial  = strtoupper(substr(strip_tags($userName), 0, 1));
$companyId    = (int)($_SESSION['company_id'] ?? 0);
$branchId     = (int)($_SESSION['branch_id']  ?? 0);

// Branch selector
// Admins can switch branch.
// Branch managers, employees, viewers are locked to their own branch.
$canSwitchBranch = in_array($userRole, ['super_admin', 'company_admin'], true);
$selectedBranch  = $canSwitchBranch
    ? trim((string)($_GET['branch'] ?? 'all'))
    : ($branchId > 0 ? (string)$branchId : 'all');

// Load branches — session-cached for 10 minutes
$_branchCacheKey = "branches_cache_{$companyId}_{$branchId}_{$userRole}";
$_branchCacheTs  = (int)($_SESSION['branches_cache_ts'] ?? 0);

if (!isset($_SESSION[$_branchCacheKey]) || (time() - $_branchCacheTs) > 600) {
    $branchSql    = "SELECT id, branch_name FROM branches WHERE is_active = 1";
    $branchParams = [];
    $branchTypes  = '';

    if ($userRole !== 'super_admin' && $companyId > 0) {
        $branchSql .= ' AND company_id = ?';
        $branchTypes .= 'i';
        $branchParams[] = $companyId;
    }

    if (!$canSwitchBranch && $branchId > 0) {
        $branchSql .= ' AND id = ?';
        $branchTypes .= 'i';
        $branchParams[] = $branchId;
    }

    $branchSql .= ' ORDER BY branch_name ASC';

    $brStmt = $conn->prepare($branchSql);
    if ($branchTypes !== '') {
        $brStmt->bind_param($branchTypes, ...$branchParams);
    }

    $brStmt->execute();
    $brResult = $brStmt->get_result();

    $_SESSION[$_branchCacheKey] = $brResult->fetch_all(MYSQLI_ASSOC);
    $_SESSION['branches_cache_ts'] = time();

    $brResult->free();
    $brStmt->close();
}

$branches = $_SESSION[$_branchCacheKey] ?? [];

// Notification count — session-cached for 2 minutes
$_notifCacheKey = "notif_count_cache_{$companyId}_{$branchId}_{$userRole}";
$_notifCacheTsKey = "notif_count_ts_{$companyId}_{$branchId}_{$userRole}";
$_notifCacheTs = (int)($_SESSION[$_notifCacheTsKey] ?? 0);

if (!isset($_SESSION[$_notifCacheKey]) || (time() - $_notifCacheTs) > 120) {
    $notifSql = "
        SELECT COUNT(*) AS total
        FROM products
        WHERE status IN ('near_expiry', 'expired')
          AND is_removed = 0
    ";

    $notifParams = [];
    $notifTypes  = '';

    if ($companyId > 0) {
        $notifSql .= ' AND company_id = ?';
        $notifTypes .= 'i';
        $notifParams[] = $companyId;
    }

    if (!$canSwitchBranch && $branchId > 0) {
        $notifSql .= ' AND branch_id = ?';
        $notifTypes .= 'i';
        $notifParams[] = $branchId;
    }

    $nStmt = $conn->prepare($notifSql);
    if ($notifTypes !== '') {
        $nStmt->bind_param($notifTypes, ...$notifParams);
    }

    $nStmt->execute();
    $nResult = $nStmt->get_result();

    $_SESSION[$_notifCacheKey] = (int)($nResult->fetch_assoc()['total'] ?? 0);
    $_SESSION[$_notifCacheTsKey] = time();

    $nResult->free();
    $nStmt->close();
}

$notifCount = (int)($_SESSION[$_notifCacheKey] ?? 0);

// Role checks
$_nuid       = (int)($_SESSION['user_id'] ?? 0);
$_isManager  = in_array($userRole, ['super_admin', 'company_admin', 'branch_manager'], true);
$_isViewer   = $userRole === 'viewer';
$_isEmployee = $userRole === 'employee';

/*
|--------------------------------------------------------------------------
| Sidebar visibility rules
|--------------------------------------------------------------------------
| Viewer:
| - Dashboard
| - Products
| - Alerts
| - Removed
| - Category Rules
| - Analytics
|
| Employee:
| - Products
| - Alerts
| - Removed
|
| Managers:
| - All current admin pages
|--------------------------------------------------------------------------
*/

$_viewerPages = [
    'dashboard.php',
    'products.php',
    'notifications.php',
    'removed.php',
    'category_rules.php',
    'analytics.php',
];

$_employeePages = [
    'products.php',
    'notifications.php',
    'removed.php',
];

function egCanShowNavItem(
    string $file,
    string $userRole,
    bool $isManager,
    bool $isViewer,
    bool $isEmployee,
    array $viewerPages,
    array $employeePages,
    mysqli $conn,
    int $userId
): bool {
    // Managers see all current admin pages
    if ($isManager) {
        return true;
    }

    // Viewers see the read-only overview/info pages
    if ($isViewer) {
        return in_array($file, $viewerPages, true);
    }

    // Employees see only operational pages
    if ($isEmployee) {
        return in_array($file, $employeePages, true);
    }

    // Fallback for any future/custom roles
    return match ($file) {
        'dashboard.php'      => userHasPermission($conn, $userId, 'view_dashboard'),
        'products.php'       => userHasPermission($conn, $userId, 'view_products') || userHasPermission($conn, $userId, 'manage_products'),
        'notifications.php'  => userHasPermission($conn, $userId, 'view_notifications'),
        'removed.php'        => userHasPermission($conn, $userId, 'view_products'),
        'category_rules.php' => userHasPermission($conn, $userId, 'view_categories') || userHasPermission($conn, $userId, 'manage_categories'),
        'analytics.php'      => userHasPermission($conn, $userId, 'view_reports'),
        'catalog.php'        => userHasPermission($conn, $userId, 'view_products') || userHasPermission($conn, $userId, 'manage_products'),
        'import.php'         => userHasPermission($conn, $userId, 'manage_products'),
        'users.php'          => userHasPermission($conn, $userId, 'view_users') || userHasPermission($conn, $userId, 'manage_users'),
        default              => false,
    };
}

// All possible sidebar items
$allNavItems = [
    [
        'file'  => 'dashboard.php',
        'label' => 'Dashboard',
        'icon'  => 'bi-speedometer2',
    ],
    [
        'file'  => 'products.php',
        'label' => 'Products',
        'icon'  => 'bi-box-seam',
    ],
    [
        'file'  => 'notifications.php',
        'label' => 'Alerts',
        'icon'  => 'bi-bell',
        'badge' => $notifCount,
    ],
    [
        'file'  => 'removed.php',
        'label' => 'Removed',
        'icon'  => 'bi-trash3',
    ],
    [
        'file'  => 'category_rules.php',
        'label' => 'Category Rules',
        'icon'  => 'bi-tags',
    ],
    [
        'file'  => 'analytics.php',
        'label' => 'Analytics',
        'icon'  => 'bi-bar-chart-line',
    ],
    [
        'file'  => 'catalog.php',
        'label' => 'Product Catalog',
        'icon'  => 'bi-upc-scan',
    ],
    [
        'file'  => 'import.php',
        'label' => 'Bulk Import',
        'icon'  => 'bi-cloud-upload',
    ],
    [
        'file'  => 'users.php',
        'label' => 'Users',
        'icon'  => 'bi-people',
    ],
];

// Filter sidebar items based on role
$navItems = array_values(array_filter($allNavItems, function (array $item) use (
    $userRole,
    $_isManager,
    $_isViewer,
    $_isEmployee,
    $_viewerPages,
    $_employeePages,
    $conn,
    $_nuid
): bool {
    return egCanShowNavItem(
        $item['file'],
        $userRole,
        $_isManager,
        $_isViewer,
        $_isEmployee,
        $_viewerPages,
        $_employeePages,
        $conn,
        $_nuid
    );
}));

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
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
      <div class="brand-sub">Pro · <?= htmlspecialchars($roleLabelDisplay, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>

    <?php foreach ($navItems as $item): ?>
      <?php
        $href     = $item['file'] . $bqs;
        $isActive = $currentPage === $item['file'];
        $badge    = (int)($item['badge'] ?? 0);
      ?>

      <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" class="sidebar-link <?= $isActive ? 'active' : '' ?>">
        <i class="bi <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
        <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>

        <?php if ($badge > 0): ?>
          <span class="nav-badge"><?= $badge > 99 ? '99+' : $badge ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="avatar"><?= htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8') ?></div>
      <div>
        <div class="u-name"><?= $userName ?></div>
        <div class="u-role"><?= htmlspecialchars($roleLabelDisplay, ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>

    <a href="../logout.php" class="btn-signout">
      <i class="bi bi-box-arrow-right"></i> Sign out
    </a>
  </div>

</aside>
<!-- /Sidebar -->

<!-- Main -->
<div class="page-content">

  <!-- Topbar -->
  <header class="topbar">
    <div class="topbar-left">
      <button class="topbar-btn d-lg-none me-2" onclick="document.getElementById('sidebar').classList.toggle('open')">
        <i class="bi bi-list" style="font-size:1.1rem"></i>
      </button>

      <div>
        <h1 class="topbar-title mb-0"><?= htmlspecialchars('ExpiryGuard Pro', ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="topbar-sub mb-0">Expiry tracking &amp; inventory management</p>
      </div>
    </div>

    <div class="topbar-right">

      <?php if (!empty($branches)): ?>
        <form method="GET" id="branchForm">
          <?php foreach ($_GET as $k => $v): ?>
            <?php if ($k === 'branch' || is_array($v)) continue; ?>
            <input type="hidden" name="<?= htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') ?>">
          <?php endforeach; ?>

          <select
            name="branch"
            class="branch-select"
            onchange="document.getElementById('branchForm').submit()"
            <?= !$canSwitchBranch ? 'disabled' : '' ?>
          >
            <?php if ($canSwitchBranch): ?>
              <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
            <?php endif; ?>

            <?php foreach ($branches as $br): ?>
              <option
                value="<?= (int)$br['id'] ?>"
                <?= (string)$selectedBranch === (string)$br['id'] ? 'selected' : '' ?>
              >
                <?= htmlspecialchars($br['branch_name'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>

      <a href="notifications.php<?= htmlspecialchars($bqs, ENT_QUOTES, 'UTF-8') ?>" class="topbar-btn">
        <i class="bi bi-bell" style="font-size:.95rem"></i>
        <?php if ($notifCount > 0): ?>
          <span class="notif-dot"></span>
        <?php endif; ?>
      </a>

      <div class="topbar-chip">
        <div class="avatar"><?= htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8') ?></div>
        <div>
          <div class="chip-name"><?= $userName ?></div>
          <div class="chip-role"><?= htmlspecialchars($roleLabelDisplay, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      </div>

    </div>
  </header>

  <div class="page-body">
<?php
// Branch filter variables used by dashboard, products, notifications, removed pages
$branchColumn         = 'branch_id';
$branchFilterSql      = '';
$branchFilterSqlAlias = '';
$branchFilterValue    = null;

if ($selectedBranch !== 'all') {
    $branchFilterSql      = ' AND `branch_id` = ?';
    $branchFilterSqlAlias = ' AND p.`branch_id` = ?';
    $branchFilterValue    = (int)$selectedBranch;
}