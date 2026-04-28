<?php
include 'layout_top.php';

$myRole      = $_SESSION['role']      ?? 'viewer';
$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id']  ?? 0);

$search    = trim($_GET['q']         ?? '');
$catFilter = trim($_GET['category']  ?? '');
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to']   ?? '');

$catRes     = $conn->query("SELECT category_name FROM category_rules ORDER BY category_name ASC");
$categories = $catRes->fetch_all(MYSQLI_ASSOC);

$sql = "SELECT p.*, u.full_name AS entered_by_name, ru.full_name AS removed_by_name, b.branch_name
        FROM products p
        LEFT JOIN users    u  ON p.entered_by = u.id
        LEFT JOIN users    ru ON p.removed_by = ru.id
        LEFT JOIN branches b  ON p.branch_id  = b.id
        WHERE (p.status = 'removed' OR p.is_removed = 1)
          AND p.company_id = $myCompanyId";

if (!in_array($myRole, ['super_admin','company_admin'], true) && $myBranchId > 0)
    $sql .= " AND p.branch_id = $myBranchId";
if ($branchFilterValue !== null) $sql .= $branchFilterSqlAlias;
if ($catFilter !== '') $sql .= " AND p.category = '"    . $conn->real_escape_string($catFilter) . "'";
if ($dateFrom  !== '') $sql .= " AND p.removed_on >= '" . $conn->real_escape_string($dateFrom)  . "'";
if ($dateTo    !== '') $sql .= " AND p.removed_on <= '" . $conn->real_escape_string($dateTo)    . " 23:59:59'";
if ($search    !== '') $sql .= " AND (p.product_name LIKE '%".$conn->real_escape_string($search)."%'
                                  OR p.barcode LIKE '%"      .$conn->real_escape_string($search)."%'
                                  OR ru.full_name LIKE '%"   .$conn->real_escape_string($search)."%')";
$sql .= " ORDER BY p.removed_on DESC";

if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($sql); $stmt->bind_param('s', $branchFilterValue); $stmt->execute();
    $res = $stmt->get_result(); $items = $res->fetch_all(MYSQLI_ASSOC); $res->free(); $stmt->close();
} else {
    $res = $conn->query($sql); $items = $res->fetch_all(MYSQLI_ASSOC); $res->free();
}

// Calculate total waste value
$totalWaste     = 0;
$itemsWithPrice = 0;
foreach ($items as $it) {
    if ($it['unit_price'] !== null) {
        $totalWaste     += $it['unit_price'] * $it['quantity'];
        $itemsWithPrice++;
    }
}

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
    <div class="kpi-value"><?= count($items) ?></div>
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
    <span class="eg-card-title"><i class="bi bi-archive me-2"></i>Removed (<?= count($items) ?>)</span>
    <?php if ($totalWaste > 0): ?>
    <span style="font-size:.82rem;font-weight:700;color:var(--red)">
      <i class="bi bi-exclamation-triangle me-1"></i>Total waste: $<?= number_format($totalWaste,2) ?>
    </span>
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
</div>

<?php include 'layout_bottom.php'; ?>
