<?php
include 'layout_top.php';

$myUserId    = (int)($_SESSION['user_id']    ?? 0);
$myRole      = $_SESSION['role']             ?? 'viewer';
$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id']  ?? 0);
$isAdmin     = in_array($myRole, ['super_admin', 'company_admin'], true);

function dbQ(mysqli $conn, string $sql): array {
    $res = $conn->query($sql);
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
    return $rows;
}
function dbOne(mysqli $conn, string $sql): array {
    $res = $conn->query($sql);
    $row = $res->fetch_assoc() ?? [];
    $res->free();
    return $row;
}

if ($isAdmin) {
    // ── EXECUTIVE DATA ───────────────────────────────────────
    // Respect the topbar branch selector
    $execBranchId = ($branchFilterValue !== null) ? (int)$branchFilterValue : 0;
    $pScope = "company_id=$myCompanyId" . ($execBranchId > 0 ? " AND branch_id=$execBranchId" : "");

    $kpi = dbOne($conn, "
        SELECT
            (SELECT COUNT(*) FROM branches WHERE company_id=$myCompanyId AND is_active=1) AS branches,
            (SELECT COUNT(*) FROM products WHERE $pScope AND is_removed=0)                AS total,
            (SELECT COUNT(*) FROM products WHERE $pScope AND status='active'      AND is_removed=0) AS active,
            (SELECT COUNT(*) FROM products WHERE $pScope AND status='near_expiry' AND is_removed=0) AS near_expiry,
            (SELECT COUNT(*) FROM products WHERE $pScope AND status='expired'     AND is_removed=0) AS expired,
            (SELECT COUNT(*) FROM products WHERE $pScope AND is_removed=1)                AS removed,
            (SELECT COUNT(*) FROM users    WHERE company_id=$myCompanyId".($execBranchId>0?" AND branch_id=$execBranchId":"")." AND is_active=1) AS users,
            (SELECT COALESCE(SUM(unit_price*quantity),0) FROM products WHERE $pScope AND is_removed=1 AND unit_price IS NOT NULL) AS total_waste,
            (SELECT COALESCE(SUM(unit_price*quantity),0) FROM products WHERE $pScope AND is_removed=1 AND unit_price IS NOT NULL AND removed_on >= DATE_FORMAT(NOW(),'%Y-%m-01')) AS month_waste
    ");

    // Branch breakdown — always shows all branches for comparison, highlights selected
    $branchScopeFilter = $execBranchId > 0 ? "AND b.id = $execBranchId" : "";
    $branches = dbQ($conn, "
        SELECT b.id, b.branch_name,
               COUNT(p.id)                                                                AS total,
               SUM(p.status='active'      AND p.is_removed=0)                             AS active,
               SUM(p.status='near_expiry' AND p.is_removed=0)                             AS near_expiry,
               SUM(p.status='expired'     AND p.is_removed=0)                             AS expired,
               SUM(p.is_removed=1)                                                        AS removed,
               COALESCE(SUM(CASE WHEN p.is_removed=1 AND p.unit_price IS NOT NULL
                            THEN p.unit_price*p.quantity ELSE 0 END),0)                   AS waste_value,
               COUNT(DISTINCT u.id)                                                       AS user_count
        FROM branches b
        LEFT JOIN products p ON p.branch_id=b.id AND p.company_id=$myCompanyId
        LEFT JOIN users    u ON u.branch_id=b.id AND u.company_id=$myCompanyId AND u.is_active=1
        WHERE b.company_id=$myCompanyId AND b.is_active=1 $branchScopeFilter
        GROUP BY b.id, b.branch_name
        ORDER BY (SUM(p.status='near_expiry' AND p.is_removed=0) + SUM(p.status='expired' AND p.is_removed=0)) DESC
    ");

    // Status distribution
    $statusDist = dbQ($conn, "SELECT status, COUNT(*) AS total FROM products WHERE $pScope AND is_removed=0 GROUP BY status ORDER BY total DESC");

    // Monthly removals trend (last 6 months)
  $removalTrend = array_reverse(dbQ($conn, "
    SELECT 
        DATE_FORMAT(removed_on,'%Y-%m') AS month,
        MIN(DATE_FORMAT(removed_on,'%b %Y')) AS label,
        COUNT(*) AS removed_count,
        COALESCE(SUM(unit_price * quantity), 0) AS waste_value
    FROM products
    WHERE $pScope 
      AND is_removed = 1 
      AND removed_on IS NOT NULL
    GROUP BY DATE_FORMAT(removed_on,'%Y-%m')
    ORDER BY month DESC 
    LIMIT 6
"));

    // Category alerts
    $catAlert = dbQ($conn, "
        SELECT category, COUNT(*) AS total,
               SUM(status='near_expiry') AS near_expiry,
               SUM(status='expired') AS expired
        FROM products WHERE $pScope AND is_removed=0
          AND status IN ('near_expiry','expired') AND category IS NOT NULL
        GROUP BY category ORDER BY total DESC LIMIT 8
    ");

    // Waste by category
    $hasWaste = (float)($kpi['total_waste'] ?? 0) > 0;
    $catWaste = $hasWaste ? dbQ($conn, "SELECT category, COALESCE(SUM(unit_price*quantity),0) AS waste_value FROM products WHERE $pScope AND is_removed=1 AND unit_price IS NOT NULL GROUP BY category ORDER BY waste_value DESC LIMIT 8") : [];

    // Best / worst (only meaningful when viewing all branches)
    $worstBranch = null; $bestBranch = null;
    if ($execBranchId === 0 && count($branches) > 1) {
        foreach ($branches as $br) {
            $alerts = (int)$br['near_expiry'] + (int)$br['expired'];
            if ($worstBranch === null || $alerts > ((int)$worstBranch['near_expiry']+(int)$worstBranch['expired'])) $worstBranch = $br;
            if ($bestBranch  === null || $alerts < ((int)$bestBranch['near_expiry']+(int)$bestBranch['expired']))  $bestBranch  = $br;
        }
    }

} else {
    // ── BRANCH DATA ──────────────────────────────────────────
    $sw = "WHERE company_id=$myCompanyId";
    if ($myBranchId > 0) $sw .= " AND branch_id=$myBranchId";
    if ($branchFilterValue !== null) $sw .= $branchFilterSql;

    $kpi = dbOne($conn, "SELECT
        COUNT(*) AS total,
        SUM(status='active' AND is_removed=0) AS active,
        SUM(status='near_expiry' AND is_removed=0) AS near_expiry,
        SUM(status='expired' AND is_removed=0) AS expired,
        SUM(is_removed=1) AS removed
        FROM products $sw");

    $statusDist  = dbQ($conn, "SELECT status, COUNT(*) AS total FROM products $sw AND is_removed=0 GROUP BY status ORDER BY total DESC");
    $addedTrend = dbQ($conn, "
    SELECT 
        DATE(entered_on) AS day,
        MIN(DATE_FORMAT(entered_on,'%b %d')) AS label,
        COUNT(*) AS total
    FROM products 
    $sw 
      AND entered_on >= DATE_SUB(CURDATE(), INTERVAL 10 DAY)
    GROUP BY DATE(entered_on)
    ORDER BY day ASC
");
    $recentItems = dbQ($conn, "SELECT p.product_name,p.barcode,p.expiry_date,p.status,b.branch_name FROM products p LEFT JOIN branches b ON p.branch_id=b.id $sw ORDER BY p.expiry_date ASC LIMIT 10");
    $catBreakdown = dbQ($conn, "SELECT category, COUNT(*) AS total, SUM(status='near_expiry') AS near_expiry, SUM(status='expired') AS expired FROM products $sw AND is_removed=0 AND category IS NOT NULL GROUP BY category ORDER BY total DESC LIMIT 6");
}
?>

<?php if ($isAdmin): ?>
<!-- ════════════════ EXECUTIVE DASHBOARD ════════════════ -->
<div class="page-header">
  <div class="page-header-text">
    <h1>Dashboard</h1>
    <p>Company-wide overview — <?= date('F j, Y') ?></p>
  </div>
  <a href="import.php" class="btn-eg btn-ghost-eg btn-sm-eg"><i class="bi bi-cloud-upload me-1"></i>Bulk Import</a>
</div>

<!-- KPI Row -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(155px,1fr))">
  <div class="kpi-card" style="--kpi-color:var(--blue);--kpi-bg:var(--blue-light)">
    <div class="kpi-icon"><i class="bi bi-diagram-3"></i></div>
    <div class="kpi-value"><?= (int)$kpi['branches'] ?></div>
    <div class="kpi-label">Branches</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--green);--kpi-bg:var(--green-light)">
    <div class="kpi-icon"><i class="bi bi-box-seam"></i></div>
    <div class="kpi-value"><?= number_format((int)$kpi['total']) ?></div>
    <div class="kpi-label">Total Products</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--yellow);--kpi-bg:var(--yellow-light)">
    <div class="kpi-icon"><i class="bi bi-exclamation-triangle"></i></div>
    <div class="kpi-value"><?= number_format((int)$kpi['near_expiry']+(int)$kpi['expired']) ?></div>
    <div class="kpi-label">Needs Action</div>
  </div>
  <div class="kpi-card" style="--kpi-color:#64748b;--kpi-bg:#f1f5f9">
    <div class="kpi-icon"><i class="bi bi-trash3"></i></div>
    <div class="kpi-value"><?= number_format((int)$kpi['removed']) ?></div>
    <div class="kpi-label">Removed</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--red);--kpi-bg:var(--red-light)">
    <div class="kpi-icon"><i class="bi bi-currency-dollar"></i></div>
    <div class="kpi-value">$<?= number_format((float)$kpi['total_waste'], 0) ?></div>
    <div class="kpi-label">Total Waste Value</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--purple);--kpi-bg:var(--purple-light)">
    <div class="kpi-icon"><i class="bi bi-people"></i></div>
    <div class="kpi-value"><?= (int)$kpi['users'] ?></div>
    <div class="kpi-label">Active Users</div>
  </div>
</div>

<!-- Best / Worst highlight -->
<?php if (count($branches) > 1): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
  <?php if ($worstBranch): $wAlerts=(int)$worstBranch['near_expiry']+(int)$worstBranch['expired']; ?>
  <div style="background:var(--red-light);border:1px solid #fca5a5;border-radius:var(--radius);padding:16px 20px;display:flex;align-items:center;gap:14px">
    <div style="width:44px;height:44px;border-radius:10px;background:var(--red);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0"><i class="bi bi-exclamation-octagon"></i></div>
    <div>
      <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#991b1b;letter-spacing:.05em">Most Alerts</div>
      <div style="font-weight:800;font-size:1.05rem;color:#7f1d1d"><?= htmlspecialchars($worstBranch['branch_name']) ?></div>
      <div style="font-size:.8rem;color:#991b1b"><?= $wAlerts ?> item<?= $wAlerts!=1?'s':'' ?> need action<?= $worstBranch['waste_value']>0?' · $'.number_format($worstBranch['waste_value'],2).' wasted':'' ?></div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($bestBranch): $bAlerts=(int)$bestBranch['near_expiry']+(int)$bestBranch['expired']; ?>
  <div style="background:var(--green-light);border:1px solid #6ee7b7;border-radius:var(--radius);padding:16px 20px;display:flex;align-items:center;gap:14px">
    <div style="width:44px;height:44px;border-radius:10px;background:var(--green);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0"><i class="bi bi-trophy"></i></div>
    <div>
      <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#065f46;letter-spacing:.05em">Best Performing</div>
      <div style="font-weight:800;font-size:1.05rem;color:#064e3b"><?= htmlspecialchars($bestBranch['branch_name']) ?></div>
      <div style="font-size:.8rem;color:#065f46">Only <?= $bAlerts ?> item<?= $bAlerts!=1?'s':'' ?> need action</div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Charts Row -->
<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:20px;margin-bottom:20px">

  <!-- Monthly removals trend -->
  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-graph-up me-2"></i>Monthly Removals Trend</span>
      <span style="font-size:.72rem;color:var(--text-muted)">Items removed per month</span>
    </div>
    <div class="eg-card-body">
      <?php if (empty($removalTrend)): ?>
      <div class="empty-state" style="padding:40px"><i class="bi bi-graph-up"></i><p>No removals recorded yet.</p></div>
      <?php else: ?>
      <div style="height:220px;position:relative"><canvas id="removalChart"></canvas></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Status distribution -->
  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-pie-chart me-2"></i>Inventory Health</span>
      <span style="font-size:.72rem;color:var(--text-muted)">All branches combined</span>
    </div>
    <div class="eg-card-body">
      <?php if (empty($statusDist)): ?>
      <div class="empty-state" style="padding:40px"><i class="bi bi-pie-chart"></i><p>No products yet.</p></div>
      <?php else: ?>
      <div style="height:220px;position:relative"><canvas id="statusChart"></canvas></div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- Alerts by Category + Waste (if prices set) -->
<div style="display:grid;grid-template-columns:<?= $hasWaste?'1fr 1fr':'1fr' ?>;gap:20px;margin-bottom:20px">

  <!-- Expiry alerts by category -->
  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-bell me-2"></i>Alert Items by Category</span>
      <span style="font-size:.72rem;color:var(--text-muted)">Near expiry + expired</span>
    </div>
    <div class="eg-card-body" style="padding-top:14px">
      <?php if (empty($catAlert)): ?>
      <div class="empty-state" style="padding:20px"><i class="bi bi-check-circle" style="color:var(--green)"></i><p style="color:var(--green)">All clear — no alerts!</p></div>
      <?php else: ?>
      <?php foreach ($catAlert as $ca):
        $total = max(1, (int)$ca['near_expiry'] + (int)$ca['expired']);
        $maxTotal = max(array_column($catAlert, 'total'));
        $pct = round(($ca['total'] / max(1,$maxTotal)) * 100);
      ?>
      <div style="margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;margin-bottom:4px">
          <span style="font-size:.82rem;font-weight:600"><?= htmlspecialchars($ca['category']) ?></span>
          <div style="display:flex;gap:8px;font-size:.75rem">
            <span style="color:var(--yellow);font-weight:700"><?= (int)$ca['near_expiry'] ?> near</span>
            <span style="color:var(--red);font-weight:700"><?= (int)$ca['expired'] ?> expired</span>
          </div>
        </div>
        <div style="height:6px;background:#e2e8f0;border-radius:3px">
          <div style="width:<?= $pct ?>%;height:100%;background:linear-gradient(90deg,var(--yellow),var(--red));border-radius:3px"></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Waste by category (only if prices configured) -->
  <?php if ($hasWaste): ?>
  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-currency-dollar me-2"></i>Waste Value by Category</span>
      <span style="font-size:.72rem;color:var(--text-muted)">Based on unit prices</span>
    </div>
    <div class="eg-card-body">
      <div style="height:220px;position:relative"><canvas id="wasteChart"></canvas></div>
    </div>
  </div>
  <?php endif; ?>

</div>

<!-- Branch Breakdown Table -->
<div class="eg-card">
  <div class="eg-card-header">
    <span class="eg-card-title"><i class="bi bi-diagram-3 me-2"></i>Branch Performance Breakdown</span>
    <span style="font-size:.72rem;color:var(--text-muted)">Sorted by most alerts</span>
  </div>
  <div class="eg-table-wrap">
    <table class="eg-table">
      <thead>
        <tr>
          <th>#</th><th>Branch</th><th>Staff</th><th>Total</th>
          <th style="color:var(--green)">Active</th>
          <th style="color:var(--yellow)">Near Expiry</th>
          <th style="color:var(--red)">Expired</th>
          <th>Removed</th>
          <th>Waste $</th>
          <th>Health Score</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($branches)): ?>
        <tr><td colspan="10"><div class="empty-state"><i class="bi bi-diagram-3"></i><p>No branches found.</p></div></td></tr>
      <?php endif; ?>
      <?php foreach ($branches as $i => $br):
        $total  = max(1,(int)$br['total']);
        $alerts = (int)$br['near_expiry']+(int)$br['expired'];
        $health = max(0,100-round(($alerts/$total)*100));
        $hColor = $health>=80?'var(--green)':($health>=50?'var(--yellow)':'var(--red)');
        $hLabel = $health>=80?'Good':($health>=50?'Fair':'Poor');
      ?>
      <tr>
        <td style="font-weight:700;color:var(--text-muted);font-size:.85rem"><?= $i+1 ?></td>
        <td style="font-weight:700"><?= htmlspecialchars($br['branch_name']) ?></td>
        <td style="font-size:.82rem"><?= (int)$br['user_count'] ?></td>
        <td style="font-size:.82rem;font-weight:600"><?= number_format($total) ?></td>
        <td><span style="font-weight:700;color:var(--green)"><?= number_format((int)$br['active']) ?></span></td>
        <td>
          <span style="font-weight:700;color:<?= (int)$br['near_expiry']>0?'var(--yellow)':'var(--text-muted)' ?>">
            <?= number_format((int)$br['near_expiry']) ?>
          </span>
        </td>
        <td>
          <span style="font-weight:700;color:<?= (int)$br['expired']>0?'var(--red)':'var(--text-muted)' ?>">
            <?= number_format((int)$br['expired']) ?>
          </span>
        </td>
        <td style="font-size:.82rem;color:var(--text-muted)"><?= number_format((int)$br['removed']) ?></td>
        <td>
          <?php if ($br['waste_value']>0): ?>
          <span style="font-weight:700;color:var(--red)">$<?= number_format($br['waste_value'],2) ?></span>
          <?php else: ?>
          <span style="font-size:.75rem;color:var(--text-muted)">No prices set</span>
          <?php endif; ?>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:8px">
            <div style="flex:1;height:8px;background:#e2e8f0;border-radius:4px;min-width:70px">
              <div style="width:<?= $health ?>%;height:100%;background:<?= $hColor ?>;border-radius:4px;transition:.3s"></div>
            </div>
            <span style="font-size:.72rem;font-weight:700;color:<?= $hColor ?>;min-width:32px"><?= $health ?>%</span>
            <span class="badge-eg <?= $health>=80?'badge-active':($health>=50?'badge-near':'badge-expired') ?>" style="font-size:.65rem"><?= $hLabel ?></span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$hasWaste): ?>
  <div style="padding:12px 20px;background:#fffbeb;border-top:1px solid #fde68a;font-size:.78rem;color:#92400e">
    <i class="bi bi-lightbulb me-1"></i>
    <strong>Tip:</strong> Set unit prices when adding products to unlock waste cost tracking and financial insights.
  </div>
  <?php endif; ?>
</div>

<script>
(function () {
  const statusPalette = {active:'#10b981',near_expiry:'#f59e0b',expired:'#ef4444',removed:'#94a3b8'};
  const statusLabels  = {active:'Active',near_expiry:'Near Expiry',expired:'Expired',removed:'Removed'};
  const hasWaste = <?= $hasWaste ? 'true' : 'false' ?>;

<?php if (!empty($removalTrend)): ?>
  const removalDatasets = [{
    label: 'Items Removed',
    data: <?= json_encode(array_column($removalTrend,'removed_count')) ?>,
    backgroundColor: 'rgba(100,116,139,.2)',
    borderColor: '#64748b', borderWidth: 2, borderRadius: 6, yAxisID: 'y'
  }];
  const removalScales = {
    x: { ticks:{color:'#94a3b8',font:{size:10}}, grid:{display:false} },
    y: { ticks:{color:'#94a3b8',font:{size:10}}, grid:{color:'rgba(148,163,184,.15)'}, beginAtZero:true, title:{display:true,text:'Items',color:'#94a3b8',font:{size:10}} }
  };
  if (hasWaste) {
    removalDatasets.push({
      label: 'Waste Value ($)',
      data: <?= json_encode(array_map(fn($r)=>round((float)$r['waste_value'],2),$removalTrend)) ?>,
      type: 'line', borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,.1)',
      borderWidth: 2, pointRadius: 4, fill: true, tension: .35, yAxisID: 'y2'
    });
    removalScales.y2 = { position:'right', ticks:{color:'#ef4444',font:{size:10},callback:v=>'$'+v}, grid:{display:false}, beginAtZero:true };
  }
  new Chart(document.getElementById('removalChart'), {
    type: 'bar',
    data: { labels: <?= json_encode(array_column($removalTrend,'label')) ?>, datasets: removalDatasets },
    options: { scales: removalScales, plugins:{ legend:{display:hasWaste,labels:{color:'#64748b',font:{size:11}}} }, maintainAspectRatio:false }
  });
<?php endif; ?>

<?php if (!empty($statusDist)): ?>
  const sData = <?= json_encode($statusDist) ?>;
  new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
      labels:   sData.map(r => statusLabels[r.status] || r.status),
      datasets: [{ data: sData.map(r=>r.total), backgroundColor: sData.map(r=>statusPalette[r.status]||'#cbd5e1'), borderWidth:0, hoverOffset:8 }]
    },
    options: { cutout:'65%', plugins:{ legend:{position:'bottom',labels:{color:'#64748b',padding:14,font:{size:11},usePointStyle:true}}, tooltip:{callbacks:{label:ctx=>` ${statusLabels[sData[ctx.dataIndex]?.status]||ctx.label}: ${ctx.parsed} items`}} }, maintainAspectRatio:false }
  });
<?php endif; ?>

<?php if ($hasWaste && !empty($catWaste)): ?>
  const cData = <?= json_encode($catWaste) ?>;
  new Chart(document.getElementById('wasteChart'), {
    type: 'doughnut',
    data: {
      labels:   cData.map(r => r.category || 'Uncategorised'),
      datasets: [{ data: cData.map(r=>parseFloat(r.waste_value)), backgroundColor:['#ef4444','#f97316','#f59e0b','#10b981','#3b82f6','#8b5cf6','#ec4899','#64748b'], borderWidth:0, hoverOffset:8 }]
    },
    options: { cutout:'65%', plugins:{ legend:{position:'bottom',labels:{color:'#64748b',padding:12,font:{size:11},usePointStyle:true}}, tooltip:{callbacks:{label:ctx=>` ${ctx.label}: $${ctx.parsed.toFixed(2)}`}} }, maintainAspectRatio:false }
  });
<?php endif; ?>
})();
</script>

<?php else: ?>
<!-- ════════════════ BRANCH DASHBOARD ════════════════ -->
<div class="page-header">
  <div class="page-header-text">
    <h1>Dashboard</h1>
    <p>Branch overview<?= $selectedBranch!=='all'?' — filtered':'' ?> — <?= date('M j, Y') ?></p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="export_csv.php" class="btn-eg btn-ghost-eg btn-sm-eg"><i class="bi bi-download"></i> Export</a>
    <a href="products.php" class="btn-eg btn-primary-eg btn-sm-eg"><i class="bi bi-plus-lg"></i> Add Product</a>
  </div>
</div>

<!-- KPIs -->
<div class="kpi-grid">
  <div class="kpi-card" style="--kpi-color:var(--blue);--kpi-bg:var(--blue-light)"><div class="kpi-icon"><i class="bi bi-box-seam"></i></div><div class="kpi-value"><?= number_format((int)$kpi['total']) ?></div><div class="kpi-label">Total Products</div></div>
  <div class="kpi-card" style="--kpi-color:var(--green);--kpi-bg:var(--green-light)"><div class="kpi-icon"><i class="bi bi-check-circle"></i></div><div class="kpi-value"><?= number_format((int)$kpi['active']) ?></div><div class="kpi-label">Active</div></div>
  <div class="kpi-card" style="--kpi-color:var(--yellow);--kpi-bg:var(--yellow-light)"><div class="kpi-icon"><i class="bi bi-exclamation-triangle"></i></div><div class="kpi-value"><?= number_format((int)$kpi['near_expiry']) ?></div><div class="kpi-label">Near Expiry</div></div>
  <div class="kpi-card" style="--kpi-color:var(--red);--kpi-bg:var(--red-light)"><div class="kpi-icon"><i class="bi bi-x-circle"></i></div><div class="kpi-value"><?= number_format((int)$kpi['expired']) ?></div><div class="kpi-label">Expired</div></div>
  <div class="kpi-card" style="--kpi-color:#64748b;--kpi-bg:#f1f5f9"><div class="kpi-icon"><i class="bi bi-trash3"></i></div><div class="kpi-value"><?= number_format((int)$kpi['removed']) ?></div><div class="kpi-label">Removed</div></div>
</div>

<!-- Charts -->
<div style="display:grid;grid-template-columns:1fr 1.6fr;gap:20px;margin-bottom:20px">
  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-pie-chart me-2"></i>Status Breakdown</span>
      <span style="font-size:.72rem;color:var(--text-muted)">Current inventory</span>
    </div>
    <div class="eg-card-body">
      <?php if(empty($statusDist)): ?>
      <div class="empty-state" style="padding:40px"><i class="bi bi-box-seam"></i><p>No products yet.</p></div>
      <?php else: ?>
      <div style="height:220px;position:relative"><canvas id="statusChart"></canvas></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-graph-up me-2"></i>Products Added — Last 10 Days</span>
      <span style="font-size:.72rem;color:var(--text-muted)">Daily entry count</span>
    </div>
    <div class="eg-card-body">
      <?php if(empty($addedTrend)): ?>
      <div class="empty-state" style="padding:40px"><i class="bi bi-graph-up"></i><p>No entries in the last 10 days.</p></div>
      <?php else: ?>
      <div style="height:220px;position:relative"><canvas id="trendChart"></canvas></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Category alerts bar + Recent items -->
<div style="display:grid;grid-template-columns:1fr 1.6fr;gap:20px;margin-bottom:20px">

  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-tags me-2"></i>Alerts by Category</span>
    </div>
    <div class="eg-card-body" style="padding-top:14px">
      <?php if(empty($catBreakdown)): ?>
      <div class="empty-state" style="padding:20px"><i class="bi bi-check-circle" style="color:var(--green)"></i><p style="color:var(--green)">No alerts!</p></div>
      <?php else: ?>
      <?php foreach($catBreakdown as $cb):
        $maxT  = max(array_column($catBreakdown,'total'));
        $pct   = round(((int)$cb['total']/max(1,$maxT))*100);
        $alert = (int)$cb['near_expiry']+(int)$cb['expired'];
      ?>
      <div style="margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;margin-bottom:4px">
          <span style="font-size:.82rem;font-weight:600"><?= htmlspecialchars($cb['category']) ?></span>
          <span style="font-size:.75rem;color:var(--text-muted)"><?= (int)$cb['total'] ?> total<?= $alert>0?' · <span style="color:var(--red);font-weight:700">'.$alert.' alert</span>':'' ?></span>
        </div>
        <div style="height:6px;background:#e2e8f0;border-radius:3px">
          <div style="width:<?= $pct ?>%;height:100%;background:<?= $alert>0?'linear-gradient(90deg,var(--yellow),var(--red))':'var(--green)' ?>;border-radius:3px"></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-sort-up me-2"></i>Expiring Soon</span>
      <a href="notifications.php" class="btn-eg btn-ghost-eg btn-xs-eg">View alerts</a>
    </div>
    <div class="eg-table-wrap">
      <table class="eg-table">
        <thead><tr><th>Product</th><th>Category</th><th>Expiry</th><th>Days Left</th><th>Status</th></tr></thead>
        <tbody>
        <?php if(empty($recentItems)): ?>
          <tr><td colspan="5"><div class="empty-state"><i class="bi bi-check-circle" style="color:var(--green)"></i><p style="color:var(--green)">All products are within safe dates.</p></div></td></tr>
        <?php endif; ?>
        <?php foreach($recentItems as $p):
          $daysLeft = (int)((strtotime($p['expiry_date'])-strtotime(date('Y-m-d')))/86400);
          $sc = ['active'=>'badge-active','near_expiry'=>'badge-near','expired'=>'badge-expired','removed'=>'badge-removed'];
          $dColor = $daysLeft<0?'var(--red)':($daysLeft<=7?'var(--yellow)':'var(--text-muted)');
        ?>
          <tr>
            <td><div style="font-weight:600;font-size:.82rem"><?= htmlspecialchars($p['product_name']) ?></div><div style="font-size:.72rem;color:var(--text-muted)"><?= htmlspecialchars($p['barcode']) ?></div></td>
            <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($p['category']??'—') ?></td>
            <td style="font-size:.8rem"><?= date('M j, Y',strtotime($p['expiry_date'])) ?></td>
            <td><span style="font-weight:700;font-size:.78rem;color:<?= $dColor ?>"><?= $daysLeft<0?abs($daysLeft).'d ago':($daysLeft===0?'Today':$daysLeft.'d') ?></span></td>
            <td><span class="badge-eg <?= $sc[$p['status']]??'badge-removed' ?>" style="font-size:.68rem"><?= ucfirst(str_replace('_',' ',$p['status'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function () {
  const branchPalette = {active:'#10b981',near_expiry:'#f59e0b',expired:'#ef4444',removed:'#94a3b8'};
  const branchLabels  = {active:'Active',near_expiry:'Near Expiry',expired:'Expired',removed:'Removed'};

<?php if(!empty($statusDist)): ?>
  const sData = <?= json_encode($statusDist) ?>;
  new Chart(document.getElementById('statusChart'), {type:'doughnut',data:{labels:sData.map(r=>branchLabels[r.status]||r.status),datasets:[{data:sData.map(r=>r.total),backgroundColor:sData.map(r=>branchPalette[r.status]||'#cbd5e1'),borderWidth:0,hoverOffset:8}]},options:{cutout:'65%',plugins:{legend:{position:'bottom',labels:{color:'#64748b',padding:14,font:{size:11},usePointStyle:true}},tooltip:{callbacks:{label:ctx=>` ${ctx.label}: ${ctx.parsed} items`}}},maintainAspectRatio:false}});
<?php endif; ?>
<?php if(!empty($addedTrend)): ?>
  const tData = <?= json_encode($addedTrend) ?>;
  new Chart(document.getElementById('trendChart'), {type:'bar',data:{labels:tData.map(r=>r.label),datasets:[{data:tData.map(r=>r.total),backgroundColor:'rgba(16,185,129,.2)',borderColor:'#10b981',borderWidth:2,borderRadius:5}]},options:{scales:{x:{ticks:{color:'#94a3b8',font:{size:10}},grid:{display:false}},y:{ticks:{color:'#94a3b8',font:{size:10}},grid:{color:'rgba(148,163,184,.15)'},beginAtZero:true,title:{display:true,text:'Items Added',color:'#94a3b8',font:{size:10}}}},plugins:{legend:{display:false}},maintainAspectRatio:false}});
<?php endif; ?>
})();
</script>
<?php endif; ?>

<style>@media(max-width:768px){[style*="grid-template-columns:1.4fr"],[style*="grid-template-columns:1fr 1.6fr"],[style*="grid-template-columns:1fr 1fr"]{grid-template-columns:1fr!important}}</style>

<?php include 'layout_bottom.php'; ?>
