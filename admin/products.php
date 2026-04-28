<?php
include 'layout_top.php';

$myRole      = $_SESSION['role']      ?? 'viewer';
$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id']  ?? 0);

$search       = trim($_GET['q']         ?? '');
$statusFilter = trim($_GET['status']    ?? '');
$catFilter    = trim($_GET['category']  ?? '');
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to']   ?? '');

$catRes     = $conn->query("SELECT category_name FROM category_rules ORDER BY category_name ASC");
$categories = $catRes->fetch_all(MYSQLI_ASSOC);

$sql = "SELECT p.*, u.full_name AS entered_by_name, b.branch_name
        FROM products p
        LEFT JOIN users    u ON p.entered_by = u.id
        LEFT JOIN branches b ON p.branch_id  = b.id
        WHERE p.company_id = $myCompanyId
          AND p.is_removed = 0
          AND p.status != 'removed'";

if (!in_array($myRole, ['super_admin','company_admin'], true) && $myBranchId > 0)
    $sql .= " AND p.branch_id = $myBranchId";
if ($branchFilterValue !== null) $sql .= $branchFilterSqlAlias;
if ($statusFilter !== '') $sql .= " AND p.status = '"     . $conn->real_escape_string($statusFilter) . "'";
if ($catFilter    !== '') $sql .= " AND p.category = '"   . $conn->real_escape_string($catFilter)    . "'";
if ($dateFrom     !== '') $sql .= " AND p.expiry_date >= '".$conn->real_escape_string($dateFrom)     . "'";
if ($dateTo       !== '') $sql .= " AND p.expiry_date <= '".$conn->real_escape_string($dateTo)       . "'";
if ($search       !== '') $sql .= " AND (p.product_name LIKE '%".$conn->real_escape_string($search)."%'
                                    OR p.barcode LIKE '%"       .$conn->real_escape_string($search)."%'
                                    OR p.category LIKE '%"      .$conn->real_escape_string($search)."%')";
$sql .= " ORDER BY p.expiry_date ASC";

if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($sql); $stmt->bind_param('s', $branchFilterValue); $stmt->execute();
    $res = $stmt->get_result(); $products = $res->fetch_all(MYSQLI_ASSOC); $res->free(); $stmt->close();
} else {
    $res = $conn->query($sql); $products = $res->fetch_all(MYSQLI_ASSOC); $res->free();
}

$statusBadge = ['active'=>'badge-active','near_expiry'=>'badge-near','expired'=>'badge-expired'];
$statusLabel = ['active'=>'Active','near_expiry'=>'Near Expiry','expired'=>'Expired'];
$hasFilters  = $search || $statusFilter || $catFilter || $dateFrom || $dateTo;
?>

<div class="page-header">
  <div class="page-header-text">
    <h1>Products</h1>
    <p>Active inventory — sorted by nearest expiry</p>
  </div>
  <a href="export_csv.php?<?= http_build_query(['q'=>$search,'status'=>$statusFilter,'branch'=>$selectedBranch]) ?>" class="btn-eg btn-ghost-eg btn-sm-eg">
    <i class="bi bi-download"></i> Export
  </a>
</div>

<!-- Filters -->
<div class="eg-card" style="margin-bottom:16px">
  <div class="eg-card-body" style="padding:14px 16px">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <?php if ($branchFilterValue): ?><input type="hidden" name="branch" value="<?= htmlspecialchars($selectedBranch) ?>"><?php endif; ?>
      <div style="flex:1;min-width:180px">
        <div class="search-wrap"><i class="bi bi-search"></i>
          <input type="text" name="q" placeholder="Search product, barcode…" value="<?= htmlspecialchars($search) ?>">
        </div>
      </div>
      <div>
        <label class="eg-label">Status</label>
        <select name="status" class="branch-select">
          <option value="">All</option>
          <option value="active"      <?= $statusFilter==='active'      ?'selected':'' ?>>Active</option>
          <option value="near_expiry" <?= $statusFilter==='near_expiry' ?'selected':'' ?>>Near Expiry</option>
          <option value="expired"     <?= $statusFilter==='expired'     ?'selected':'' ?>>Expired</option>
        </select>
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
      <div><label class="eg-label">Expiry From</label><input type="date" name="date_from" class="branch-select" value="<?= htmlspecialchars($dateFrom) ?>"></div>
      <div><label class="eg-label">Expiry To</label><input type="date" name="date_to" class="branch-select" value="<?= htmlspecialchars($dateTo) ?>"></div>
      <button type="submit" class="btn-eg btn-primary-eg btn-sm-eg"><i class="bi bi-funnel"></i> Filter</button>
      <?php if ($hasFilters): ?><a href="products.php<?= $branchFilterValue?'?branch='.urlencode($selectedBranch):'' ?>" class="btn-eg btn-ghost-eg btn-sm-eg">Clear</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="eg-card">
  <div class="eg-card-header">
    <span class="eg-card-title"><i class="bi bi-box-seam me-2"></i>Products (<?= count($products) ?>)</span>
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
          <th>Stock Value</th>
          <th>Status</th>
          <th>Branch</th>
          <th>Entered By</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($products)): ?>
        <tr><td colspan="10"><div class="empty-state"><i class="bi bi-box-seam"></i><p>No products found.</p></div></td></tr>
      <?php endif; ?>
      <?php foreach ($products as $p):
        $stockValue = ($p['unit_price'] !== null) ? $p['unit_price'] * $p['quantity'] : null;
      ?>
        <tr id="row_<?= (int)$p['id'] ?>">
          <td>
            <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($p['product_name']) ?></div>
            <div style="font-size:.73rem;color:var(--text-muted)"><?= htmlspecialchars($p['barcode']) ?></div>
          </td>
          <td style="font-size:.82rem"><?= htmlspecialchars($p['category'] ?? '—') ?></td>
          <td style="font-size:.82rem"><?= date('M j, Y', strtotime($p['expiry_date'])) ?></td>
          <td style="font-size:.82rem"><?= (int)$p['quantity'] ?></td>
          <td style="font-size:.82rem"><?= $p['unit_price'] !== null ? '$'.number_format($p['unit_price'],2) : '<span style="color:var(--text-muted)">—</span>' ?></td>
          <td style="font-size:.82rem;font-weight:600"><?= $stockValue !== null ? '$'.number_format($stockValue,2) : '<span style="color:var(--text-muted)">—</span>' ?></td>
          <td><span class="badge-eg <?= $statusBadge[$p['status']] ?? 'badge-removed' ?>"><?= $statusLabel[$p['status']] ?? $p['status'] ?></span></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($p['branch_name'] ?? '—') ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($p['entered_by_name'] ?? '—') ?></td>
          <td>
            <?php if (!in_array($myRole, ['viewer'], true)): ?>
            <button class="btn-eg btn-ghost-eg btn-xs-eg" style="color:var(--red)"
              onclick="removeProduct(<?= (int)$p['id'] ?>, '<?= htmlspecialchars($p['product_name'],ENT_QUOTES) ?>')">
              <i class="bi bi-trash3"></i> Remove
            </button>
            <?php else: ?>
            <span style="font-size:.74rem;color:var(--text-muted)">—</span>
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
  const fd = new FormData(); fd.append('product_id', id);
  const res  = await fetch('../api/mark_removed.php', { method: 'POST', body: fd });
  const json = await res.json();
  showToast(json.success ? 'Product removed' : (json.message || 'Failed'), json.success ? 'ok' : 'err');
  if (json.success) setTimeout(() => location.reload(), 700);
}
</script>

<?php include 'layout_bottom.php'; ?>
