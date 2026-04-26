<?php
include 'layout_top.php';

$myUserId    = (int)($_SESSION['user_id']    ?? 0);
$myRole      = $_SESSION['role']             ?? 'viewer';
$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id']  ?? 0);

// Scoped WHERE for single-table queries (no alias)
$scopeWhere  = "WHERE company_id = $myCompanyId";
// Scoped WHERE for aliased queries (products p JOIN branches b)
$scopeWhereP = "WHERE p.company_id = $myCompanyId";
$scopeParams = [];
$scopeTypes  = '';

if (!in_array($myRole, ['super_admin','company_admin'], true) && $myBranchId > 0) {
    $scopeWhere  .= " AND branch_id = $myBranchId";
    $scopeWhereP .= " AND p.branch_id = $myBranchId";
}
if ($branchFilterValue !== null) {
    $scopeWhere  .= $branchFilterSql;
    $scopeWhereP .= str_replace('`branch_id`', 'p.`branch_id`', $branchFilterSql);
    $scopeTypes   = 's';
    $scopeParams  = [$branchFilterValue];
}

function dashCount(mysqli $conn, string $sql, string $t = '', array $p = []): int {
    $stmt = $conn->prepare($sql);
    if ($t !== '') $stmt->bind_param($t, ...$p);
    $stmt->execute();
    $res   = $stmt->get_result();
    $total = (int)$res->fetch_assoc()['total'];
    $res->free();
    $stmt->close();
    return $total;
}

$base = "SELECT COUNT(*) AS total FROM products $scopeWhere";
$stats = [
    'total'       => dashCount($conn, $base, $scopeTypes, $scopeParams),
    'active'      => dashCount($conn, "$base AND status='active' AND is_removed=0", $scopeTypes, $scopeParams),
    'near_expiry' => dashCount($conn, "$base AND status='near_expiry' AND is_removed=0", $scopeTypes, $scopeParams),
    'expired'     => dashCount($conn, "$base AND status='expired' AND is_removed=0", $scopeTypes, $scopeParams),
    'removed'     => dashCount($conn, "$base AND (status='removed' OR is_removed=1)", $scopeTypes, $scopeParams),
    'users'       => dashCount($conn, "SELECT COUNT(*) AS total FROM users WHERE company_id = $myCompanyId", '', []),
];

// Status chart data
$statusStmt = $conn->prepare("SELECT status, COUNT(*) AS total FROM products $scopeWhere AND is_removed=0 GROUP BY status");
if ($scopeTypes !== '') $statusStmt->bind_param($scopeTypes, ...$scopeParams);
$statusStmt->execute();
$statusRes  = $statusStmt->get_result();
$statusRows = $statusRes->fetch_all(MYSQLI_ASSOC);
$statusRes->free();
$statusStmt->close();

// 10-day trend
$trendStmt = $conn->prepare("SELECT DATE(entered_on) AS day, COUNT(*) AS total FROM products $scopeWhere AND entered_on >= DATE_SUB(CURDATE(), INTERVAL 10 DAY) GROUP BY day ORDER BY day ASC");
if ($scopeTypes !== '') $trendStmt->bind_param($scopeTypes, ...$scopeParams);
$trendStmt->execute();
$trendRes  = $trendStmt->get_result();
$trendRows = $trendRes->fetch_all(MYSQLI_ASSOC);
$trendRes->free();
$trendStmt->close();

// Top 5 products
$topStmt = $conn->prepare("SELECT product_name, COUNT(*) AS total FROM products $scopeWhere GROUP BY product_name ORDER BY total DESC LIMIT 5");
if ($scopeTypes !== '') $topStmt->bind_param($scopeTypes, ...$scopeParams);
$topStmt->execute();
$topRes      = $topStmt->get_result();
$topProducts = $topRes->fetch_all(MYSQLI_ASSOC);
$topRes->free();
$topStmt->close();

// Recent entries — uses aliased query to avoid ambiguous company_id
$recentStmt = $conn->prepare("SELECT p.product_name, p.barcode, p.expiry_date, p.status, p.entered_on, b.branch_name FROM products p LEFT JOIN branches b ON p.branch_id = b.id $scopeWhereP ORDER BY p.entered_on DESC LIMIT 8");
if ($scopeTypes !== '') $recentStmt->bind_param($scopeTypes, ...$scopeParams);
$recentStmt->execute();
$recentRes      = $recentStmt->get_result();
$recentProducts = $recentRes->fetch_all(MYSQLI_ASSOC);
$recentRes->free();
$recentStmt->close();

$statusColors = [
    'active'     => ['var(--green)', 'var(--green-light)'],
    'near_expiry'=> ['var(--yellow)', 'var(--yellow-light)'],
    'expired'    => ['var(--red)', 'var(--red-light)'],
    'removed'    => ['#64748b', '#f1f5f9'],
];
?>

<!-- Page header -->
<div class="page-header">
  <div class="page-header-text">
    <h1>Dashboard</h1>
    <p>Inventory health overview<?= $selectedBranch !== 'all' ? ' — filtered by branch' : '' ?></p>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <a href="export_csv.php" class="btn-eg btn-ghost-eg btn-sm-eg"><i class="bi bi-download"></i> Export CSV</a>
    <a href="products.php<?= $branchFilterValue ? '?branch='.urlencode($selectedBranch) : '' ?>" class="btn-eg btn-primary-eg btn-sm-eg"><i class="bi bi-plus-lg"></i> Add Product</a>
  </div>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
  <div class="kpi-card" style="--kpi-color:var(--blue);--kpi-bg:var(--blue-light)">
    <div class="kpi-icon"><i class="bi bi-box-seam"></i></div>
    <div class="kpi-value"><?= number_format($stats['total']) ?></div>
    <div class="kpi-label">Total Products</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--green);--kpi-bg:var(--green-light)">
    <div class="kpi-icon"><i class="bi bi-check-circle"></i></div>
    <div class="kpi-value"><?= number_format($stats['active']) ?></div>
    <div class="kpi-label">Active</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--yellow);--kpi-bg:var(--yellow-light)">
    <div class="kpi-icon"><i class="bi bi-exclamation-triangle"></i></div>
    <div class="kpi-value"><?= number_format($stats['near_expiry']) ?></div>
    <div class="kpi-label">Near Expiry</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--red);--kpi-bg:var(--red-light)">
    <div class="kpi-icon"><i class="bi bi-x-circle"></i></div>
    <div class="kpi-value"><?= number_format($stats['expired']) ?></div>
    <div class="kpi-label">Expired</div>
  </div>
  <div class="kpi-card" style="--kpi-color:#64748b;--kpi-bg:#f1f5f9">
    <div class="kpi-icon"><i class="bi bi-trash3"></i></div>
    <div class="kpi-value"><?= number_format($stats['removed']) ?></div>
    <div class="kpi-label">Removed</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--purple);--kpi-bg:var(--purple-light)">
    <div class="kpi-icon"><i class="bi bi-people"></i></div>
    <div class="kpi-value"><?= number_format($stats['users']) ?></div>
    <div class="kpi-label">Team Members</div>
  </div>
</div>

<!-- Charts row -->
<div style="display:grid;grid-template-columns:1fr 1.6fr;gap:20px;margin-bottom:20px" class="charts-row">

  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-pie-chart me-2"></i>Status Distribution</span>
    </div>
    <div class="eg-card-body">
      <div style="height:220px;position:relative">
        <canvas id="statusChart"></canvas>
      </div>
    </div>
  </div>

  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-graph-up me-2"></i>Products Added — Last 10 Days</span>
    </div>
    <div class="eg-card-body">
      <div style="height:220px;position:relative">
        <canvas id="trendChart"></canvas>
      </div>
    </div>
  </div>

</div>

<!-- Bottom row: recent entries + top products + NLP query -->
<div style="display:grid;grid-template-columns:1.6fr 1fr;gap:20px;margin-bottom:20px" class="bottom-row">

  <!-- Recent entries -->
  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-clock-history me-2"></i>Recent Entries</span>
      <a href="products.php<?= $branchFilterValue ? '?branch='.urlencode($selectedBranch) : '' ?>" class="btn-eg btn-ghost-eg btn-xs-eg">View all</a>
    </div>
    <div class="eg-table-wrap">
      <table class="eg-table">
        <thead><tr><th>Product</th><th>Expiry</th><th>Status</th><th>Branch</th></tr></thead>
        <tbody>
        <?php if (empty($recentProducts)): ?>
          <tr><td colspan="4"><div class="empty-state"><i class="bi bi-box-seam"></i><p>No products yet.</p></div></td></tr>
        <?php endif; ?>
        <?php foreach ($recentProducts as $p):
          $sc = ['active'=>'badge-active','near_expiry'=>'badge-near','expired'=>'badge-expired','removed'=>'badge-removed'];
          $sl = ['active'=>'Active','near_expiry'=>'Near Expiry','expired'=>'Expired','removed'=>'Removed'];
        ?>
          <tr>
            <td>
              <div style="font-weight:600;font-size:.82rem"><?= htmlspecialchars($p['product_name']) ?></div>
              <div style="font-size:.72rem;color:var(--text-muted)"><?= htmlspecialchars($p['barcode']) ?></div>
            </td>
            <td style="font-size:.82rem"><?= date('M j, Y', strtotime($p['expiry_date'])) ?></td>
            <td><span class="badge-eg <?= $sc[$p['status']] ?? 'badge-removed' ?>"><?= $sl[$p['status']] ?? $p['status'] ?></span></td>
            <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($p['branch_name'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Top products + NLP -->
  <div style="display:flex;flex-direction:column;gap:20px">
    <div class="eg-card" style="margin-bottom:0">
      <div class="eg-card-header">
        <span class="eg-card-title"><i class="bi bi-bar-chart me-2"></i>Top Products</span>
      </div>
      <div class="eg-card-body" style="padding-top:14px">
        <?php if (empty($topProducts)): ?>
          <div class="empty-state" style="padding:20px"><i class="bi bi-box-seam"></i><p>No data yet.</p></div>
        <?php else: ?>
          <?php foreach ($topProducts as $i => $tp): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f1f5f9">
            <div style="display:flex;align-items:center;gap:9px">
              <div style="width:22px;height:22px;border-radius:6px;background:var(--blue-light);color:var(--blue);font-size:.7rem;font-weight:800;display:flex;align-items:center;justify-content:center"><?= $i+1 ?></div>
              <span style="font-size:.82rem"><?= htmlspecialchars($tp['product_name']) ?></span>
            </div>
            <span style="font-size:.78rem;font-weight:700;color:var(--text-muted)"><?= $tp['total'] ?></span>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if (in_array($myRole, ['super_admin','company_admin'], true)): ?>
    <div class="eg-card" style="margin-bottom:0">
      <div class="eg-card-header">
        <span class="eg-card-title"><i class="bi bi-magic me-2"></i>AI Query</span>
      </div>
      <div class="eg-card-body">
        <p style="font-size:.78rem;color:var(--text-muted);margin-bottom:12px">Ask a question in plain English about your inventory.</p>
        <div class="form-group">
          <input type="text" id="nlpInput" class="eg-input" placeholder="e.g. Near expiry items this week…">
        </div>
        <button class="btn-eg btn-primary-eg btn-sm-eg w-100 justify-content-center" onclick="runNlp()">
          <i class="bi bi-send"></i> Run Query
        </button>
        <div id="nlpResult" style="margin-top:12px;font-size:.8rem"></div>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<script>
// Status donut chart
const statusData = <?= json_encode($statusRows) ?>;
const palette = { active: '#10b981', near_expiry: '#f59e0b', expired: '#ef4444', removed: '#94a3b8' };
new Chart(document.getElementById('statusChart'), {
  type: 'doughnut',
  data: {
    labels: statusData.map(r => r.status.replace('_',' ')),
    datasets: [{ data: statusData.map(r => r.total), backgroundColor: statusData.map(r => palette[r.status] || '#cbd5e1'), borderWidth: 0, hoverOffset: 6 }]
  },
  options: {
    cutout: '68%',
    plugins: { legend: { position: 'bottom', labels: { color: '#64748b', padding: 12, font: { size: 11 } } } },
    maintainAspectRatio: false
  }
});

// Trend line chart
const trendData = <?= json_encode($trendRows) ?>;
new Chart(document.getElementById('trendChart'), {
  type: 'bar',
  data: {
    labels: trendData.map(r => r.day),
    datasets: [{
      data: trendData.map(r => r.total),
      backgroundColor: 'rgba(16,185,129,.2)',
      borderColor: '#10b981', borderWidth: 2, borderRadius: 5
    }]
  },
  options: {
    scales: {
      x: { ticks: { color: '#94a3b8', font: { size: 10 } }, grid: { display: false } },
      y: { ticks: { color: '#94a3b8', font: { size: 10 } }, grid: { color: 'rgba(148,163,184,.15)' }, beginAtZero: true }
    },
    plugins: { legend: { display: false } },
    maintainAspectRatio: false
  }
});

// NLP query
async function runNlp() {
  const q = document.getElementById('nlpInput').value.trim();
  if (!q) return;
  const el = document.getElementById('nlpResult');
  el.innerHTML = '<div style="color:var(--text-muted)"><div class="spinner-border spinner-border-sm me-1"></div> Running…</div>';
  try {
    const fd = new FormData(); fd.append('query', q);
    const res  = await fetch('../api/nlp_query.php', { method: 'POST', body: fd });
    const json = await res.json();
    if (!json.success) { el.innerHTML = `<div style="color:var(--red)">${json.message}</div>`; return; }
    if (!json.data?.length) { el.innerHTML = '<div style="color:var(--text-muted)">No results.</div>'; return; }
    const cols = Object.keys(json.data[0]);
    let h = '<div style="overflow-x:auto"><table class="eg-table" style="font-size:.76rem"><thead><tr>' + cols.map(c=>`<th>${c}</th>`).join('') + '</tr></thead><tbody>';
    json.data.forEach(r => { h += '<tr>' + cols.map(c=>`<td>${r[c] ?? ''}</td>`).join('') + '</tr>'; });
    h += '</tbody></table></div>';
    el.innerHTML = `<div style="color:var(--green);font-size:.74rem;margin-bottom:8px"><i class="bi bi-check-circle"></i> ${json.data.length} row(s)</div>` + h;
  } catch { el.innerHTML = '<div style="color:var(--red)">Request failed.</div>'; }
}
document.getElementById('nlpInput')?.addEventListener('keydown', e => { if (e.key === 'Enter') runNlp(); });
</script>

<style>
@media (max-width: 768px) {
  .charts-row, .bottom-row { grid-template-columns: 1fr !important; }
}
</style>

<?php include 'layout_bottom.php'; ?>