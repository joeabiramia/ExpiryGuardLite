<?php
include 'layout_top.php';

$myRole      = $_SESSION['role']      ?? 'viewer';
$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id']  ?? 0);

// Filters
$search       = trim($_GET['q']         ?? '');
$statusFilter = trim($_GET['status']    ?? '');
$catFilter    = trim($_GET['category']  ?? '');
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to']   ?? '');

// Categories for dropdown
$catRes     = $conn->query("SELECT category_name FROM category_rules ORDER BY category_name ASC");
$categories = $catRes->fetch_all(MYSQLI_ASSOC);

$sql = "SELECT p.*, u.full_name AS entered_by_name, b.branch_name
        FROM products p
        LEFT JOIN users    u ON p.entered_by = u.id
        LEFT JOIN branches b ON p.branch_id  = b.id
        WHERE p.status IN ('near_expiry','expired')
          AND p.is_removed = 0
          AND p.company_id = $myCompanyId";

if (!in_array($myRole, ['super_admin','company_admin'], true) && $myBranchId > 0) {
    $sql .= " AND p.branch_id = $myBranchId";
}
if ($branchFilterValue !== null) $sql .= $branchFilterSqlAlias;
if ($statusFilter !== '') $sql .= " AND p.status = '"   . $conn->real_escape_string($statusFilter) . "'";
if ($catFilter    !== '') $sql .= " AND p.category = '" . $conn->real_escape_string($catFilter)    . "'";
if ($dateFrom     !== '') $sql .= " AND p.expiry_date >= '" . $conn->real_escape_string($dateFrom) . "'";
if ($dateTo       !== '') $sql .= " AND p.expiry_date <= '" . $conn->real_escape_string($dateTo)   . "'";
if ($search       !== '') $sql .= " AND (p.product_name LIKE '%" . $conn->real_escape_string($search) . "%'
                                    OR p.barcode LIKE '%"        . $conn->real_escape_string($search) . "%')";
$sql .= " ORDER BY p.expiry_date ASC";

if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($sql); $stmt->bind_param('s', $branchFilterValue); $stmt->execute();
    $res  = $stmt->get_result(); $rows = $res->fetch_all(MYSQLI_ASSOC); $res->free(); $stmt->close();
} else {
    $res  = $conn->query($sql); $rows = $res->fetch_all(MYSQLI_ASSOC); $res->free();
}

$hasFilters = $search || $statusFilter || $catFilter || $dateFrom || $dateTo;
?>

<div class="page-header">
  <div class="page-header-text">
    <h1>Expiry Alerts</h1>
    <p>Products near expiry or already expired — requires action</p>
  </div>
  <button class="btn-eg btn-ghost-eg btn-sm-eg" onclick="location.reload()">
    <i class="bi bi-arrow-clockwise"></i> Refresh
  </button>
</div>

<!-- Filters -->
<div class="eg-card" style="margin-bottom:16px">
  <div class="eg-card-body" style="padding:14px 16px">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <?php if ($branchFilterValue): ?><input type="hidden" name="branch" value="<?= htmlspecialchars($selectedBranch) ?>"><?php endif; ?>

      <div style="flex:1;min-width:180px">
        <div class="search-wrap">
          <i class="bi bi-search"></i>
          <input type="text" name="q" placeholder="Search product, barcode…" value="<?= htmlspecialchars($search) ?>">
        </div>
      </div>

      <div>
        <label class="eg-label">Status</label>
        <select name="status" class="branch-select">
          <option value="">All Alerts</option>
          <option value="near_expiry" <?= $statusFilter==='near_expiry'?'selected':'' ?>>Near Expiry</option>
          <option value="expired"     <?= $statusFilter==='expired'    ?'selected':'' ?>>Expired</option>
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

      <div>
        <label class="eg-label">Expiry From</label>
        <input type="date" name="date_from" class="branch-select" value="<?= htmlspecialchars($dateFrom) ?>">
      </div>

      <div>
        <label class="eg-label">Expiry To</label>
        <input type="date" name="date_to" class="branch-select" value="<?= htmlspecialchars($dateTo) ?>">
      </div>

      <button type="submit" class="btn-eg btn-primary-eg btn-sm-eg"><i class="bi bi-funnel"></i> Filter</button>
      <?php if ($hasFilters): ?>
      <a href="notifications.php<?= $branchFilterValue ? '?branch='.urlencode($selectedBranch) : '' ?>" class="btn-eg btn-ghost-eg btn-sm-eg">Clear</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="eg-card">
  <div class="eg-card-header">
    <span class="eg-card-title"><i class="bi bi-bell me-2"></i>Alerts (<?= count($rows) ?>)</span>
  </div>

  <div class="eg-table-wrap">
    <table class="eg-table">
      <thead>
        <tr>
          <th>Product</th>
          <th>Barcode</th>
          <th>Category</th>
          <th>Expiry Date</th>
          <th>Days Left</th>
          <th>Status</th>
          <th>Branch</th>
          <th>Entered By</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="9"><div class="empty-state"><i class="bi bi-check-circle"></i><p>All clear! No alerts at this time.</p></div></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $item):
        $daysLeft    = (int)((strtotime($item['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
        $daysDisplay = $daysLeft < 0 ? abs($daysLeft).'d ago' : ($daysLeft === 0 ? 'Today' : $daysLeft.'d left');
        $daysColor   = $daysLeft < 0 ? 'var(--red)' : ($daysLeft <= 3 ? 'var(--yellow)' : 'var(--text-muted)');
      ?>
        <tr>
          <td><div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($item['product_name']) ?></div></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($item['barcode']) ?></td>
          <td style="font-size:.8rem"><?= htmlspecialchars($item['category'] ?? '—') ?></td>
          <td style="font-size:.82rem"><?= date('M j, Y', strtotime($item['expiry_date'])) ?></td>
          <td><span style="font-weight:700;font-size:.8rem;color:<?= $daysColor ?>"><?= $daysDisplay ?></span></td>
          <td>
            <span class="badge-eg <?= $item['status']==='expired'?'badge-expired':'badge-near' ?>">
              <?= $item['status']==='expired' ? 'Expired' : 'Near Expiry' ?>
            </span>
          </td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($item['branch_name'] ?? '—') ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($item['entered_by_name'] ?? '—') ?></td>
          <td>
            <?php if (!in_array($myRole, ['viewer'], true)): ?>
            <button class="btn-eg btn-danger-eg btn-xs-eg" onclick="removeProduct(<?= (int)$item['id'] ?>, '<?= htmlspecialchars($item['product_name'],ENT_QUOTES) ?>')">
              <i class="bi bi-trash3"></i> Remove
            </button>
            <?php else: ?>
            <span style="font-size:.74rem;color:var(--text-muted)">View only</span>
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
  if (!confirm(`Remove "${name}" from inventory?`)) return;
  const fd = new FormData();
  fd.append('product_id', id);
  const res  = await fetch('../api/mark_removed.php', { method: 'POST', body: fd });
  const json = await res.json();
  showToast(json.success ? 'Product removed' : (json.message || 'Failed'), json.success ? 'ok' : 'err');
  if (json.success) setTimeout(() => location.reload(), 700);
}

setTimeout(() => location.reload(), 60000);
</script>

<?php include 'layout_bottom.php'; ?>
