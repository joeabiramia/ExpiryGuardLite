<?php
include 'layout_top.php';

$myRole      = $_SESSION['role']      ?? 'viewer';
$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id']  ?? 0);

$statusFilter = trim($_GET['status'] ?? '');
$search       = trim($_GET['q']      ?? '');

$sql    = "SELECT p.*, u.full_name AS entered_by_name, ru.full_name AS removed_by_name, b.branch_name
           FROM products p
           LEFT JOIN users    u  ON p.entered_by = u.id
           LEFT JOIN users    ru ON p.removed_by  = ru.id
           LEFT JOIN branches b  ON p.branch_id   = b.id
           WHERE p.company_id = $myCompanyId";

if (!in_array($myRole, ['super_admin','company_admin'], true) && $myBranchId > 0) {
    $sql .= " AND p.branch_id = $myBranchId";
}
if ($branchFilterValue !== null) $sql .= $branchFilterSqlAlias;
if ($statusFilter !== '') $sql .= " AND p.status = '" . $conn->real_escape_string($statusFilter) . "'";
if ($search !== '') $sql .= " AND (p.product_name LIKE '%" . $conn->real_escape_string($search) . "%' OR p.barcode LIKE '%" . $conn->real_escape_string($search) . "%' OR p.category LIKE '%" . $conn->real_escape_string($search) . "%')";
$sql .= " ORDER BY p.entered_on DESC";

if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($sql); $stmt->bind_param('s', $branchFilterValue); $stmt->execute();
    $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $products = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

$statusBadge = ['active'=>'badge-active','near_expiry'=>'badge-near','expired'=>'badge-expired','removed'=>'badge-removed'];
$statusLabel = ['active'=>'Active','near_expiry'=>'Near Expiry','expired'=>'Expired','removed'=>'Removed'];
?>

<div class="page-header">
  <div class="page-header-text">
    <h1>Products</h1>
    <p>Inventory across your branch<?= $myRole === 'branch_manager' ? '' : 'es' ?></p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="export_csv.php?<?= http_build_query(['q'=>$search,'status'=>$statusFilter,'branch'=>$selectedBranch]) ?>" class="btn-eg btn-ghost-eg btn-sm-eg">
      <i class="bi bi-download"></i> Export
    </a>
  </div>
</div>

<!-- Filters bar -->
<div class="eg-card" style="margin-bottom:16px">
  <div class="eg-card-body" style="padding:14px 16px">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <?php if ($branchFilterValue): ?><input type="hidden" name="branch" value="<?= htmlspecialchars($selectedBranch) ?>"><?php endif; ?>
      <div class="search-wrap" style="flex:1;min-width:200px">
        <i class="bi bi-search"></i>
        <input type="text" name="q" placeholder="Search product, barcode, category…" value="<?= htmlspecialchars($search) ?>">
      </div>
      <select name="status" class="branch-select">
        <option value="">All Statuses</option>
        <option value="active"      <?= $statusFilter==='active'       ? 'selected':'' ?>>Active</option>
        <option value="near_expiry" <?= $statusFilter==='near_expiry'   ? 'selected':'' ?>>Near Expiry</option>
        <option value="expired"     <?= $statusFilter==='expired'       ? 'selected':'' ?>>Expired</option>
        <option value="removed"     <?= $statusFilter==='removed'       ? 'selected':'' ?>>Removed</option>
      </select>
      <button type="submit" class="btn-eg btn-primary-eg btn-sm-eg"><i class="bi bi-funnel"></i> Filter</button>
      <?php if ($search || $statusFilter): ?>
      <a href="products.php<?= $branchFilterValue ? '?branch='.urlencode($selectedBranch) : '' ?>" class="btn-eg btn-ghost-eg btn-sm-eg">Clear</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="eg-card">
  <div class="eg-card-header">
    <span class="eg-card-title"><i class="bi bi-box-seam me-2"></i>Products (<?= count($products) ?>)</span>
  </div>

  <div class="eg-table-wrap">
    <table class="eg-table" id="productsTable">
      <thead>
        <tr>
          <th>Product</th>
          <th>Category</th>
          <th>Expiry Date</th>
          <th>Qty</th>
          <th>Status</th>
          <th>Branch</th>
          <th>Entered By</th>
          <th>Entered On</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="productsBody">
      <?php if (empty($products)): ?>
        <tr><td colspan="9"><div class="empty-state"><i class="bi bi-box-seam"></i><p>No products found.</p></div></td></tr>
      <?php endif; ?>
      <?php foreach ($products as $p): ?>
        <tr id="row_<?= (int)$p['id'] ?>">
          <td>
            <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($p['product_name']) ?></div>
            <div style="font-size:.73rem;color:var(--text-muted)"><?= htmlspecialchars($p['barcode']) ?></div>
          </td>
          <td style="font-size:.82rem"><?= htmlspecialchars($p['category'] ?? '—') ?></td>
          <td style="font-size:.82rem"><?= date('M j, Y', strtotime($p['expiry_date'])) ?></td>
          <td style="font-size:.82rem"><?= (int)$p['quantity'] ?></td>
          <td><span class="badge-eg <?= $statusBadge[$p['status']] ?? 'badge-removed' ?>"><?= $statusLabel[$p['status']] ?? $p['status'] ?></span></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($p['branch_name'] ?? '—') ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($p['entered_by_name'] ?? '—') ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= date('M j, Y', strtotime($p['entered_on'])) ?></td>
          <td>
            <?php if (!(int)$p['is_removed']): ?>
              <?php if (!in_array($myRole, ['viewer'], true)): ?>
              <button class="btn-eg btn-ghost-eg btn-xs-eg" style="color:var(--red)"
                onclick="removeProduct(<?= (int)$p['id'] ?>, '<?= htmlspecialchars($p['product_name'],ENT_QUOTES) ?>')">
                <i class="bi bi-trash3"></i> Remove
              </button>
              <?php else: ?>
              <span style="font-size:.74rem;color:var(--text-muted)">—</span>
              <?php endif; ?>
            <?php else: ?>
            <span style="font-size:.74rem;color:var(--text-muted)">Removed by <?= htmlspecialchars($p['removed_by_name'] ?? '—') ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
async function removeProduct(id, name) {
  if (!confirm(`Remove "${name}" from inventory? This cannot be undone.`)) return;
  const fd = new FormData();
  fd.append('product_id', id);
  const res  = await fetch('../api/mark_removed.php', { method: 'POST', body: fd });
  const json = await res.json();
  showToast(json.success ? 'Product removed' : (json.message || 'Failed'), json.success ? 'ok' : 'err');
  if (json.success) setTimeout(() => location.reload(), 700);
}

// Live refresh every 30s (no full reload — just updates status badges)
setInterval(async () => {
  try {
    const branch = "<?= htmlspecialchars($selectedBranch) ?>";
    const res  = await fetch(`../api/live_products.php?branch=${encodeURIComponent(branch)}`);
    const json = await res.json();
    if (!json.success) return;
    const badgeMap = { active:'badge-active', near_expiry:'badge-near', expired:'badge-expired', removed:'badge-removed' };
    const labelMap = { active:'Active', near_expiry:'Near Expiry', expired:'Expired', removed:'Removed' };
    json.data.forEach(p => {
      const row = document.getElementById(`row_${p.id}`);
      if (!row) return;
      const badge = row.querySelector('.badge-eg');
      if (badge) {
        badge.className = `badge-eg ${badgeMap[p.status] || 'badge-removed'}`;
        badge.textContent = labelMap[p.status] || p.status;
      }
    });
  } catch {}
}, 30000);
</script>

<?php include 'layout_bottom.php'; ?>