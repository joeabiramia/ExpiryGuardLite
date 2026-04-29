<?php
include 'layout_top.php';

$userRole          ??= 'viewer';
$selectedBranch    ??= 'all';
$branchFilterValue ??= null;

$myRole      = $_SESSION['role']      ?? 'viewer';
$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id']  ?? 0);
$myUserId    = (int)($_SESSION['user_id']     ?? 0);

// Can remove alerts if: not a viewer AND (is manager-level OR has an explicit remove permission)
$canRemove = $myRole !== 'viewer'
          && (
              in_array($myRole, ['super_admin', 'company_admin', 'branch_manager'], true)
              || userHasPermission($conn, $myUserId, 'remove_expired_items')
          );

// Filters
$search       = trim($_GET['q']         ?? '');
$statusFilter = trim($_GET['status']    ?? '');
$catFilter    = trim($_GET['category']  ?? '');
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to']   ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

// Categories for dropdown — session-cached to avoid a query on every page load
if (!isset($_SESSION['category_rules_cache'])) {
    $catRes = $conn->query("SELECT category_name FROM category_rules ORDER BY category_name ASC");
    $_SESSION['category_rules_cache'] = $catRes ? $catRes->fetch_all(MYSQLI_ASSOC) : [];
}

$categories = $_SESSION['category_rules_cache'];

// Build parameterized WHERE clause
$whereSql = "WHERE p.status IN ('near_expiry','expired')
               AND p.is_removed = 0
               AND p.company_id = ?";

$params = [$myCompanyId];
$types  = 'i';

if (!in_array($myRole, ['super_admin', 'company_admin'], true) && $myBranchId > 0) {
    $whereSql .= " AND p.branch_id = ?";
    $params[]  = $myBranchId;
    $types    .= 'i';
}

if ($branchFilterValue !== null) {
    $whereSql .= " AND p.branch_id = ?";
    $params[]  = (int)$branchFilterValue;
    $types    .= 'i';
}

if ($statusFilter !== '') {
    $whereSql .= " AND p.status = ?";
    $params[]  = $statusFilter;
    $types    .= 's';
}

if ($catFilter !== '') {
    // Railway collation-safe category filter
    $whereSql .= " AND p.category COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci";
    $params[]  = $catFilter;
    $types    .= 's';
}

if ($dateFrom !== '') {
    $whereSql .= " AND p.expiry_date >= ?";
    $params[]  = $dateFrom;
    $types    .= 's';
}

if ($dateTo !== '') {
    $whereSql .= " AND p.expiry_date <= ?";
    $params[]  = $dateTo;
    $types    .= 's';
}

if ($search !== '') {
    $whereSql .= " AND (p.product_name LIKE ? OR p.barcode LIKE ?)";
    $like      = '%' . $search . '%';
    $params[]  = $like;
    $params[]  = $like;
    $types    .= 'ss';
}

// Total count for pagination
$countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM products p $whereSql");

if (!$countStmt) {
    die('Database error: ' . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8'));
}

$countStmt->bind_param($types, ...$params);
$countStmt->execute();

$countResult = $countStmt->get_result();
$totalRows   = (int)($countResult->fetch_assoc()['total'] ?? 0);

$countResult->free();
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch current page with explicit columns
$pageParams = array_merge($params, [$perPage, $offset]);
$pageTypes  = $types . 'ii';

$dataStmt = $conn->prepare(
    "SELECT 
            p.id,
            p.product_name,
            p.barcode,
            p.category,
            p.expiry_date,
            p.status,
            u.full_name AS entered_by_name,
            b.branch_name,
            pc.measurement AS catalog_measurement
     FROM products p
     LEFT JOIN users u 
       ON p.entered_by = u.id
     LEFT JOIN branches b 
       ON p.branch_id = b.id
     LEFT JOIN product_catalog pc 
       ON pc.barcode COLLATE utf8mb4_general_ci = p.barcode COLLATE utf8mb4_general_ci
     $whereSql
     ORDER BY p.expiry_date ASC
     LIMIT ? OFFSET ?"
);

if (!$dataStmt) {
    die('Database error: ' . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8'));
}

$dataStmt->bind_param($pageTypes, ...$pageParams);
$dataStmt->execute();

$res  = $dataStmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);

$res->free();
$dataStmt->close();

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

      <?php if ($branchFilterValue): ?>
        <input type="hidden" name="branch" value="<?= htmlspecialchars($selectedBranch, ENT_QUOTES, 'UTF-8') ?>">
      <?php endif; ?>

      <div style="flex:1;min-width:180px">
        <div class="search-wrap">
          <i class="bi bi-search"></i>
          <input
            type="text"
            name="q"
            placeholder="Search product, barcode…"
            value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
          >
        </div>
      </div>

      <div>
        <label class="eg-label">Status</label>
        <select name="status" class="branch-select">
          <option value="">All Alerts</option>
          <option value="near_expiry" <?= $statusFilter === 'near_expiry' ? 'selected' : '' ?>>Near Expiry</option>
          <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
        </select>
      </div>

      <div>
        <label class="eg-label">Category</label>
        <select name="category" class="branch-select">
          <option value="">All Categories</option>

          <?php foreach ($categories as $cat): ?>
            <option
              value="<?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?>"
              <?= $catFilter === $cat['category_name'] ? 'selected' : '' ?>
            >
              <?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>

        </select>
      </div>

      <div>
        <label class="eg-label">Expiry From</label>
        <input
          type="date"
          name="date_from"
          class="branch-select"
          value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>"
        >
      </div>

      <div>
        <label class="eg-label">Expiry To</label>
        <input
          type="date"
          name="date_to"
          class="branch-select"
          value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>"
        >
      </div>

      <button type="submit" class="btn-eg btn-primary-eg btn-sm-eg">
        <i class="bi bi-funnel"></i> Filter
      </button>

      <?php if ($hasFilters): ?>
        <a href="notifications.php<?= $branchFilterValue ? '?branch=' . urlencode($selectedBranch) : '' ?>" class="btn-eg btn-ghost-eg btn-sm-eg">
          Clear
        </a>
      <?php endif; ?>

    </form>
  </div>
</div>

<div class="eg-card">
  <div class="eg-card-header">
    <span class="eg-card-title">
      <i class="bi bi-bell me-2"></i>Alerts (<?= $totalRows ?>)
    </span>

    <?php if ($totalPages > 1): ?>
      <span style="font-size:.78rem;color:var(--text-muted)">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php endif; ?>
  </div>

  <div class="eg-table-wrap">
    <table class="eg-table">
      <thead>
        <tr>
          <th>Product</th>
          <th>Barcode</th>
          <th>Category</th>
          <th>Measurement</th>
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
        <tr>
          <td colspan="10">
            <div class="empty-state">
              <i class="bi bi-check-circle"></i>
              <p>All clear! No alerts at this time.</p>
            </div>
          </td>
        </tr>
      <?php endif; ?>

      <?php foreach ($rows as $item): ?>
        <?php
          $daysLeft    = (int)((strtotime($item['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
          $daysDisplay = $daysLeft < 0 ? abs($daysLeft) . 'd ago' : ($daysLeft === 0 ? 'Today' : $daysLeft . 'd left');
          $daysColor   = $daysLeft < 0 ? 'var(--red)' : ($daysLeft <= 3 ? 'var(--yellow)' : 'var(--text-muted)');
        ?>

        <tr>
          <td>
            <div style="font-weight:600;font-size:.85rem">
              <?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?>
            </div>
          </td>

          <td style="font-size:.78rem;color:var(--text-muted)">
            <?= htmlspecialchars($item['barcode'], ENT_QUOTES, 'UTF-8') ?>
          </td>

          <td style="font-size:.8rem">
            <?= htmlspecialchars($item['category'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
          </td>

          <td style="font-size:.78rem;color:var(--text-muted)">
            <?= htmlspecialchars($item['catalog_measurement'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
          </td>

          <td style="font-size:.82rem">
            <?= date('M j, Y', strtotime($item['expiry_date'])) ?>
          </td>

          <td>
            <span style="font-weight:700;font-size:.8rem;color:<?= $daysColor ?>">
              <?= $daysDisplay ?>
            </span>
          </td>

          <td>
            <span class="badge-eg <?= $item['status'] === 'expired' ? 'badge-expired' : 'badge-near' ?>">
              <?= $item['status'] === 'expired' ? 'Expired' : 'Near Expiry' ?>
            </span>
          </td>

          <td style="font-size:.78rem;color:var(--text-muted)">
            <?= htmlspecialchars($item['branch_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
          </td>

          <td style="font-size:.78rem;color:var(--text-muted)">
            <?= htmlspecialchars($item['entered_by_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
          </td>

          <td>
            <?php if ($canRemove): ?>
              <button
                class="btn-eg btn-danger-eg btn-xs-eg"
                onclick="removeProduct(<?= (int)$item['id'] ?>, '<?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?>')"
              >
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

  <?php if ($totalPages > 1): ?>
    <?php
      $qp = $_GET;

      $makeUrl = function (int $p) use ($qp): string {
          $qp['page'] = $p;
          return 'notifications.php?' . http_build_query($qp);
      };

      $visible = [1];

      for ($i = $page - 1; $i <= $page + 1; $i++) {
          if ($i > 1 && $i < $totalPages) {
              $visible[] = $i;
          }
      }

      $visible[] = $totalPages;
      $visible = array_values(array_unique($visible));
      sort($visible);
    ?>

    <div style="padding:14px 20px;display:flex;justify-content:center;align-items:center;gap:6px;border-top:1px solid var(--border);flex-wrap:wrap">

      <?php if ($page > 1): ?>
        <a href="<?= $makeUrl($page - 1) ?>" style="padding:6px 12px;border-radius:7px;font-size:.8rem;font-weight:600;text-decoration:none;background:var(--bg);color:var(--text-muted);border:1px solid var(--border)">
          Previous
        </a>
      <?php else: ?>
        <span style="padding:6px 12px;border-radius:7px;font-size:.8rem;font-weight:600;background:var(--bg);color:#aaa;border:1px solid var(--border);opacity:.6">
          Previous
        </span>
      <?php endif; ?>

      <?php $last = 0; ?>
      <?php foreach ($visible as $vp): ?>
        <?php if ($last > 0 && $vp > $last + 1): ?>
          <span style="padding:6px 8px;color:var(--text-muted);font-weight:700">...</span>
        <?php endif; ?>

        <?php $last = $vp; ?>

        <a href="<?= $makeUrl($vp) ?>" style="padding:6px 11px;border-radius:7px;font-size:.8rem;font-weight:600;text-decoration:none;background:<?= $vp === $page ? 'var(--green)' : 'var(--bg)' ?>;color:<?= $vp === $page ? '#fff' : 'var(--text-muted)' ?>;border:1px solid <?= $vp === $page ? 'var(--green)' : 'var(--border)' ?>">
          <?= $vp ?>
        </a>
      <?php endforeach; ?>

      <?php if ($page < $totalPages): ?>
        <a href="<?= $makeUrl($page + 1) ?>" style="padding:6px 12px;border-radius:7px;font-size:.8rem;font-weight:600;text-decoration:none;background:var(--bg);color:var(--text-muted);border:1px solid var(--border)">
          Next
        </a>
      <?php else: ?>
        <span style="padding:6px 12px;border-radius:7px;font-size:.8rem;font-weight:600;background:var(--bg);color:#aaa;border:1px solid var(--border);opacity:.6">
          Next
        </span>
      <?php endif; ?>

    </div>
  <?php endif; ?>
</div>

<script>
async function removeProduct(id, name) {
  if (!confirm(`Remove "${name}" from inventory?`)) return;

  const fd = new FormData();
  fd.append('product_id', id);

  try {
    const res = await fetch('../api/mark_removed.php', {
      method: 'POST',
      body: fd
    });

    const json = await res.json();

    showToast(
      json.success ? 'Product removed' : (json.message || 'Failed'),
      json.success ? 'ok' : 'err'
    );

    if (json.success) {
      setTimeout(() => location.reload(), 700);
    }
  } catch (error) {
    showToast('Network/server error while removing product', 'err');
  }
}

// Refresh every 5 minutes
setTimeout(() => location.reload(), 300000);
</script>

<?php include 'layout_bottom.php'; ?>