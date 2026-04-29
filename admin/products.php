<?php
include 'layout_top.php';

$userRole             ??= 'viewer';
$selectedBranch       ??= 'all';
$branchFilterValue    ??= null;
$branchFilterSqlAlias ??= '';

$myRole      = $_SESSION['role']      ?? 'viewer';
$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id']  ?? 0);
$myUserId    = (int)($_SESSION['user_id']     ?? 0);

/*
|--------------------------------------------------------------------------
| Product action permissions
|--------------------------------------------------------------------------
| viewer   = view only
| employee = remove near_expiry / expired only
| manager/admin = edit, delete, remove
|--------------------------------------------------------------------------
*/
$isManagerRole = in_array($myRole, ['super_admin', 'company_admin', 'branch_manager'], true);

$canEditDeleteProduct = $isManagerRole || userHasPermission($conn, $myUserId, 'manage_products');
$canRemoveExpiredOnly = $myRole === 'employee' || userHasPermission($conn, $myUserId, 'remove_expired_items');
$canRemoveProduct     = $canEditDeleteProduct || $canRemoveExpiredOnly;

$search       = trim($_GET['q']         ?? '');
$statusFilter = trim($_GET['status']    ?? '');
$catFilter    = trim($_GET['category']  ?? '');
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to']   ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

// Categories for dropdown — session-cached
if (!isset($_SESSION['category_rules_cache'])) {
    $catRes = $conn->query("SELECT category_name FROM category_rules ORDER BY category_name ASC");
    $_SESSION['category_rules_cache'] = $catRes ? $catRes->fetch_all(MYSQLI_ASSOC) : [];
}
$categories = $_SESSION['category_rules_cache'];

// Build parameterized WHERE clause
$whereSql = "WHERE p.company_id = ?
               AND p.is_removed = 0
               AND p.status != 'removed'";
$params = [$myCompanyId];
$types  = 'i';

if (!in_array($myRole, ['super_admin','company_admin'], true) && $myBranchId > 0) {
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
    $whereSql .= " AND p.category = ?";
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
    $whereSql .= " AND (p.product_name LIKE ? OR p.barcode LIKE ? OR p.category LIKE ?)";
    $like      = '%' . $search . '%';
    $params[]  = $like;
    $params[]  = $like;
    $params[]  = $like;
    $types    .= 'sss';
}

// Total count for pagination
$countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM products p $whereSql");
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRows  = (int)$countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch current page
$pageParams = array_merge($params, [$perPage, $offset]);
$pageTypes  = $types . 'ii';

$dataStmt = $conn->prepare(
    "SELECT 
            p.id,
            p.product_name,
            p.barcode,
            p.category,
            p.expiry_date,
            p.quantity,
            p.unit_price,
            p.status,
            u.full_name AS entered_by_name,
            b.branch_name
     FROM products p
     LEFT JOIN users    u ON p.entered_by = u.id
     LEFT JOIN branches b ON p.branch_id  = b.id
     $whereSql
     ORDER BY p.expiry_date ASC
     LIMIT ? OFFSET ?"
);

$dataStmt->bind_param($pageTypes, ...$pageParams);
$dataStmt->execute();

$res      = $dataStmt->get_result();
$products = $res->fetch_all(MYSQLI_ASSOC);

$res->free();
$dataStmt->close();

$statusBadge = [
    'active'      => 'badge-active',
    'near_expiry' => 'badge-near',
    'expired'     => 'badge-expired',
];

$statusLabel = [
    'active'      => 'Active',
    'near_expiry' => 'Near Expiry',
    'expired'     => 'Expired',
];

$hasFilters = $search || $statusFilter || $catFilter || $dateFrom || $dateTo;
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

      <?php if ($branchFilterValue): ?>
        <input type="hidden" name="branch" value="<?= htmlspecialchars($selectedBranch, ENT_QUOTES, 'UTF-8') ?>">
      <?php endif; ?>

      <div style="flex:1;min-width:180px">
        <div class="search-wrap">
          <i class="bi bi-search"></i>
          <input type="text" name="q" placeholder="Search product, barcode…" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>

      <div>
        <label class="eg-label">Status</label>
        <select name="status" class="branch-select">
          <option value="">All</option>
          <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="near_expiry" <?= $statusFilter === 'near_expiry' ? 'selected' : '' ?>>Near Expiry</option>
          <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
        </select>
      </div>

      <div>
        <label class="eg-label">Category</label>
        <select name="category" class="branch-select">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?>" <?= $catFilter === $cat['category_name'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="eg-label">Expiry From</label>
        <input type="date" name="date_from" class="branch-select" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>">
      </div>

      <div>
        <label class="eg-label">Expiry To</label>
        <input type="date" name="date_to" class="branch-select" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>">
      </div>

      <button type="submit" class="btn-eg btn-primary-eg btn-sm-eg">
        <i class="bi bi-funnel"></i> Filter
      </button>

      <?php if ($hasFilters): ?>
        <a href="products.php<?= $branchFilterValue ? '?branch=' . urlencode($selectedBranch) : '' ?>" class="btn-eg btn-ghost-eg btn-sm-eg">
          Clear
        </a>
      <?php endif; ?>

    </form>
  </div>
</div>

<div class="eg-card">
  <div class="eg-card-header">
    <span class="eg-card-title">
      <i class="bi bi-box-seam me-2"></i>Products (<?= $totalRows ?>)
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
        <tr>
          <td colspan="10">
            <div class="empty-state">
              <i class="bi bi-box-seam"></i>
              <p>No products found.</p>
            </div>
          </td>
        </tr>
      <?php endif; ?>

      <?php foreach ($products as $p): ?>
        <?php
          $stockValue = ($p['unit_price'] !== null) ? ((float)$p['unit_price'] * (int)$p['quantity']) : null;
          $isExpiryActionItem = in_array($p['status'], ['near_expiry', 'expired'], true);
          $showRemoveButton = $canEditDeleteProduct || ($canRemoveExpiredOnly && $isExpiryActionItem);
        ?>

        <tr id="row_<?= (int)$p['id'] ?>">
          <td>
            <div style="font-weight:600;font-size:.85rem">
              <?= htmlspecialchars($p['product_name'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div style="font-size:.73rem;color:var(--text-muted)">
              <?= htmlspecialchars($p['barcode'], ENT_QUOTES, 'UTF-8') ?>
            </div>
          </td>

          <td style="font-size:.82rem">
            <?= htmlspecialchars($p['category'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
          </td>

          <td style="font-size:.82rem">
            <?= date('M j, Y', strtotime($p['expiry_date'])) ?>
          </td>

          <td style="font-size:.82rem">
            <?= (int)$p['quantity'] ?>
          </td>

          <td style="font-size:.82rem">
            <?= $p['unit_price'] !== null ? '$' . number_format((float)$p['unit_price'], 2) : '<span style="color:var(--text-muted)">—</span>' ?>
          </td>

          <td style="font-size:.82rem;font-weight:600">
            <?= $stockValue !== null ? '$' . number_format($stockValue, 2) : '<span style="color:var(--text-muted)">—</span>' ?>
          </td>

          <td>
            <span class="badge-eg <?= $statusBadge[$p['status']] ?? 'badge-removed' ?>">
              <?= $statusLabel[$p['status']] ?? htmlspecialchars($p['status'], ENT_QUOTES, 'UTF-8') ?>
            </span>
          </td>

          <td style="font-size:.78rem;color:var(--text-muted)">
            <?= htmlspecialchars($p['branch_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
          </td>

          <td style="font-size:.78rem;color:var(--text-muted)">
            <?= htmlspecialchars($p['entered_by_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
          </td>

          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">

              <?php if ($canEditDeleteProduct): ?>
                <button class="btn-eg btn-ghost-eg btn-xs-eg"
                  onclick="editProduct(<?= (int)$p['id'] ?>)">
                  <i class="bi bi-pencil"></i> Edit
                </button>

                <button class="btn-eg btn-ghost-eg btn-xs-eg" style="color:var(--red)"
                  onclick="deleteProduct(<?= (int)$p['id'] ?>, '<?= htmlspecialchars($p['product_name'], ENT_QUOTES, 'UTF-8') ?>')">
                  <i class="bi bi-x-circle"></i> Delete
                </button>
              <?php endif; ?>

              <?php if ($showRemoveButton): ?>
                <button class="btn-eg btn-ghost-eg btn-xs-eg" style="color:var(--red)"
                  onclick="removeProduct(<?= (int)$p['id'] ?>, '<?= htmlspecialchars($p['product_name'], ENT_QUOTES, 'UTF-8') ?>')">
                  <i class="bi bi-trash3"></i> Remove
                </button>
              <?php endif; ?>

              <?php if (!$canEditDeleteProduct && !$showRemoveButton): ?>
                <span style="font-size:.74rem;color:var(--text-muted)">—</span>
              <?php endif; ?>

            </div>
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
          return 'products.php?' . http_build_query($qp);
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

<?php if ($canEditDeleteProduct): ?>
<!-- Edit Product Modal -->
<div class="modal fade" id="editProductModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="border-radius:16px;border:0;box-shadow:0 20px 60px rgba(15,23,42,.25)">
      <form id="editProductForm">

        <div class="modal-header" style="border-bottom:1px solid var(--border)">
          <h5 class="modal-title" style="font-weight:800">
            <i class="bi bi-pencil-square me-2"></i>Edit Product
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="product_id" id="edit_product_id">

          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">

            <div>
              <label class="eg-label">Product Name</label>
              <input type="text" name="product_name" id="edit_product_name" class="form-control" required>
            </div>

            <div>
              <label class="eg-label">Barcode</label>
              <input type="text" name="barcode" id="edit_barcode" class="form-control" required>
            </div>

            <div>
              <label class="eg-label">Category</label>
              <select name="category" id="edit_category" class="form-control" required>
                <option value="">Select category</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="eg-label">Quantity</label>
              <input type="number" name="quantity" id="edit_quantity" class="form-control" min="1" required>
            </div>

            <div>
              <label class="eg-label">Unit Price</label>
              <input type="number" step="0.01" name="unit_price" id="edit_unit_price" class="form-control" min="0">
            </div>

            <div>
              <label class="eg-label">Unit / Measurement</label>
              <input type="text" name="unit" id="edit_unit" class="form-control" placeholder="Example: 500g, 1L, pack">
            </div>

            <div>
              <label class="eg-label">Expiry Date</label>
              <input type="date" name="expiry_date" id="edit_expiry_date" class="form-control" required>
            </div>

          </div>

          <div id="editProductError" class="alert alert-danger mt-3 d-none"></div>
        </div>

        <div class="modal-footer" style="border-top:1px solid var(--border)">
          <button type="button" class="btn-eg btn-ghost-eg" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-eg btn-primary-eg">
            <i class="bi bi-check2"></i> Save Changes
          </button>
        </div>

      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
async function removeProduct(id, name) {
  if (!confirm(`Remove "${name}" from inventory? This will move it to Removed.`)) return;

  const fd = new FormData();
  fd.append('product_id', id);

  try {
    const res = await fetch('../api/mark_removed.php', {
      method: 'POST',
      body: fd
    });

    const json = await res.json();

    showToast(
      json.success ? 'Product removed' : (json.message || 'Failed to remove product'),
      json.success ? 'ok' : 'err'
    );

    if (json.success) {
      setTimeout(() => location.reload(), 700);
    }
  } catch (error) {
    showToast('Network/server error while removing product', 'err');
  }
}

<?php if ($canEditDeleteProduct): ?>
let editProductModalInstance = null;

async function editProduct(id) {
  const errorBox = document.getElementById('editProductError');
  errorBox.classList.add('d-none');
  errorBox.textContent = '';

  try {
    const res = await fetch(`../api/get_product.php?product_id=${encodeURIComponent(id)}`);
    const json = await res.json();

    if (!json.success) {
      showToast(json.message || 'Failed to load product', 'err');
      return;
    }

    const p = json.data || {};

    document.getElementById('edit_product_id').value = p.id || '';
    document.getElementById('edit_product_name').value = p.product_name || '';
    document.getElementById('edit_barcode').value = p.barcode || '';
    document.getElementById('edit_category').value = p.category || '';
    document.getElementById('edit_quantity').value = p.quantity || 1;
    document.getElementById('edit_unit_price').value = p.unit_price ?? 0;
    document.getElementById('edit_unit').value = p.unit || '';
    document.getElementById('edit_expiry_date').value = p.expiry_date || '';

    const modalEl = document.getElementById('editProductModal');

    if (window.bootstrap && bootstrap.Modal) {
      editProductModalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
      editProductModalInstance.show();
    } else {
      showToast('Bootstrap modal script is not loaded.', 'err');
    }
  } catch (error) {
    showToast('Network/server error while loading product', 'err');
  }
}

document.getElementById('editProductForm')?.addEventListener('submit', async function (e) {
  e.preventDefault();

  const errorBox = document.getElementById('editProductError');
  errorBox.classList.add('d-none');
  errorBox.textContent = '';

  const fd = new FormData(this);

  try {
    const res = await fetch('../api/update_product.php', {
      method: 'POST',
      body: fd
    });

    const json = await res.json();

    if (!json.success) {
      errorBox.textContent = json.message || 'Failed to update product';
      errorBox.classList.remove('d-none');
      showToast(json.message || 'Failed to update product', 'err');
      return;
    }

    showToast('Product updated successfully', 'ok');

    if (editProductModalInstance) {
      editProductModalInstance.hide();
    }

    setTimeout(() => location.reload(), 700);
  } catch (error) {
    errorBox.textContent = 'Network/server error while updating product';
    errorBox.classList.remove('d-none');
    showToast('Network/server error while updating product', 'err');
  }
});

async function deleteProduct(id, name) {
  const firstConfirm = confirm(`Permanently delete "${name}"?\n\nThis is different from Remove and cannot be restored.`);
  if (!firstConfirm) return;

  const secondConfirm = confirm('Are you 100% sure? This will permanently delete the product row from the database.');
  if (!secondConfirm) return;

  const fd = new FormData();
  fd.append('product_id', id);

  try {
    const res = await fetch('../api/delete_product.php', {
      method: 'POST',
      body: fd
    });

    const json = await res.json();

    showToast(
      json.success ? 'Product deleted permanently' : (json.message || 'Failed to delete product'),
      json.success ? 'ok' : 'err'
    );

    if (json.success) {
      setTimeout(() => location.reload(), 700);
    }
  } catch (error) {
    showToast('Network/server error while deleting product', 'err');
  }
}
<?php endif; ?>
</script>

<?php include 'layout_bottom.php'; ?>