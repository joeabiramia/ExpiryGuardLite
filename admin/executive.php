<?php
include 'layout_top.php';

// Executive dashboard — company_admin and super_admin only
if (!in_array($userRole, ['super_admin', 'company_admin'], true)) {
    header('Location: dashboard.php');
    exit();
}

$companyId = (int)($_SESSION['company_id'] ?? 0);

// ── Company-wide KPIs ────────────────────────────────────────
function execCount(mysqli $conn, string $sql): int {
    $res = $conn->query($sql);
    $row = $res->fetch_assoc();
    $res->free();
    return (int)($row['v'] ?? 0);
}

function execVal(mysqli $conn, string $sql): float {
    $res = $conn->query($sql);
    $row = $res->fetch_assoc();
    $res->free();
    return (float)($row['v'] ?? 0);
}

$totalProducts  = execCount($conn, "SELECT COUNT(*) AS v FROM products WHERE company_id=$companyId AND is_removed=0");
$totalActive    = execCount($conn, "SELECT COUNT(*) AS v FROM products WHERE company_id=$companyId AND status='active' AND is_removed=0");
$totalNear      = execCount($conn, "SELECT COUNT(*) AS v FROM products WHERE company_id=$companyId AND status='near_expiry' AND is_removed=0");
$totalExpired   = execCount($conn, "SELECT COUNT(*) AS v FROM products WHERE company_id=$companyId AND status='expired' AND is_removed=0");
$totalRemoved   = execCount($conn, "SELECT COUNT(*) AS v FROM products WHERE company_id=$companyId AND is_removed=1");
$totalWaste     = execVal($conn,   "SELECT COALESCE(SUM(unit_price * quantity),0) AS v FROM products WHERE company_id=$companyId AND is_removed=1 AND unit_price IS NOT NULL");
$monthWaste     = execVal($conn,   "SELECT COALESCE(SUM(unit_price * quantity),0) AS v FROM products WHERE company_id=$companyId AND is_removed=1 AND unit_price IS NOT NULL AND removed_on >= DATE_FORMAT(NOW(),'%Y-%m-01')");
$totalBranches  = execCount($conn, "SELECT COUNT(*) AS v FROM branches WHERE company_id=$companyId AND is_active=1");
$totalUsers     = execCount($conn, "SELECT COUNT(*) AS v FROM users WHERE company_id=$companyId AND is_active=1");

// ── Per-branch breakdown ─────────────────────────────────────
$branchRes = $conn->query("
    SELECT
        b.id,
        b.branch_name,
        COUNT(p.id)                                                          AS total,
        SUM(p.status='active'      AND p.is_removed=0)                       AS active,
        SUM(p.status='near_expiry' AND p.is_removed=0)                       AS near_expiry,
        SUM(p.status='expired'     AND p.is_removed=0)                       AS expired,
        SUM(p.is_removed=1)                                                  AS removed,
        COALESCE(SUM(CASE WHEN p.is_removed=1 AND p.unit_price IS NOT NULL
                     THEN p.unit_price * p.quantity ELSE 0 END), 0)          AS waste_value,
        COUNT(DISTINCT u.id)                                                 AS user_count
    FROM branches b
    LEFT JOIN products p ON p.branch_id = b.id AND p.company_id = $companyId
    LEFT JOIN users    u ON u.branch_id = b.id AND u.company_id = $companyId AND u.is_active = 1
    WHERE b.company_id = $companyId AND b.is_active = 1
    GROUP BY b.id, b.branch_name
    ORDER BY waste_value DESC
");
$branches = $branchRes->fetch_all(MYSQLI_ASSOC);
$branchRes->free();

// ── Waste by category ────────────────────────────────────────
$catWasteRes = $conn->query("
    SELECT category,
           COALESCE(SUM(unit_price * quantity), 0) AS waste_value,
           COUNT(*) AS removed_count
    FROM products
    WHERE company_id=$companyId AND is_removed=1 AND unit_price IS NOT NULL
    GROUP BY category
    ORDER BY waste_value DESC
    LIMIT 8
");
$catWaste = $catWasteRes->fetch_all(MYSQLI_ASSOC);
$catWasteRes->free();

// ── Monthly waste trend (last 6 months) ──────────────────────
$trendRes = $conn->query("
    SELECT DATE_FORMAT(removed_on,'%Y-%m') AS month,
           DATE_FORMAT(removed_on,'%b %Y') AS label,
           COALESCE(SUM(unit_price * quantity),0) AS waste_value,
           COUNT(*) AS removed_count
    FROM products
    WHERE company_id=$companyId AND is_removed=1 AND removed_on IS NOT NULL
    GROUP BY DATE_FORMAT(removed_on,'%Y-%m')
    ORDER BY month DESC
    LIMIT 6
");
$trend = array_reverse($trendRes->fetch_all(MYSQLI_ASSOC));
$trendRes->free();

// ── Best / Worst branch ──────────────────────────────────────
$worstBranch = null; $bestBranch = null;
foreach ($branches as $br) {
    if ($worstBranch === null || $br['waste_value'] > $worstBranch['waste_value']) $worstBranch = $br;
    if ($bestBranch  === null || ($br['near_expiry'] + $br['expired']) < ($bestBranch['near_expiry'] + $bestBranch['expired'])) $bestBranch = $br;
}
?>

<div class="page-header">
  <div class="page-header-text">
    <h1>Executive Dashboard</h1>
    <p>Cross-branch overview — full company visibility</p>
  </div>
  <span style="font-size:.78rem;color:var(--text-muted)">
    <i class="bi bi-calendar3 me-1"></i><?= date('F j, Y') ?>
  </span>
</div>

<!-- Company KPIs -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
  <div class="kpi-card" style="--kpi-color:var(--blue);--kpi-bg:var(--blue-light)">
    <div class="kpi-icon"><i class="bi bi-diagram-3"></i></div>
    <div class="kpi-value"><?= $totalBranches ?></div>
    <div class="kpi-label">Active Branches</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--green);--kpi-bg:var(--green-light)">
    <div class="kpi-icon"><i class="bi bi-box-seam"></i></div>
    <div class="kpi-value"><?= number_format($totalProducts) ?></div>
    <div class="kpi-label">Total Products</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--yellow);--kpi-bg:var(--yellow-light)">
    <div class="kpi-icon"><i class="bi bi-exclamation-triangle"></i></div>
    <div class="kpi-value"><?= number_format($totalNear + $totalExpired) ?></div>
    <div class="kpi-label">Needs Action</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--red);--kpi-bg:var(--red-light)">
    <div class="kpi-icon"><i class="bi bi-currency-dollar"></i></div>
    <div class="kpi-value">$<?= number_format($totalWaste, 0) ?></div>
    <div class="kpi-label">Total Waste Value</div>
  </div>
  <div class="kpi-card" style="--kpi-color:#f97316;--kpi-bg:#fff7ed">
    <div class="kpi-icon"><i class="bi bi-calendar-month"></i></div>
    <div class="kpi-value">$<?= number_format($monthWaste, 0) ?></div>
    <div class="kpi-label">This Month's Waste</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--purple);--kpi-bg:var(--purple-light)">
    <div class="kpi-icon"><i class="bi bi-people"></i></div>
    <div class="kpi-value"><?= $totalUsers ?></div>
    <div class="kpi-label">Active Users</div>
  </div>
</div>

<!-- Highlight cards -->
<?php if ($worstBranch || $bestBranch): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
  <?php if ($worstBranch && $worstBranch['waste_value'] > 0): ?>
  <div style="background:var(--red-light);border:1px solid #fca5a5;border-radius:var(--radius);padding:16px 20px;display:flex;align-items:center;gap:14px">
    <div style="width:44px;height:44px;border-radius:10px;background:var(--red);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">
      <i class="bi bi-graph-down-arrow"></i>
    </div>
    <div>
      <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#991b1b;letter-spacing:.05em">Highest Waste</div>
      <div style="font-weight:800;font-size:1rem;color:#7f1d1d"><?= htmlspecialchars($worstBranch['branch_name']) ?></div>
      <div style="font-size:.8rem;color:#991b1b">$<?= number_format($worstBranch['waste_value'],2) ?> wasted</div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($bestBranch): ?>
  <div style="background:var(--green-light);border:1px solid #6ee7b7;border-radius:var(--radius);padding:16px 20px;display:flex;align-items:center;gap:14px">
    <div style="width:44px;height:44px;border-radius:10px;background:var(--green);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">
      <i class="bi bi-trophy"></i>
    </div>
    <div>
      <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#065f46;letter-spacing:.05em">Best Performing</div>
      <div style="font-weight:800;font-size:1rem;color:#064e3b"><?= htmlspecialchars($bestBranch['branch_name']) ?></div>
      <div style="font-size:.8rem;color:#065f46"><?= (int)($bestBranch['near_expiry'] + $bestBranch['expired']) ?> items need action</div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Charts row -->
<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:20px;margin-bottom:20px">
  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header"><span class="eg-card-title"><i class="bi bi-graph-up me-2"></i>Monthly Waste Trend</span></div>
    <div class="eg-card-body"><div style="height:220px;position:relative"><canvas id="trendChart"></canvas></div></div>
  </div>
  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header"><span class="eg-card-title"><i class="bi bi-tags me-2"></i>Waste by Category</span></div>
    <div class="eg-card-body"><div style="height:220px;position:relative"><canvas id="catChart"></canvas></div></div>
  </div>
</div>

<!-- Branch comparison table -->
<div class="eg-card">
  <div class="eg-card-header">
    <span class="eg-card-title"><i class="bi bi-diagram-3 me-2"></i>Branch Performance Breakdown</span>
  </div>
  <div class="eg-table-wrap">
    <table class="eg-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Branch</th>
          <th>Users</th>
          <th>Total Products</th>
          <th><span style="color:var(--green)">Active</span></th>
          <th><span style="color:var(--yellow)">Near Expiry</span></th>
          <th><span style="color:var(--red)">Expired</span></th>
          <th>Removed</th>
          <th>Waste Value</th>
          <th>Health</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($branches)): ?>
        <tr><td colspan="10"><div class="empty-state"><i class="bi bi-diagram-3"></i><p>No branches found.</p></div></td></tr>
      <?php endif; ?>
      <?php foreach ($branches as $i => $br):
        $total   = max(1, (int)$br['total']);
        $alerts  = (int)$br['near_expiry'] + (int)$br['expired'];
        $health  = max(0, 100 - round(($alerts / $total) * 100));
        $hColor  = $health >= 80 ? 'var(--green)' : ($health >= 50 ? 'var(--yellow)' : 'var(--red)');
      ?>
        <tr>
          <td style="font-weight:700;color:var(--text-muted)"><?= $i+1 ?></td>
          <td style="font-weight:700"><?= htmlspecialchars($br['branch_name']) ?></td>
          <td style="font-size:.82rem"><?= (int)$br['user_count'] ?></td>
          <td style="font-size:.82rem"><?= number_format((int)$br['total']) ?></td>
          <td><span style="font-weight:700;color:var(--green)"><?= number_format((int)$br['active']) ?></span></td>
          <td><span style="font-weight:700;color:var(--yellow)"><?= number_format((int)$br['near_expiry']) ?></span></td>
          <td><span style="font-weight:700;color:var(--red)"><?= number_format((int)$br['expired']) ?></span></td>
          <td style="font-size:.82rem;color:var(--text-muted)"><?= number_format((int)$br['removed']) ?></td>
          <td style="font-weight:700;color:var(--red)">
            <?= $br['waste_value'] > 0 ? '$'.number_format($br['waste_value'],2) : '<span style="color:var(--text-muted);font-weight:400">—</span>' ?>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;height:6px;background:#e2e8f0;border-radius:3px;min-width:60px">
                <div style="width:<?= $health ?>%;height:100%;background:<?= $hColor ?>;border-radius:3px;transition:.3s"></div>
              </div>
              <span style="font-size:.75rem;font-weight:700;color:<?= $hColor ?>"><?= $health ?>%</span>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// Monthly waste trend
const trendData = <?= json_encode($trend) ?>;
new Chart(document.getElementById('trendChart'), {
  type: 'bar',
  data: {
    labels: trendData.map(r => r.label),
    datasets: [{
      label: 'Waste Value ($)',
      data: trendData.map(r => parseFloat(r.waste_value)),
      backgroundColor: 'rgba(239,68,68,.25)',
      borderColor: '#ef4444', borderWidth: 2, borderRadius: 5
    }]
  },
  options: {
    scales: {
      x: { ticks: { color:'#94a3b8', font:{size:10} }, grid:{display:false} },
      y: { ticks: { color:'#94a3b8', font:{size:10}, callback: v => '$'+v }, grid:{color:'rgba(148,163,184,.15)'}, beginAtZero:true }
    },
    plugins: { legend:{display:false} },
    maintainAspectRatio: false
  }
});

// Waste by category
const catData = <?= json_encode($catWaste) ?>;
new Chart(document.getElementById('catChart'), {
  type: 'doughnut',
  data: {
    labels: catData.map(r => r.category || 'Uncategorised'),
    datasets: [{
      data: catData.map(r => parseFloat(r.waste_value)),
      backgroundColor: ['#ef4444','#f97316','#f59e0b','#10b981','#3b82f6','#8b5cf6','#ec4899','#64748b'],
      borderWidth: 0, hoverOffset: 6
    }]
  },
  options: {
    cutout: '62%',
    plugins: { legend:{ position:'bottom', labels:{ color:'#64748b', font:{size:10}, padding:8 } } },
    maintainAspectRatio: false
  }
});
</script>

<style>
@media (max-width:768px) {
  .exec-grid { grid-template-columns: 1fr !important; }
}
</style>

<?php include 'layout_bottom.php'; ?>
