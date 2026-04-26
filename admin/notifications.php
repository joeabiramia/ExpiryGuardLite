<?php
include 'layout_top.php';

$myRole      = $_SESSION['role']      ?? 'viewer';
$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id']  ?? 0);

$sql    = "SELECT p.*, u.full_name AS entered_by_name, b.branch_name
           FROM products p
           LEFT JOIN users    u ON p.entered_by = u.id
           LEFT JOIN branches b ON p.branch_id  = b.id
           WHERE p.status IN ('near_expiry','expired') AND p.is_removed = 0
             AND p.company_id = $myCompanyId";
if (!in_array($myRole, ['super_admin','company_admin'], true) && $myBranchId > 0) {
    $sql .= " AND p.branch_id = $myBranchId";
}
if ($branchFilterValue !== null) $sql .= $branchFilterSqlAlias;
$sql .= " ORDER BY p.expiry_date ASC";

if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($sql); $stmt->bind_param('s', $branchFilterValue); $stmt->execute();
    $items = $stmt->get_result();
} else {
    $items = $conn->query($sql);
}
$rows = $items->fetch_all(MYSQLI_ASSOC);
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

<div class="eg-card">
  <div class="eg-card-header">
    <span class="eg-card-title"><i class="bi bi-bell me-2"></i>Alerts (<?= count($rows) ?>)</span>
    <div class="search-wrap">
      <i class="bi bi-search"></i>
      <input type="text" id="alertSearch" placeholder="Search…" oninput="filterTable('alertSearch','alertTable')">
    </div>
  </div>

  <div class="eg-table-wrap">
    <table class="eg-table" id="alertTable">
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
        $daysLeft = (int)((strtotime($item['expiry_date']) - time()) / 86400);
        $daysDisplay = $daysLeft < 0 ? abs($daysLeft) . 'd ago' : ($daysLeft === 0 ? 'Today' : $daysLeft . 'd left');
        $daysColor   = $daysLeft < 0 ? 'var(--red)' : ($daysLeft <= 3 ? 'var(--yellow)' : 'var(--text-muted)');
      ?>
        <tr>
          <td>
            <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($item['product_name']) ?></div>
          </td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($item['barcode']) ?></td>
          <td style="font-size:.8rem"><?= htmlspecialchars($item['category'] ?? '—') ?></td>
          <td style="font-size:.82rem"><?= date('M j, Y', strtotime($item['expiry_date'])) ?></td>
          <td><span style="font-weight:700;font-size:.8rem;color:<?= $daysColor ?>"><?= $daysDisplay ?></span></td>
          <td>
            <span class="badge-eg <?= $item['status'] === 'expired' ? 'badge-expired' : 'badge-near' ?>">
              <?= $item['status'] === 'expired' ? 'Expired' : 'Near Expiry' ?>
            </span>
          </td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($item['branch_name'] ?? '—') ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($item['entered_by_name'] ?? '—') ?></td>
          <td>
            <?php if (!in_array($myRole, ['viewer'], true)): ?>
            <button class="btn-eg btn-danger-eg btn-xs-eg" onclick="removeProduct(<?= (int)$item['id'] ?>, '<?= htmlspecialchars($item['product_name']) ?>')">
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
function filterTable(inputId, tableId) {
  const q = document.getElementById(inputId).value.toLowerCase();
  document.querySelectorAll(`#${tableId} tbody tr`).forEach(tr => {
    tr.style.display = !q || tr.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

async function removeProduct(id, name) {
  if (!confirm(`Remove "${name}" from inventory?`)) return;
  const fd = new FormData();
  fd.append('product_id', id);
  const res  = await fetch('../api/mark_removed.php', { method: 'POST', body: fd });
  const json = await res.json();
  showToast(json.success ? 'Product removed' : (json.message || 'Failed'), json.success ? 'ok' : 'err');
  if (json.success) setTimeout(() => location.reload(), 700);
}

// Auto-refresh every 60s
setTimeout(() => location.reload(), 60000);
</script>

<?php include 'layout_bottom.php'; ?>