<?php
include 'layout_top.php';

$myRole      = $_SESSION['role']      ?? 'viewer';
$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id']  ?? 0);

$sql  = "SELECT p.*, u.full_name AS entered_by_name, ru.full_name AS removed_by_name, b.branch_name
         FROM products p
         LEFT JOIN users    u  ON p.entered_by = u.id
         LEFT JOIN users    ru ON p.removed_by = ru.id
         LEFT JOIN branches b  ON p.branch_id  = b.id
         WHERE (p.status = 'removed' OR p.is_removed = 1)
           AND p.company_id = $myCompanyId";
if (!in_array($myRole, ['super_admin','company_admin'], true) && $myBranchId > 0) {
    $sql .= " AND p.branch_id = $myBranchId";
}
if ($branchFilterValue !== null) $sql .= $branchFilterSqlAlias;
$sql .= " ORDER BY p.removed_on DESC";

if ($branchFilterValue !== null) {
    $stmt = $conn->prepare($sql); $stmt->bind_param('s', $branchFilterValue); $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $items = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}
?>

<div class="page-header">
  <div class="page-header-text">
    <h1>Removed Products</h1>
    <p>Archive of products that have been removed from inventory</p>
  </div>
  <a href="export_csv.php?status=removed" class="btn-eg btn-ghost-eg btn-sm-eg">
    <i class="bi bi-download"></i> Export CSV
  </a>
</div>

<div class="eg-card">
  <div class="eg-card-header">
    <span class="eg-card-title"><i class="bi bi-archive me-2"></i>Removed (<?= count($items) ?>)</span>
    <div class="search-wrap">
      <i class="bi bi-search"></i>
      <input type="text" id="removedSearch" placeholder="Search…" oninput="filterTable('removedSearch','removedTable')">
    </div>
  </div>

  <div class="eg-table-wrap">
    <table class="eg-table" id="removedTable">
      <thead>
        <tr>
          <th>Product</th>
          <th>Category</th>
          <th>Expiry Date</th>
          <th>Branch</th>
          <th>Entered By</th>
          <th>Removed By</th>
          <th>Removed On</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($items)): ?>
        <tr><td colspan="7"><div class="empty-state"><i class="bi bi-archive"></i><p>No removed products.</p></div></td></tr>
      <?php endif; ?>
      <?php foreach ($items as $item): ?>
        <tr>
          <td>
            <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($item['product_name']) ?></div>
            <div style="font-size:.73rem;color:var(--text-muted)"><?= htmlspecialchars($item['barcode']) ?></div>
          </td>
          <td style="font-size:.82rem"><?= htmlspecialchars($item['category'] ?? '—') ?></td>
          <td style="font-size:.82rem"><?= date('M j, Y', strtotime($item['expiry_date'])) ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($item['branch_name'] ?? '—') ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($item['entered_by_name'] ?? '—') ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($item['removed_by_name'] ?? '—') ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)">
            <?= $item['removed_on'] ? date('M j, Y H:i', strtotime($item['removed_on'])) : '—' ?>
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
</script>

<?php include 'layout_bottom.php'; ?>