<?php
include 'layout_top.php';

$myRole      = $_SESSION['role']      ?? 'viewer';
$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id']  ?? 0);
$myUserId    = (int)($_SESSION['user_id']     ?? 0);
$isAdmin     = in_array($myRole, ['super_admin','company_admin'], true);

// Employees and viewers need explicit view_reports permission
if (!in_array($myRole, ['super_admin', 'company_admin', 'branch_manager'], true)
    && !userHasPermission($conn, $myUserId, 'view_reports')) {
    header('Location: products.php');
    exit();
}

// ── Filter parameters ──────────────────────────────────────────────────────
$period   = $_GET['period']   ?? '30d';   // 7d, 30d, 90d, 180d, 365d, all
$interval = $_GET['interval'] ?? 'daily'; // daily, weekly, monthly
$branchF  = (int)($_GET['branch_id'] ?? 0);

// Enforce branch scope
if (!$isAdmin && $myBranchId > 0) $branchF = $myBranchId;

// Period → SQL date condition
$periodLabel = ['7d'=>'Last 7 Days','30d'=>'Last 30 Days','90d'=>'Last 90 Days','180d'=>'Last 6 Months','365d'=>'Last Year','all'=>'All Time'];
$periodMap   = ['7d'=>7,'30d'=>30,'90d'=>90,'180d'=>180,'365d'=>365,'all'=>null];
$periodDays  = $periodMap[$period] ?? 30;
$dateFrom    = $periodDays ? date('Y-m-d', strtotime("-{$periodDays} days")) : '2000-01-01';
$periodSQL   = "AND entered_on >= '$dateFrom'";
$removedSQL  = $periodDays ? "AND removed_on >= '$dateFrom'" : "";

// Interval → GROUP BY / label expressions
$intervalMap = [
    'daily'   => ["DATE(col)",                       "DATE_FORMAT(col,'%b %d')",             "DATE_FORMAT(col,'%b %d')"],
    'weekly'  => ["YEARWEEK(col,1)",                 "CONCAT('W',WEEK(col,1),' ',YEAR(col))","CONCAT('Wk ',WEEK(col,1),' ',YEAR(col))"],
    'monthly' => ["DATE_FORMAT(col,'%Y-%m')",        "DATE_FORMAT(col,'%b %Y')",             "DATE_FORMAT(col,'%b %Y')"],
];
[$grpExpr, $lblExpr, $lblFull] = $intervalMap[$interval] ?? $intervalMap['daily'];

// Helper: swap date column
function iv(string $tpl, string $col): string { return str_replace('col', $col, $tpl); }

// Branch scope SQL
$bScope  = "company_id=$myCompanyId" . ($branchF > 0 ? " AND branch_id=$branchF" : "");
$bScopeP = "p.company_id=$myCompanyId" . ($branchF > 0 ? " AND p.branch_id=$branchF" : "");

// Helper query functions
function anaQ(mysqli $conn, string $sql): array {
    $res = $conn->query($sql); if (!$res) return [];
    $r = $res->fetch_all(MYSQLI_ASSOC); $res->free(); return $r;
}
function anaOne(mysqli $conn, string $sql): array {
    $res = $conn->query($sql); if (!$res) return [];
    $r = $res->fetch_assoc() ?? []; $res->free(); return $r;
}

// ── 1. KPI snapshot ────────────────────────────────────────────────────────
$kpi = anaOne($conn, "SELECT
    COUNT(*) AS total,
    SUM(status='active'      AND is_removed=0)  AS active,
    SUM(status='near_expiry' AND is_removed=0)  AS near_expiry,
    SUM(status='expired'     AND is_removed=0)  AS expired,
    SUM(is_removed=1)                           AS removed,
    COALESCE(SUM(CASE WHEN is_removed=0 AND unit_price IS NOT NULL THEN unit_price*quantity ELSE 0 END),0) AS stock_value,
    COALESCE(SUM(CASE WHEN is_removed=1 AND unit_price IS NOT NULL THEN unit_price*quantity ELSE 0 END),0) AS waste_value,
    COUNT(DISTINCT category) AS categories
    FROM products WHERE $bScope");

$hasPrices = (float)($kpi['stock_value']??0) + (float)($kpi['waste_value']??0) > 0;

// ── 2. Products added trend ────────────────────────────────────────────────
$addedTrend = anaQ($conn, "
    SELECT 
        ".iv($grpExpr,'entered_on')." AS grp,
        MIN(".iv($lblExpr,'entered_on').") AS label,
        COUNT(*) AS total
    FROM products 
    WHERE $bScope $periodSQL
    GROUP BY ".iv($grpExpr,'entered_on')."
    ORDER BY grp ASC 
    LIMIT 120
");

// ── 3. Removals trend ─────────────────────────────────────────────────────
$removedTrend = anaQ($conn, "
    SELECT 
        ".iv($grpExpr,'removed_on')." AS grp,
        MIN(".iv($lblExpr,'removed_on').") AS label,
        COUNT(*) AS removed_count,
        COALESCE(SUM(unit_price * quantity), 0) AS waste_value
    FROM products 
    WHERE $bScope 
      AND is_removed = 1 
      $removedSQL 
      AND removed_on IS NOT NULL
    GROUP BY ".iv($grpExpr,'removed_on')."
    ORDER BY grp ASC 
    LIMIT 120
");

// ── 4. Expiry timeline — products expiring in upcoming windows ─────────────
$expiryWindows = anaQ($conn, "
    SELECT
        CASE
            WHEN expiry_date < CURDATE()                                        THEN 'Already Expired'
            WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7  DAY)            THEN 'Next 7 days'
            WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)            THEN '8–14 days'
            WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)            THEN '15–30 days'
            WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)            THEN '31–60 days'
            WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)            THEN '61–90 days'
            ELSE '90+ days'
        END AS window_label,
        CASE
            WHEN expiry_date < CURDATE()                                        THEN 0
            WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7  DAY)            THEN 1
            WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)            THEN 2
            WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)            THEN 3
            WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)            THEN 4
            WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)            THEN 5
            ELSE 6
        END AS sort_order,
        COUNT(*) AS total, SUM(quantity) AS total_qty
    FROM products WHERE $bScope AND is_removed=0
    GROUP BY window_label, sort_order ORDER BY sort_order ASC
");

// ── 5. Category analysis ───────────────────────────────────────────────────
$catAnalysis = anaQ($conn, "
    SELECT category,
           COUNT(*) AS total,
           SUM(status='active'      AND is_removed=0)   AS active,
           SUM(status='near_expiry' AND is_removed=0)   AS near_expiry,
           SUM(status='expired'     AND is_removed=0)   AS expired,
           SUM(is_removed=1)                             AS removed,
           COALESCE(SUM(CASE WHEN is_removed=1 AND unit_price IS NOT NULL THEN unit_price*quantity ELSE 0 END),0) AS waste_value,
           COALESCE(SUM(CASE WHEN is_removed=0 AND unit_price IS NOT NULL THEN unit_price*quantity ELSE 0 END),0) AS stock_value
    FROM products WHERE $bScope AND category IS NOT NULL
    GROUP BY category ORDER BY total DESC LIMIT 12
");

// ── 6. Products added by interval (for the per-category stacked chart) ─────
$catTrend = anaQ($conn, "
    SELECT 
        ".iv($grpExpr,'entered_on')." AS grp,
        MIN(".iv($lblExpr,'entered_on').") AS label,
        category,
        COUNT(*) AS total
    FROM products 
    WHERE $bScope 
      $periodSQL 
      AND category IS NOT NULL
    GROUP BY ".iv($grpExpr,'entered_on').", category
    ORDER BY grp ASC 
    LIMIT 300
");

// ── 7. Branch comparison (admin only) ─────────────────────────────────────
$branchComp = $isAdmin ? anaQ($conn, "
    SELECT b.branch_name,
           COUNT(p.id) AS total,
           SUM(p.status='active'      AND p.is_removed=0) AS active,
           SUM(p.status='near_expiry' AND p.is_removed=0) AS near_expiry,
           SUM(p.status='expired'     AND p.is_removed=0) AS expired,
           SUM(p.is_removed=1)                             AS removed,
           COALESCE(SUM(CASE WHEN p.is_removed=1 AND p.unit_price IS NOT NULL THEN p.unit_price*p.quantity ELSE 0 END),0) AS waste_value
    FROM branches b
    LEFT JOIN products p ON p.branch_id=b.id AND p.company_id=$myCompanyId
    WHERE b.company_id=$myCompanyId AND b.is_active=1
    GROUP BY b.id, b.branch_name ORDER BY total DESC
") : [];

// ── 8. Staff performance ───────────────────────────────────────────────────
$staffAdded = anaQ($conn, "
    SELECT u.full_name, u.role,
           COUNT(p.id) AS added,
           SUM(CASE WHEN p.entered_on >= '$dateFrom' THEN 1 ELSE 0 END) AS added_period
    FROM users u
    LEFT JOIN products p ON p.entered_by=u.id AND p.company_id=$myCompanyId".($branchF>0?" AND p.branch_id=$branchF":"")."
    WHERE u.company_id=$myCompanyId".($branchF>0?" AND u.branch_id=$branchF":"")."
    GROUP BY u.id, u.full_name, u.role HAVING added > 0 ORDER BY added DESC LIMIT 10
");

$staffRemoved = anaQ($conn, "
    SELECT u.full_name,
           COUNT(p.id) AS removed_count,
           COALESCE(SUM(CASE WHEN p.unit_price IS NOT NULL THEN p.unit_price*p.quantity ELSE 0 END),0) AS waste_handled
    FROM users u
    LEFT JOIN products p ON p.removed_by=u.id AND p.company_id=$myCompanyId".($branchF>0?" AND p.branch_id=$branchF":"")."
    WHERE u.company_id=$myCompanyId AND p.id IS NOT NULL
    GROUP BY u.id, u.full_name ORDER BY removed_count DESC LIMIT 10
");

// ── 9. Top removed products ────────────────────────────────────────────────
$topRemoved = anaQ($conn, "
    SELECT product_name, category, COUNT(*) AS times_removed,
           SUM(quantity) AS total_qty_removed,
           COALESCE(SUM(CASE WHEN unit_price IS NOT NULL THEN unit_price*quantity ELSE 0 END),0) AS total_waste
    FROM products WHERE $bScope AND is_removed=1 $removedSQL
    GROUP BY product_name, category ORDER BY times_removed DESC LIMIT 10
");

// ── 10. Potential loss — expired but not yet removed ───────────────────────
$potentialLoss = anaOne($conn, "
    SELECT COUNT(*) AS count,
           COALESCE(SUM(CASE WHEN unit_price IS NOT NULL THEN unit_price*quantity ELSE 0 END),0) AS potential_loss,
           SUM(quantity) AS total_qty
    FROM products WHERE $bScope AND status='expired' AND is_removed=0
");

// ── Load branches for filter dropdown ─────────────────────────────────────
$branchList = $isAdmin ? anaQ($conn, "SELECT id, branch_name FROM branches WHERE company_id=$myCompanyId AND is_active=1 ORDER BY branch_name ASC") : [];
?>

<div class="page-header">
  <div class="page-header-text">
    <h1>Analytics</h1>
    <p>Full inventory intelligence — <?= $periodLabel[$period] ?? '30 Days' ?></p>
  </div>
</div>

<!-- ── Global Filters ──────────────────────────────────────────────────── -->
<?php
// Build a URL with one param changed — preserves all other current params
function anaUrl(string $key, string $val): string {
    $params = ['period' => $_GET['period'] ?? '30d', 'interval' => $_GET['interval'] ?? 'daily', 'branch_id' => (string)($_GET['branch_id'] ?? '0')];
    $params[$key] = $val;
    if ($params['branch_id'] === '0') unset($params['branch_id']);
    return 'analytics.php?' . http_build_query($params);
}
?>
<div class="eg-card" style="margin-bottom:20px">
  <div class="eg-card-body" style="padding:14px 16px">
    <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end">

      <!-- Period: link-based, preserves interval + branch -->
      <div>
        <label class="eg-label">Time Period</label>
        <div style="display:flex;border:1px solid var(--border);border-radius:9px;overflow:hidden">
          <?php foreach(['7d'=>'7D','30d'=>'30D','90d'=>'90D','180d'=>'6M','365d'=>'1Y','all'=>'All'] as $v=>$l): ?>
          <a href="<?= anaUrl('period',$v) ?>"
            style="padding:7px 13px;text-decoration:none;display:block;background:<?=$period===$v?'var(--green)':'transparent'?>;color:<?=$period===$v?'#fff':'var(--text-muted)'?>;font-size:.8rem;font-weight:600;border-right:1px solid var(--border)">
            <?=$l?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Interval: link-based, preserves period + branch -->
      <div>
        <label class="eg-label">Chart Interval</label>
        <div style="display:flex;border:1px solid var(--border);border-radius:9px;overflow:hidden">
          <?php foreach(['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly'] as $v=>$l): ?>
          <a href="<?= anaUrl('interval',$v) ?>"
            style="padding:7px 13px;text-decoration:none;display:block;background:<?=$interval===$v?'var(--blue)':'transparent'?>;color:<?=$interval===$v?'#fff':'var(--text-muted)'?>;font-size:.8rem;font-weight:600;border-right:1px solid var(--border)">
            <?=$l?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

    </div><!-- /flex row -->
  </div>
</div>

<!-- ── KPI Snapshot ─────────────────────────────────────────────────────── -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr));margin-bottom:20px">
  <div class="kpi-card" style="--kpi-color:var(--blue);--kpi-bg:var(--blue-light)"><div class="kpi-icon"><i class="bi bi-box-seam"></i></div><div class="kpi-value"><?=number_format((int)$kpi['total'])?></div><div class="kpi-label">Total Products</div></div>
  <div class="kpi-card" style="--kpi-color:var(--green);--kpi-bg:var(--green-light)"><div class="kpi-icon"><i class="bi bi-check-circle"></i></div><div class="kpi-value"><?=number_format((int)$kpi['active'])?></div><div class="kpi-label">Active</div></div>
  <div class="kpi-card" style="--kpi-color:var(--yellow);--kpi-bg:var(--yellow-light)"><div class="kpi-icon"><i class="bi bi-exclamation-triangle"></i></div><div class="kpi-value"><?=number_format((int)$kpi['near_expiry'])?></div><div class="kpi-label">Near Expiry</div></div>
  <div class="kpi-card" style="--kpi-color:var(--red);--kpi-bg:var(--red-light)"><div class="kpi-icon"><i class="bi bi-x-circle"></i></div><div class="kpi-value"><?=number_format((int)$kpi['expired'])?></div><div class="kpi-label">Expired</div></div>
  <div class="kpi-card" style="--kpi-color:#64748b;--kpi-bg:#f1f5f9"><div class="kpi-icon"><i class="bi bi-trash3"></i></div><div class="kpi-value"><?=number_format((int)$kpi['removed'])?></div><div class="kpi-label">Removed</div></div>
  <?php if ($hasPrices): ?>
  <div class="kpi-card" style="--kpi-color:var(--green);--kpi-bg:var(--green-light)"><div class="kpi-icon"><i class="bi bi-safe2"></i></div><div class="kpi-value">$<?=number_format((float)$kpi['stock_value'],0)?></div><div class="kpi-label">Stock Value</div></div>
  <div class="kpi-card" style="--kpi-color:var(--red);--kpi-bg:var(--red-light)"><div class="kpi-icon"><i class="bi bi-currency-dollar"></i></div><div class="kpi-value">$<?=number_format((float)$kpi['waste_value'],0)?></div><div class="kpi-label">Total Waste</div></div>
  <?php endif; ?>
  <?php if ((float)$potentialLoss['potential_loss'] > 0): ?>
  <div class="kpi-card" style="--kpi-color:#f97316;--kpi-bg:#fff7ed">
    <div class="kpi-icon"><i class="bi bi-exclamation-octagon"></i></div>
    <div class="kpi-value">$<?=number_format((float)$potentialLoss['potential_loss'],0)?></div>
    <div class="kpi-label">Potential Loss<br><span style="font-size:.65rem;font-weight:400"><?=(int)$potentialLoss['count']?> expired, not removed</span></div>
  </div>
  <?php endif; ?>
</div>

<!-- ── Trends Section ──────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">

  <!-- Products Added Trend -->
  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-plus-circle me-2" style="color:var(--green)"></i>Products Added</span>
      <span style="font-size:.72rem;color:var(--text-muted)"><?=$periodLabel[$period]?> · <?=ucfirst($interval)?></span>
    </div>
    <div class="eg-card-body">
      <?php if(empty($addedTrend)): ?>
      <div class="empty-state" style="padding:40px"><i class="bi bi-graph-up"></i><p>No data for this period.</p></div>
      <?php else: ?>
      <div style="height:230px;position:relative"><canvas id="addedChart"></canvas></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Removals Trend -->
  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-trash3 me-2" style="color:var(--red)"></i>Products Removed</span>
      <span style="font-size:.72rem;color:var(--text-muted)"><?=$periodLabel[$period]?> · <?=ucfirst($interval)?></span>
    </div>
    <div class="eg-card-body">
      <?php if(empty($removedTrend)): ?>
      <div class="empty-state" style="padding:40px"><i class="bi bi-trash3"></i><p>No removals in this period.</p></div>
      <?php else: ?>
      <div style="height:230px;position:relative"><canvas id="removalChart"></canvas></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Expiry Intelligence ─────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1.2fr 1fr;gap:20px;margin-bottom:20px">

  <!-- Expiry windows bar -->
  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-calendar-x me-2" style="color:var(--yellow)"></i>Expiry Timeline</span>
      <span style="font-size:.72rem;color:var(--text-muted)">When products will expire</span>
    </div>
    <div class="eg-card-body">
      <?php if(empty($expiryWindows)): ?>
      <div class="empty-state" style="padding:40px"><i class="bi bi-calendar-check"></i><p>No products tracked.</p></div>
      <?php else: ?>
      <div style="height:230px;position:relative"><canvas id="expiryChart"></canvas></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Status distribution donut -->
  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-pie-chart me-2"></i>Status Distribution</span>
      <span style="font-size:.72rem;color:var(--text-muted)">Current inventory health</span>
    </div>
    <div class="eg-card-body">
      <div style="height:230px;position:relative"><canvas id="statusChart"></canvas></div>
    </div>
  </div>

</div>

<!-- ── Category Analysis ───────────────────────────────────────────────── -->
<div class="eg-card" style="margin-bottom:20px">
  <div class="eg-card-header">
    <span class="eg-card-title"><i class="bi bi-tags me-2"></i>Category Performance</span>
    <span style="font-size:.72rem;color:var(--text-muted)">All-time · sorted by volume</span>
  </div>
  <div class="eg-card-body">
    <?php if(empty($catAnalysis)): ?>
    <div class="empty-state"><i class="bi bi-tags"></i><p>No category data.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <?php
    $maxTotal = max(array_column($catAnalysis,'total')) ?: 1;
    ?>
    <table style="width:100%;border-collapse:collapse;font-size:.82rem">
      <thead>
        <tr style="border-bottom:1px solid var(--border)">
          <th style="padding:8px 12px;text-align:left;font-size:.7rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Category</th>
          <th style="padding:8px;text-align:center;font-size:.7rem;font-weight:700;color:var(--green);text-transform:uppercase">Active</th>
          <th style="padding:8px;text-align:center;font-size:.7rem;font-weight:700;color:var(--yellow);text-transform:uppercase">Near Exp.</th>
          <th style="padding:8px;text-align:center;font-size:.7rem;font-weight:700;color:var(--red);text-transform:uppercase">Expired</th>
          <th style="padding:8px;text-align:center;font-size:.7rem;font-weight:700;color:var(--text-muted);text-transform:uppercase">Removed</th>
          <?php if($hasPrices): ?>
          <th style="padding:8px;text-align:center;font-size:.7rem;font-weight:700;color:var(--red);text-transform:uppercase">Waste $</th>
          <?php endif; ?>
          <th style="padding:8px 12px;text-align:left;font-size:.7rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;min-width:140px">Alert Rate</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($catAnalysis as $ca):
        $total     = max(1,(int)$ca['total']);
        $alertRate = round(((int)$ca['near_expiry']+(int)$ca['expired'])/$total*100);
        $barPct    = round($total/$maxTotal*100);
        $rColor    = $alertRate>50?'var(--red)':($alertRate>20?'var(--yellow)':'var(--green)');
      ?>
        <tr style="border-bottom:1px solid #f1f5f9">
          <td style="padding:10px 12px">
            <div style="font-weight:600"><?=htmlspecialchars($ca['category'])?></div>
            <div style="height:4px;background:#e2e8f0;border-radius:2px;margin-top:4px;width:<?=$barPct?>%">
              <div style="height:100%;background:var(--blue);border-radius:2px"></div>
            </div>
            <div style="font-size:.72rem;color:var(--text-muted);margin-top:2px"><?=$total?> total</div>
          </td>
          <td style="padding:8px;text-align:center;font-weight:700;color:var(--green)"><?=(int)$ca['active']?></td>
          <td style="padding:8px;text-align:center;font-weight:700;color:<?=(int)$ca['near_expiry']>0?'var(--yellow)':'var(--text-muted)'?>"><?=(int)$ca['near_expiry']?></td>
          <td style="padding:8px;text-align:center;font-weight:700;color:<?=(int)$ca['expired']>0?'var(--red)':'var(--text-muted)'?>"><?=(int)$ca['expired']?></td>
          <td style="padding:8px;text-align:center;color:var(--text-muted)"><?=(int)$ca['removed']?></td>
          <?php if($hasPrices): ?>
          <td style="padding:8px;text-align:center;font-weight:700;color:var(--red)"><?=$ca['waste_value']>0?'$'.number_format($ca['waste_value'],2):'—'?></td>
          <?php endif; ?>
          <td style="padding:8px 12px">
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;height:6px;background:#e2e8f0;border-radius:3px">
                <div style="width:<?=$alertRate?>%;height:100%;background:<?=$rColor?>;border-radius:3px"></div>
              </div>
              <span style="font-size:.75rem;font-weight:700;color:<?=$rColor?>;min-width:30px"><?=$alertRate?>%</span>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Branch Comparison (admin only) ─────────────────────────────────── -->
<?php if ($isAdmin && !empty($branchComp)): ?>
<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:20px;margin-bottom:20px">

  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-diagram-3 me-2"></i>Branch Inventory Comparison</span>
      <span style="font-size:.72rem;color:var(--text-muted)">Active vs alerts vs removed</span>
    </div>
    <div class="eg-card-body"><div style="height:250px;position:relative"><canvas id="branchChart"></canvas></div></div>
  </div>

  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-graph-down me-2" style="color:var(--red)"></i>Waste by Branch</span>
      <span style="font-size:.72rem;color:var(--text-muted)"><?= $hasPrices ? 'Based on unit prices' : 'Add prices to unlock' ?></span>
    </div>
    <div class="eg-card-body">
      <?php if (!$hasPrices): ?>
      <div class="empty-state" style="padding:40px">
        <i class="bi bi-lock" style="color:var(--text-muted)"></i>
        <p>Set unit prices on products to see financial waste by branch.</p>
      </div>
      <?php else: ?>
      <div style="height:250px;position:relative"><canvas id="wasteByBranchChart"></canvas></div>
      <?php endif; ?>
    </div>
  </div>

</div>
<?php endif; ?>

<!-- ── Staff Performance ───────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">

  <!-- Top contributors -->
  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-person-check me-2"></i>Top Contributors</span>
      <span style="font-size:.72rem;color:var(--text-muted)">Most products added</span>
    </div>
    <div class="eg-card-body" style="padding-top:14px">
      <?php if(empty($staffAdded)): ?>
      <div class="empty-state" style="padding:20px"><i class="bi bi-people"></i><p>No staff data.</p></div>
      <?php else:
        $maxA = max(array_column($staffAdded,'added')) ?: 1;
      ?>
      <?php foreach($staffAdded as $i=>$s):
        $pct = round($s['added']/$maxA*100);
        $roleColors = ['super_admin'=>'badge-super','company_admin'=>'badge-company','branch_manager'=>'badge-manager','employee'=>'badge-employee','viewer'=>'badge-viewer'];
      ?>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
        <div style="width:26px;height:26px;border-radius:7px;background:var(--blue-light);color:var(--blue);font-size:.72rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0"><?=$i+1?></div>
        <div style="flex:1;min-width:0">
          <div style="display:flex;justify-content:space-between;margin-bottom:3px">
            <span style="font-size:.82rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($s['full_name'])?></span>
            <span style="font-size:.78rem;font-weight:700;color:var(--blue);flex-shrink:0;margin-left:8px"><?=$s['added']?> total</span>
          </div>
          <div style="height:5px;background:#e2e8f0;border-radius:3px">
            <div style="width:<?=$pct?>%;height:100%;background:var(--blue);border-radius:3px"></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Top removers -->
  <div class="eg-card" style="margin-bottom:0">
    <div class="eg-card-header">
      <span class="eg-card-title"><i class="bi bi-person-x me-2"></i>Top Removers</span>
      <span style="font-size:.72rem;color:var(--text-muted)">Most products removed</span>
    </div>
    <div class="eg-card-body" style="padding-top:14px">
      <?php if(empty($staffRemoved)): ?>
      <div class="empty-state" style="padding:20px"><i class="bi bi-people"></i><p>No removals yet.</p></div>
      <?php else:
        $maxR = max(array_column($staffRemoved,'removed_count')) ?: 1;
      ?>
      <?php foreach($staffRemoved as $i=>$s):
        $pct = round($s['removed_count']/$maxR*100);
      ?>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
        <div style="width:26px;height:26px;border-radius:7px;background:var(--red-light);color:var(--red);font-size:.72rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0"><?=$i+1?></div>
        <div style="flex:1;min-width:0">
          <div style="display:flex;justify-content:space-between;margin-bottom:3px">
            <span style="font-size:.82rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($s['full_name'])?></span>
            <div style="display:flex;gap:8px;flex-shrink:0;margin-left:8px">
              <span style="font-size:.78rem;font-weight:700;color:var(--red)"><?=$s['removed_count']?> removed</span>
              <?php if($s['waste_handled']>0): ?><span style="font-size:.72rem;color:var(--text-muted)">$<?=number_format($s['waste_handled'],2)?></span><?php endif; ?>
            </div>
          </div>
          <div style="height:5px;background:#e2e8f0;border-radius:3px">
            <div style="width:<?=$pct?>%;height:100%;background:var(--red);border-radius:3px"></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Most Removed Products ──────────────────────────────────────────── -->
<?php if (!empty($topRemoved)): ?>
<div class="eg-card" style="margin-bottom:20px">
  <div class="eg-card-header">
    <span class="eg-card-title"><i class="bi bi-arrow-repeat me-2"></i>Most Frequently Removed Products</span>
    <span style="font-size:.72rem;color:var(--text-muted)"><?=$periodLabel[$period]?></span>
  </div>
  <div class="eg-table-wrap">
    <table class="eg-table">
      <thead>
        <tr><th>Product</th><th>Category</th><th>Times Removed</th><th>Total Qty</th><?php if($hasPrices): ?><th>Total Waste $</th><?php endif; ?></tr>
      </thead>
      <tbody>
      <?php foreach($topRemoved as $i=>$r): ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="width:22px;height:22px;border-radius:6px;background:var(--red-light);color:var(--red);font-size:.7rem;font-weight:800;display:flex;align-items:center;justify-content:center"><?=$i+1?></div>
              <span style="font-weight:600;font-size:.85rem"><?=htmlspecialchars($r['product_name'])?></span>
            </div>
          </td>
          <td style="font-size:.82rem;color:var(--text-muted)"><?=htmlspecialchars($r['category']??'—')?></td>
          <td><span style="font-weight:700;color:var(--red)"><?=(int)$r['times_removed']?>×</span></td>
          <td style="font-size:.82rem"><?=(int)$r['total_qty_removed']?> units</td>
          <?php if($hasPrices): ?>
          <td style="font-weight:700;color:var(--red)"><?=$r['total_waste']>0?'$'.number_format($r['total_waste'],2):'—'?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Charts JS ───────────────────────────────────────────────────────── -->
<script>
const GREEN='#10b981',YELLOW='#f59e0b',RED='#ef4444',BLUE='#3b82f6',SLATE='#64748b';
const PALETTE=['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#f97316','#ec4899','#64748b','#06b6d4','#84cc16'];

// Products Added
<?php if(!empty($addedTrend)): ?>
new Chart(document.getElementById('addedChart'),{
  type:'bar',
  data:{labels:<?=json_encode(array_column($addedTrend,'label'))?>,datasets:[{label:'Products Added',data:<?=json_encode(array_column($addedTrend,'total'))?>,backgroundColor:'rgba(16,185,129,.25)',borderColor:GREEN,borderWidth:2,borderRadius:5}]},
  options:{scales:{x:{ticks:{color:'#94a3b8',font:{size:10},maxRotation:45},grid:{display:false}},y:{ticks:{color:'#94a3b8',font:{size:10}},grid:{color:'rgba(148,163,184,.15)'},beginAtZero:true,title:{display:true,text:'Items',color:'#94a3b8',font:{size:10}}}},plugins:{legend:{display:false},tooltip:{callbacks:{title:c=>c[0].label,label:c=>` ${c.parsed.y} products added`}}},maintainAspectRatio:false}
});
<?php endif; ?>

// Removals
<?php if(!empty($removedTrend)): ?>
{
  const removalDatasets = [
    {label:'Items Removed',data:<?=json_encode(array_column($removedTrend,'removed_count'))?>,backgroundColor:'rgba(239,68,68,.2)',borderColor:RED,borderWidth:2,borderRadius:5,yAxisID:'y'}
  ];
  const removalScales = {
    x:{ticks:{color:'#94a3b8',font:{size:10},maxRotation:45},grid:{display:false}},
    y:{ticks:{color:'#94a3b8',font:{size:10}},grid:{color:'rgba(148,163,184,.15)'},beginAtZero:true}
  };
  <?php if($hasPrices): ?>
  removalDatasets.push({label:'Waste Value ($)',type:'line',data:<?=json_encode(array_map(fn($r)=>round((float)$r['waste_value'],2),$removedTrend))?>,borderColor:'#f97316',backgroundColor:'rgba(249,115,22,.1)',borderWidth:2,pointRadius:3,fill:true,tension:.35,yAxisID:'y2'});
  removalScales.y2 = {position:'right',ticks:{color:'#f97316',font:{size:10},callback:v=>'$'+v.toLocaleString()},grid:{display:false},beginAtZero:true};
  <?php endif; ?>
  new Chart(document.getElementById('removalChart'),{
    type:'bar',
    data:{labels:<?=json_encode(array_column($removedTrend,'label'))?>,datasets:removalDatasets},
    options:{scales:removalScales,plugins:{legend:{display:<?=$hasPrices?'true':'false'?>,labels:{color:'#64748b',font:{size:11}}},tooltip:{callbacks:{title:c=>c[0].label}}},maintainAspectRatio:false}
  });
}
<?php endif; ?>

// Expiry windows
<?php if(!empty($expiryWindows)): ?>
const ewColors=[RED,RED,YELLOW,YELLOW,'#f97316',GREEN,GREEN];
const ewData=<?=json_encode($expiryWindows)?>;
new Chart(document.getElementById('expiryChart'),{
  type:'bar',
  data:{labels:ewData.map(r=>r.window_label),datasets:[{label:'Products',data:ewData.map(r=>r.total),backgroundColor:ewData.map((r,i)=>ewColors[i]||SLATE+'44'),borderColor:ewData.map((r,i)=>ewColors[i]||SLATE),borderWidth:2,borderRadius:6}]},
  options:{indexAxis:'y',scales:{x:{ticks:{color:'#94a3b8',font:{size:10}},grid:{color:'rgba(148,163,184,.15)'},beginAtZero:true},y:{ticks:{color:'#475569',font:{size:11,weight:'600'}},grid:{display:false}}},plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>` ${c.parsed.x} products (${ewData[c.dataIndex].total_qty} units)`}}},maintainAspectRatio:false}
});
<?php endif; ?>

// Status donut
<?php
$sdData=[
  ['label'=>'Active',     'val'=>(int)$kpi['active'],     'color'=>'#10b981'],
  ['label'=>'Near Expiry','val'=>(int)$kpi['near_expiry'], 'color'=>'#f59e0b'],
  ['label'=>'Expired',    'val'=>(int)$kpi['expired'],     'color'=>'#ef4444'],
  ['label'=>'Removed',    'val'=>(int)$kpi['removed'],     'color'=>'#94a3b8'],
];
$sdData = array_filter($sdData, fn($d)=>$d['val']>0);
?>
<?php if(!empty($sdData)): ?>
new Chart(document.getElementById('statusChart'),{
  type:'doughnut',
  data:{labels:<?=json_encode(array_column(array_values($sdData),'label'))?>,datasets:[{data:<?=json_encode(array_column(array_values($sdData),'val'))?>,backgroundColor:<?=json_encode(array_column(array_values($sdData),'color'))?>,borderWidth:0,hoverOffset:8}]},
  options:{cutout:'65%',plugins:{legend:{position:'bottom',labels:{color:'#64748b',padding:14,font:{size:11},usePointStyle:true}},tooltip:{callbacks:{label:ctx=>` ${ctx.label}: ${ctx.parsed.toLocaleString()} items`}}},maintainAspectRatio:false}
});
<?php endif; ?>

<?php if($isAdmin && !empty($branchComp)): ?>
// Branch comparison
new Chart(document.getElementById('branchChart'),{
  type:'bar',
  data:{
    labels:<?=json_encode(array_column($branchComp,'branch_name'))?>,
    datasets:[
      {label:'Active',    data:<?=json_encode(array_column($branchComp,'active'))?>,   backgroundColor:'rgba(16,185,129,.7)',borderRadius:3},
      {label:'Near Exp',  data:<?=json_encode(array_column($branchComp,'near_expiry'))?>,backgroundColor:'rgba(245,158,11,.7)',borderRadius:3},
      {label:'Expired',   data:<?=json_encode(array_column($branchComp,'expired'))?>,  backgroundColor:'rgba(239,68,68,.7)',borderRadius:3},
    ]
  },
  options:{scales:{x:{ticks:{color:'#94a3b8',font:{size:10}},stacked:true,grid:{display:false}},y:{ticks:{color:'#94a3b8',font:{size:10}},stacked:true,grid:{color:'rgba(148,163,184,.15)'},beginAtZero:true}},plugins:{legend:{labels:{color:'#64748b',font:{size:11},usePointStyle:true}}},maintainAspectRatio:false}
});

<?php if($hasPrices): ?>
new Chart(document.getElementById('wasteByBranchChart'),{
  type:'bar',
  data:{
    labels:<?=json_encode(array_column($branchComp,'branch_name'))?>,
    datasets:[{label:'Waste Value ($)',data:<?=json_encode(array_map(fn($b)=>round((float)$b['waste_value'],2),$branchComp))?>,backgroundColor:array_map(fn($b)=>$b['waste_value']>0?'rgba(239,68,68,.7)':'rgba(148,163,184,.3)',$branchComp),borderRadius:5}]
  },
  options:{indexAxis:'y',scales:{x:{ticks:{color:'#94a3b8',font:{size:10},callback:v=>'$'+v},grid:{color:'rgba(148,163,184,.15)'},beginAtZero:true},y:{ticks:{color:'#475569',font:{size:11,weight:'600'}},grid:{display:false}}},plugins:{legend:{display:false}},maintainAspectRatio:false}
});
<?php endif; ?>
<?php endif; ?>
</script>

<style>@media(max-width:768px){[style*="grid-template-columns:1fr 1fr"],[style*="grid-template-columns:1.2fr"],[style*="grid-template-columns:1.4fr"]{grid-template-columns:1fr!important}}</style>

<?php include 'layout_bottom.php'; ?>
