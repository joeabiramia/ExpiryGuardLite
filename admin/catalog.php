<?php
include 'layout_top.php';
$userRole = $_SESSION['role'] ?? 'viewer';

if (!in_array($userRole, ['super_admin', 'company_admin', 'branch_manager'], true)) {
    header('Location: dashboard.php');
    exit();
}

// Load categories for filter
$catRes = $conn->query("
    SELECT DISTINCT category
    FROM product_catalog
    WHERE category IS NOT NULL AND category <> ''
    ORDER BY category ASC
");
$catList = $catRes ? $catRes->fetch_all(MYSQLI_ASSOC) : [];

// Filters
$search = trim($_GET['q'] ?? '');
$catFilter = trim($_GET['category'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = "WHERE 1=1";

if ($search !== '') {
    $safeSearch = '%' . $conn->real_escape_string($search) . '%';
    $where .= "
        AND (
            item_id LIKE '$safeSearch'
            OR barcode LIKE '$safeSearch'
            OR product_name LIKE '$safeSearch'
            OR supplier LIKE '$safeSearch'
            OR category LIKE '$safeSearch'
            OR measurement LIKE '$safeSearch'
        )
    ";
}

if ($catFilter !== '') {
    $safeCat = $conn->real_escape_string($catFilter);
    $where .= " AND category = '$safeCat'";
}

$totalRes = $conn->query("SELECT COUNT(*) AS total FROM product_catalog $where");
$totalItems = $totalRes ? (int)$totalRes->fetch_assoc()['total'] : 0;
$totalPages = max(1, (int)ceil($totalItems / $perPage));

$itemsRes = $conn->query("
    SELECT
        id,
        item_id,
        barcode,
        product_name,
        category,
        measurement,
        unit_price,
        stock_level,
        supplier,
        image_url
    FROM product_catalog
    $where
    ORDER BY product_name ASC
    LIMIT $perPage OFFSET $offset
");
$items = $itemsRes ? $itemsRes->fetch_all(MYSQLI_ASSOC) : [];

$statsRes = $conn->query("
    SELECT
        COUNT(*) AS total,
        COUNT(DISTINCT category) AS cats,
        COUNT(DISTINCT supplier) AS suppliers,
        SUM(unit_price IS NOT NULL) AS with_price,
        SUM(stock_level) AS total_stock
    FROM product_catalog
");
$stats = $statsRes ? $statsRes->fetch_assoc() : [
    'total' => 0,
    'cats' => 0,
    'suppliers' => 0,
    'with_price' => 0,
    'total_stock' => 0
];

$hasFilters = $search !== '' || $catFilter !== '';
?>

<div class="page-header">
  <div class="page-header-text">
    <h1>Product Catalog</h1>
    <p>Master reference table — barcode scan auto-fills from this table</p>
  </div>

  <div style="display:flex;gap:8px">
    <a href="import_catalog.php" class="btn-eg btn-ghost-eg btn-sm-eg">
      <i class="bi bi-cloud-upload me-1"></i>Bulk Import
    </a>

    <button class="btn-eg btn-primary-eg" onclick="openAddItem()">
      <i class="bi bi-plus-lg"></i> Add Item
    </button>
  </div>
</div>

<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:20px">
  <div class="kpi-card" style="--kpi-color:var(--blue);--kpi-bg:var(--blue-light)">
    <div class="kpi-icon"><i class="bi bi-upc-scan"></i></div>
    <div class="kpi-value"><?=(int)$stats['total']?></div>
    <div class="kpi-label">Catalog Items</div>
  </div>

  <div class="kpi-card" style="--kpi-color:var(--green);--kpi-bg:var(--green-light)">
    <div class="kpi-icon"><i class="bi bi-tags"></i></div>
    <div class="kpi-value"><?=(int)$stats['cats']?></div>
    <div class="kpi-label">Categories</div>
  </div>

  <div class="kpi-card" style="--kpi-color:var(--purple);--kpi-bg:var(--purple-light)">
    <div class="kpi-icon"><i class="bi bi-truck"></i></div>
    <div class="kpi-value"><?=(int)$stats['suppliers']?></div>
    <div class="kpi-label">Suppliers</div>
  </div>

  <div class="kpi-card" style="--kpi-color:var(--yellow);--kpi-bg:var(--yellow-light)">
    <div class="kpi-icon"><i class="bi bi-box-seam"></i></div>
    <div class="kpi-value"><?=number_format((int)($stats['total_stock'] ?? 0))?></div>
    <div class="kpi-label">Total Stock</div>
  </div>
</div>

<div class="eg-card" style="margin-bottom:16px">
  <div class="eg-card-body" style="padding:12px 16px">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <div style="flex:1;min-width:200px">
        <div class="search-wrap">
          <i class="bi bi-search"></i>
          <input
            type="text"
            name="q"
            placeholder="Search item ID, barcode, name, supplier…"
            value="<?=htmlspecialchars($search, ENT_QUOTES, 'UTF-8')?>"
          >
        </div>
      </div>

      <select name="category" class="branch-select">
        <option value="">All Categories</option>
        <?php foreach ($catList as $c): ?>
          <option
            value="<?=htmlspecialchars($c['category'], ENT_QUOTES, 'UTF-8')?>"
            <?=$catFilter === $c['category'] ? 'selected' : ''?>
          >
            <?=htmlspecialchars($c['category'], ENT_QUOTES, 'UTF-8')?>
          </option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="btn-eg btn-primary-eg btn-sm-eg">
        <i class="bi bi-funnel"></i> Filter
      </button>

      <?php if ($hasFilters): ?>
        <a href="catalog.php" class="btn-eg btn-ghost-eg btn-sm-eg">Clear</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="eg-card">
  <div class="eg-card-header">
    <span class="eg-card-title">
      <i class="bi bi-upc-scan me-2"></i>Catalog (<?=number_format($totalItems)?>)
    </span>
    <span style="font-size:.78rem;color:var(--text-muted)">
      Page <?=$page?> of <?=$totalPages?>
    </span>
  </div>

  <div class="eg-table-wrap">
    <table class="eg-table">
      <thead>
        <tr>
          <th>Item ID</th>
          <th>Barcode</th>
          <th>Product Name</th>
          <th>Category</th>
          <th>Measurement</th>
          <th>Price</th>
          <th>Stock</th>
          <th>Supplier</th>
          <th>Image</th>
          <th>Actions</th>
        </tr>
      </thead>

      <tbody>
        <?php if (empty($items)): ?>
          <tr>
            <td colspan="10">
              <div class="empty-state">
                <i class="bi bi-upc-scan"></i>
                <p>No catalog items yet. Add your first product or import your CSV.</p>
              </div>
            </td>
          </tr>
        <?php endif; ?>

        <?php foreach ($items as $item): ?>
          <tr>
            <td>
              <code style="font-size:.78rem;background:var(--bg);padding:3px 7px;border-radius:5px;font-family:monospace">
                <?=htmlspecialchars($item['item_id'] ?: '—', ENT_QUOTES, 'UTF-8')?>
              </code>
            </td>

            <td>
              <code style="font-size:.78rem;background:var(--bg);padding:3px 7px;border-radius:5px;font-family:monospace">
                <?=htmlspecialchars($item['barcode'] ?: '—', ENT_QUOTES, 'UTF-8')?>
              </code>
            </td>

            <td>
              <div style="font-weight:600;font-size:.85rem">
                <?=htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8')?>
              </div>
            </td>

            <td>
              <?php if (!empty($item['category'])): ?>
                <span class="badge-eg badge-employee" style="font-size:.72rem">
                  <?=htmlspecialchars($item['category'], ENT_QUOTES, 'UTF-8')?>
                </span>
              <?php else: ?>
                <span style="color:var(--text-muted);font-size:.78rem">—</span>
              <?php endif; ?>
            </td>

            <td style="font-size:.82rem;color:var(--text-muted)">
              <?=htmlspecialchars($item['measurement'] ?: '—', ENT_QUOTES, 'UTF-8')?>
            </td>

            <td style="font-size:.82rem;font-weight:600">
              <?php if ($item['unit_price'] !== null && $item['unit_price'] !== ''): ?>
                $<?=number_format((float)$item['unit_price'], 2)?>
              <?php else: ?>
                <span style="color:var(--text-muted)">—</span>
              <?php endif; ?>
            </td>

            <td style="font-size:.82rem;font-weight:600">
              <?=number_format((int)($item['stock_level'] ?? 0))?>
            </td>

            <td style="font-size:.78rem;color:var(--text-muted)">
              <?=htmlspecialchars($item['supplier'] ?: '—', ENT_QUOTES, 'UTF-8')?>
            </td>

            <td style="font-size:.78rem">
              <?php if (!empty($item['image_url'])): ?>
                <a href="<?=htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8')?>" target="_blank" rel="noopener">
                  View
                </a>
              <?php else: ?>
                <span style="color:var(--text-muted)">—</span>
              <?php endif; ?>
            </td>

            <td>
              <div style="display:flex;gap:5px">
                <button
                  class="btn-eg btn-ghost-eg btn-xs-eg"
                  onclick="openEditItem(<?=htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8')?>)"
                  title="Edit"
                >
                  <i class="bi bi-pencil"></i>
                </button>

                <button
                  class="btn-eg btn-ghost-eg btn-xs-eg"
                  style="color:var(--red)"
                  onclick="deleteItem(<?=(int)$item['id']?>, '<?=htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8')?>')"
                  title="Delete"
                >
                  <i class="bi bi-trash3"></i>
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <?php
    $queryParams = $_GET;

    $makePageUrl = function ($pageNumber) use ($queryParams) {
        $queryParams['page'] = $pageNumber;
        return 'catalog.php?' . http_build_query($queryParams);
    };

    $visiblePages = [];

    $visiblePages[] = 1;

    for ($i = $page - 1; $i <= $page + 1; $i++) {
        if ($i > 1 && $i < $totalPages) {
            $visiblePages[] = $i;
        }
    }

    if ($totalPages > 1) {
        $visiblePages[] = $totalPages;
    }

    $visiblePages = array_values(array_unique($visiblePages));
    sort($visiblePages);
  ?>

  <div style="padding:14px 20px;display:flex;justify-content:center;align-items:center;gap:6px;border-top:1px solid var(--border);flex-wrap:wrap">

    <?php if ($page > 1): ?>
      <a
        href="<?=$makePageUrl($page - 1)?>"
        style="padding:6px 12px;border-radius:7px;font-size:.8rem;font-weight:600;text-decoration:none;background:var(--bg);color:var(--text-muted);border:1px solid var(--border)"
      >
        Previous
      </a>
    <?php else: ?>
      <span style="padding:6px 12px;border-radius:7px;font-size:.8rem;font-weight:600;background:var(--bg);color:#aaa;border:1px solid var(--border);opacity:.6">
        Previous
      </span>
    <?php endif; ?>

    <?php
      $lastPrinted = 0;
      foreach ($visiblePages as $p):
        if ($lastPrinted > 0 && $p > $lastPrinted + 1):
    ?>
          <span style="padding:6px 8px;color:var(--text-muted);font-weight:700">...</span>
    <?php
        endif;
        $lastPrinted = $p;
    ?>
        <a
          href="<?=$makePageUrl($p)?>"
          style="padding:6px 11px;border-radius:7px;font-size:.8rem;font-weight:600;text-decoration:none;
                 background:<?=$p === $page ? 'var(--green)' : 'var(--bg)'?>;
                 color:<?=$p === $page ? '#fff' : 'var(--text-muted)'?>;
                 border:1px solid <?=$p === $page ? 'var(--green)' : 'var(--border)'?>"
        >
          <?=$p?>
        </a>
    <?php endforeach; ?>

    <?php if ($page < $totalPages): ?>
      <a
        href="<?=$makePageUrl($page + 1)?>"
        style="padding:6px 12px;border-radius:7px;font-size:.8rem;font-weight:600;text-decoration:none;background:var(--bg);color:var(--text-muted);border:1px solid var(--border)"
      >
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

<div class="modal fade" id="catalogModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="catalogModalTitle">Add Catalog Item</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <form id="catalogForm">
          <input type="hidden" id="itemId" name="id" value="0">

          <div style="background:var(--blue-light);color:#1d4ed8;border-radius:9px;padding:10px 14px;font-size:.78rem;margin-bottom:16px">
            <i class="bi bi-info-circle me-1"></i>
            When an employee scans this barcode, the app will auto-fill the product information from this table.
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="eg-label">Item ID</label>
              <input type="text" id="itemItemId" name="item_id" class="eg-input" placeholder="Optional item ID">
            </div>

            <div class="form-group">
              <label class="eg-label">Barcode *</label>
              <input type="text" id="itemBarcode" name="barcode" class="eg-input" placeholder="e.g. 7622201500061" required>
            </div>
          </div>

          <div class="form-group">
            <label class="eg-label">Product Name *</label>
            <input type="text" id="itemName" name="product_name" class="eg-input" placeholder="Product name" required>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="eg-label">Category</label>
              <input type="text" id="itemCat" name="category" class="eg-input" placeholder="e.g. Dairy, Snacks…" list="catSuggestions">

              <datalist id="catSuggestions">
                <?php
                $cRules = $conn->query("SELECT category_name FROM category_rules ORDER BY category_name ASC");
                if ($cRules) {
                    while ($cr = $cRules->fetch_assoc()) {
                        echo '<option value="' . htmlspecialchars($cr['category_name'], ENT_QUOTES, 'UTF-8') . '">';
                    }
                }
                ?>
              </datalist>
            </div>

            <div class="form-group">
              <label class="eg-label">Measurement</label>
              <input type="text" id="itemMeasurement" name="measurement" class="eg-input" placeholder="e.g. kg, g, ml, pcs, box" list="measurementSuggestions">

              <datalist id="measurementSuggestions">
                <option value="pcs">
                <option value="kg">
                <option value="g">
                <option value="ml">
                <option value="L">
                <option value="box">
                <option value="pack">
                <option value="can">
                <option value="bottle">
              </datalist>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="eg-label">Unit Price ($)</label>
              <input type="number" id="itemPrice" name="unit_price" class="eg-input" placeholder="0.00" step="0.01" min="0">
            </div>

            <div class="form-group">
              <label class="eg-label">Stock Level</label>
              <input type="number" id="itemStock" name="stock_level" class="eg-input" placeholder="0" step="1" min="0">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="eg-label">Supplier</label>
              <input type="text" id="itemSupplier" name="supplier" class="eg-input" placeholder="Supplier / Distributor name">
            </div>

            <div class="form-group">
              <label class="eg-label">Image URL</label>
              <input type="url" id="itemImage" name="image_url" class="eg-input" placeholder="https://…">
            </div>
          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button class="btn-eg btn-ghost-eg" data-bs-dismiss="modal">Cancel</button>
        <button class="btn-eg btn-primary-eg" onclick="saveCatalogItem()">
          <i class="bi bi-check-lg"></i> Save Item
        </button>
      </div>
    </div>
  </div>
</div>

<script>
function openAddItem() {
  document.getElementById('catalogModalTitle').textContent = 'Add Catalog Item';
  document.getElementById('catalogForm').reset();
  document.getElementById('itemId').value = '0';

  new bootstrap.Modal(document.getElementById('catalogModal')).show();
}

function openEditItem(item) {
  document.getElementById('catalogModalTitle').textContent = 'Edit Catalog Item';

  document.getElementById('itemId').value = item.id || 0;
  document.getElementById('itemItemId').value = item.item_id || '';
  document.getElementById('itemBarcode').value = item.barcode || '';
  document.getElementById('itemName').value = item.product_name || '';
  document.getElementById('itemCat').value = item.category || '';
  document.getElementById('itemMeasurement').value = item.measurement || '';
  document.getElementById('itemPrice').value = item.unit_price !== null && item.unit_price !== undefined ? item.unit_price : '';
  document.getElementById('itemStock').value = item.stock_level !== null && item.stock_level !== undefined ? item.stock_level : '0';
  document.getElementById('itemSupplier').value = item.supplier || '';
  document.getElementById('itemImage').value = item.image_url || '';

  new bootstrap.Modal(document.getElementById('catalogModal')).show();
}

async function saveCatalogItem() {
  const form = document.getElementById('catalogForm');

  if (!form.reportValidity()) {
    return;
  }

  const fd = new FormData(form);

  try {
    const res = await fetch('../api/save_catalog_item.php', {
      method: 'POST',
      body: fd
    });

    const json = await res.json();

    showToast(
      json.success ? 'Catalog item saved' : (json.message || 'Failed to save catalog item'),
      json.success ? 'ok' : 'err'
    );

    if (json.success) {
      bootstrap.Modal.getInstance(document.getElementById('catalogModal'))?.hide();
      setTimeout(() => location.reload(), 700);
    }
  } catch (error) {
    showToast('Server error while saving catalog item', 'err');
  }
}

async function deleteItem(id, name) {
  if (!confirm(`Delete "${name}" from catalog? This will not delete already saved product records.`)) {
    return;
  }

  const fd = new FormData();
  fd.append('action', 'delete');
  fd.append('id', id);

  try {
    const res = await fetch('../api/save_catalog_item.php', {
      method: 'POST',
      body: fd
    });

    const json = await res.json();

    showToast(
      json.success ? 'Catalog item deleted' : (json.message || 'Failed to delete item'),
      json.success ? 'ok' : 'err'
    );

    if (json.success) {
      setTimeout(() => location.reload(), 700);
    }
  } catch (error) {
    showToast('Server error while deleting catalog item', 'err');
  }
}
</script>

<?php include 'layout_bottom.php'; ?>