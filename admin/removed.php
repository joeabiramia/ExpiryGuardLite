<?php
include 'layout_top.php';
$userRole             ??= 'viewer';
$selectedBranch       ??= 'all';
$branchFilterValue    ??= null;
$branchFilterSqlAlias ??= '';

$myRole      = $_SESSION['role']      ?? 'viewer';
$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id']  ?? 0);

$search    = trim($_GET['q']         ?? '');
$catFilter = trim($_GET['category']  ?? '');
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to']   ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 25;
$offset    = ($page - 1) * $perPage;

// Categories — session-cached
if (!isset($_SESSION['category_rules_cache'])) {
    $catRes = $conn->query("SELECT category_name FROM category_rules ORDER BY category_name ASC");
    $_SESSION['category_rules_cache'] = $catRes ? $catRes->fetch_all(MYSQLI_ASSOC) : [];
}
$categories = $_SESSION['category_rules_cache'];

// Build parameterized WHERE clause
$whereSql = "WHERE (p.status = 'removed' OR p.is_removed = 1)";
$params = [];
$types  = '';

if ($myRole !== 'super_admin' && $myCompanyId > 0) {
    $whereSql .= " AND p.company_id = ?";
    $params[]  = $myCompanyId;
    $types    .= 'i';
}

if (!in_array($myRole, ['super_admin','company_admin'], true) && $myBranchId > 0) {
    $whereSql .= " AND p.branch_id = ?";
    $params[]  = $myBranchId;
    $types    .= 'i';
}
if ($branchFilterValue !== null) {
    $whereSql .= " AND p.branch_id = ?";
    $params[]  = (int)$branchFilterValue;
    $types    .= 'i';
}
if ($catFilter !== '') {
    $whereSql .= " AND p.category = ?";
    $params[]  = $catFilter;
    $types    .= 's';
}
if ($dateFrom !== '') {
    $whereSql .= " AND p.removed_on >= ?";
    $params[]  = $dateFrom;
    $types    .= 's';
}
if ($dateTo !== '') {
    $whereSql .= " AND p.removed_on <= ?";
    $params[]  = $dateTo . ' 23:59:59';
    $types    .= 's';
}
if ($search !== '') {
    $whereSql .= " AND (p.product_name LIKE ? OR p.barcode LIKE ? OR ru.full_name LIKE ?)";
    $like      = '%' . $search . '%';
    $params[]  = $like;
    $params[]  = $like;
    $params[]  = $like;
    $types    .= 'sss';
}

// Single aggregate query: total rows + waste totals (no LIMIT — covers all filtered results)
$aggStmt = $conn->prepare(
    "SELECT COUNT(*) AS total_rows,
            COALESCE(SUM(CASE WHEN p.unit_price IS NOT NULL THEN p.unit_price * p.quantity ELSE 0 END), 0) AS total_waste,
            SUM(p.unit_price IS NOT NULL) AS items_with_price
     FROM products p
     LEFT JOIN users ru ON p.removed_by = ru.id
     $whereSql"
);
if ($types !== '') {
    $aggStmt->bind_param($types, ...$params);
}
$aggStmt->execute();
$agg            = $aggStmt->get_result()->fetch_assoc();
$aggStmt->close();
$totalRows      = (int)$agg['total_rows'];
$totalWaste     = (float)$agg['total_waste'];
$itemsWithPrice = (int)$agg['items_with_price'];
$totalPages     = max(1, (int)ceil($totalRows / $perPage));

// Fetch current page with explicit columns
$pageParams = array_merge($params, [$perPage, $offset]);
$pageTypes  = $types . 'ii';
$dataStmt   = $conn->prepare(
    "SELECT p.id, p.product_name, p.barcode, p.category, p.expiry_date,
            p.quantity, p.unit_price, p.removed_on,
            u.full_name AS entered_by_name, ru.full_name AS removed_by_name, b.branch_name
     FROM products p
     LEFT JOIN users    u  ON p.entered_by = u.id
     LEFT JOIN users    ru ON p.removed_by = ru.id
     LEFT JOIN branches b  ON p.branch_id  = b.id
     $whereSql
     ORDER BY p.removed_on DESC LIMIT ? OFFSET ?"
);
if ($pageTypes !== '') {
    $dataStmt->bind_param($pageTypes, ...$pageParams);
}
$dataStmt->execute();
$res   = $dataStmt->get_result();
$items = $res->fetch_all(MYSQLI_ASSOC);
$res->free();
$dataStmt->close();

$hasFilters = $search || $catFilter || $dateFrom || $dateTo;
?>

<div class="page-header">
  <div class="page-header-text">
    <h1>Removed Products</h1>
    <p>Full archive with waste cost tracking</p>
  </div>
  <a href="export_csv.php?status=removed" class="btn-eg btn-ghost-eg btn-sm-eg">
    <i class="bi bi-download"></i> Export CSV
  </a>
</div>

<!-- Waste cost summary -->
<?php if ($totalWaste > 0): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:16px">
  <div class="kpi-card" style="--kpi-color:var(--red);--kpi-bg:var(--red-light)">
    <div class="kpi-icon"><i class="bi bi-currency-dollar"></i></div>
    <div class="kpi-value">$<?= number_format($totalWaste, 2) ?></div>
    <div class="kpi-label">Total Waste Value</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--yellow);--kpi-bg:var(--yellow-light)">
    <div class="kpi-icon"><i class="bi bi-box-seam"></i></div>
    <div class="kpi-value"><?= $totalRows ?></div>
    <div class="kpi-label">Items Removed</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--blue);--kpi-bg:var(--blue-light)">
    <div class="kpi-icon"><i class="bi bi-calculator"></i></div>
    <div class="kpi-value">$<?= $itemsWithPrice > 0 ? number_format($totalWaste / $itemsWithPrice, 2) : '0.00' ?></div>
    <div class="kpi-label">Avg Loss per Item</div>
  </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="eg-card" style="margin-bottom:16px">
  <div class="eg-card-body" style="padding:14px 16px">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <?php if ($branchFilterValue): ?><input type="hidden" name="branch" value="<?= htmlspecialchars($selectedBranch) ?>"><?php endif; ?>
      <div style="flex:1;min-width:180px">
        <div class="search-wrap"><i class="bi bi-search"></i>
          <input type="text" name="q" placeholder="Search product, barcode, removed by…" value="<?= htmlspecialchars($search) ?>">
        </div>
      </div>
      <div>
        <label class="eg-label">Category</label>
        <select name="category" class="branch-select">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?= htmlspecialchars($cat['category_name']) ?>" <?= $catFilter===$cat['category_name']?'selected':'' ?>>
            <?= htmlspecialchars($cat['category_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="eg-label">Removed From</label><input type="date" name="date_from" class="branch-select" value="<?= htmlspecialchars($dateFrom) ?>"></div>
      <div><label class="eg-label">Removed To</label><input type="date" name="date_to" class="branch-select" value="<?= htmlspecialchars($dateTo) ?>"></div>
      <button type="submit" class="btn-eg btn-primary-eg btn-sm-eg"><i class="bi bi-funnel"></i> Filter</button>
      <?php if ($hasFilters): ?><a href="removed.php<?= $branchFilterValue?'?branch='.urlencode($selectedBranch):'' ?>" class="btn-eg btn-ghost-eg btn-sm-eg">Clear</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="eg-card">
  <div class="eg-card-header">
    <span class="eg-card-title"><i class="bi bi-archive me-2"></i>Removed (<?= $totalRows ?>)</span>
    <?php if ($totalWaste > 0): ?>
    <span style="font-size:.82rem;font-weight:700;color:var(--red)">
      <i class="bi bi-exclamation-triangle me-1"></i>Total waste: $<?= number_format($totalWaste,2) ?>
    </span>
    <?php endif; ?>
    <?php if ($totalPages > 1): ?>
    <span style="font-size:.78rem;color:var(--text-muted)">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php endif; ?>
  </div>
  <div class="eg-table-wrap">
    <table class="eg-table">
      <thead>
        <tr>
          <th>Product</th>
          <th>Category</th>
          <th>Expiry Date</th>
          <th>Qty</th>
          <th>Unit Price</th>
          <th>Waste Value</th>
          <th>Branch</th>
          <th>Entered By</th>
          <th>Removed By</th>
          <th>Removed On</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($items)): ?>
        <tr><td colspan="10"><div class="empty-state"><i class="bi bi-archive"></i><p>No removed products.</p></div></td></tr>
      <?php endif; ?>
      <?php foreach ($items as $item):
        $wasteVal = ($item['unit_price'] !== null) ? $item['unit_price'] * $item['quantity'] : null;
      ?>
        <tr>
          <td>
            <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($item['product_name']) ?></div>
            <div style="font-size:.73rem;color:var(--text-muted)"><?= htmlspecialchars($item['barcode']) ?></div>
          </td>
          <td style="font-size:.82rem"><?= htmlspecialchars($item['category'] ?? '—') ?></td>
          <td style="font-size:.82rem"><?= date('M j, Y', strtotime($item['expiry_date'])) ?></td>
          <td style="font-size:.82rem"><?= (int)$item['quantity'] ?></td>
          <td style="font-size:.82rem"><?= $item['unit_price'] !== null ? '$'.number_format($item['unit_price'],2) : '<span style="color:var(--text-muted)">—</span>' ?></td>
          <td style="font-size:.82rem;font-weight:700;color:var(--red)">
            <?= $wasteVal !== null ? '$'.number_format($wasteVal,2) : '<span style="color:var(--text-muted);font-weight:400">—</span>' ?>
          </td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($item['branch_name'] ?? '—') ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($item['entered_by_name'] ?? '—') ?></td>
          <td>
            <?php if ($item['removed_by_name']): ?>
            <span style="font-size:.78rem;font-weight:600;color:var(--red)"><?= htmlspecialchars($item['removed_by_name']) ?></span>
            <?php else: ?>
            <span style="font-size:.78rem;color:var(--text-muted)">—</span>
            <?php endif; ?>
          </td>
          <td style="font-size:.78rem;color:var(--text-muted)">
            <?= $item['removed_on'] ? date('M j, Y H:i', strtotime($item['removed_on'])) : '—' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <?php
    $qp = $_GET;
    $makeUrl = function (int $p) use ($qp): string {
        $qp['page'] = $p;
        return 'removed.php?' . http_build_query($qp);
    };
    $visible = [1];
    for ($i = $page - 1; $i <= $page + 1; $i++) {
        if ($i > 1 && $i < $totalPages) $visible[] = $i;
    }
    $visible[] = $totalPages;
    $visible = array_values(array_unique($visible));
    sort($visible);
  ?>
  <div style="padding:14px 20px;display:flex;justify-content:center;align-items:center;gap:6px;border-top:1px solid var(--border);flex-wrap:wrap">
    <?php if ($page > 1): ?>
      <a href="<?= $makeUrl($page - 1) ?>" style="padding:6px 12px;border-radius:7px;font-size:.8rem;font-weight:600;text-decoration:none;background:var(--bg);color:var(--text-muted);border:1px solid var(--border)">Previous</a>
    <?php else: ?>
      <span style="padding:6px 12px;border-radius:7px;font-size:.8rem;font-weight:600;background:var(--bg);color:#aaa;border:1px solid var(--border);opacity:.6">Previous</span>
    <?php endif; ?>
    <?php $last = 0; foreach ($visible as $vp): ?>
      <?php if ($last > 0 && $vp > $last + 1): ?><span style="padding:6px 8px;color:var(--text-muted);font-weight:700">...</span><?php endif; ?>
      <?php $last = $vp; ?>
      <a href="<?= $makeUrl($vp) ?>" style="padding:6px 11px;border-radius:7px;font-size:.8rem;font-weight:600;text-decoration:none;background:<?= $vp===$page?'var(--green)':'var(--bg)'?>;color:<?= $vp===$page?'#fff':'var(--text-muted)'?>;border:1px solid <?= $vp===$page?'var(--green)':'var(--border)'?>"><?= $vp ?></a>
    <?php endforeach; ?>
    <?php if ($page < $totalPages): ?>
      <a href="<?= $makeUrl($page + 1) ?>" style="padding:6px 12px;border-radius:7px;font-size:.8rem;font-weight:600;text-decoration:none;background:var(--bg);color:var(--text-muted);border:1px solid var(--border)">Next</a>
    <?php else: ?>
      <span style="padding:6px 12px;border-radius:7px;font-size:.8rem;font-weight:600;background:var(--bg);color:#aaa;border:1px solid var(--border);opacity:.6">Next</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php include 'layout_bottom.php'; ?>
