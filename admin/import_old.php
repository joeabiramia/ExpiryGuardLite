<?php
http_response_code(403);
exit('This old import page is disabled.');
include 'layout_top.php';
$userRole = $_SESSION['role'] ?? 'viewer';

if (!in_array($userRole, ['super_admin', 'company_admin', 'branch_manager'], true)) {
    header('Location: dashboard.php');
    exit();
}

$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id']  ?? 0);
$myUserId    = (int)($_SESSION['user_id']     ?? 0);

// Load branches for dropdown
$branchQ = "SELECT id, branch_name FROM branches WHERE company_id=$myCompanyId AND is_active=1";
if ($userRole === 'branch_manager' && $myBranchId > 0) $branchQ .= " AND id=$myBranchId";
$branchQ .= " ORDER BY branch_name ASC";
$branchRes  = $conn->query($branchQ);
$branchList = $branchRes->fetch_all(MYSQLI_ASSOC);
$branchRes->free();

// Load categories
$catRes     = $conn->query("SELECT category_name, alert_days_before FROM category_rules ORDER BY category_name ASC");
$catRules   = [];
while ($row = $catRes->fetch_assoc()) { $catRules[strtolower($row['category_name'])] = (int)$row['alert_days_before']; }
$catRes->free();

$results   = null;
$errors    = [];
$imported  = 0;
$skipped   = 0;
$today     = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $targetBranchId = (int)($_POST['branch_id'] ?? 0);

    // Enforce branch scope
    if ($userRole === 'branch_manager') $targetBranchId = $myBranchId;

    if ($targetBranchId <= 0) {
        $errors[] = 'Please select a branch.';
    } elseif ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed. Please try again.';
    } else {
        $file = $_FILES['csv_file']['tmp_name'];
        $ext  = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt'], true)) {
            $errors[] = 'Only CSV files are supported. In Excel: File → Save As → CSV.';
        } else {
            $handle = fopen($file, 'r');
            $header = fgetcsv($handle); // skip header row

            // Normalise header
            $header = array_map(fn($h) => strtolower(trim(str_replace([' ', '-', '_'], '', $h))), $header ?? []);

            // Expected columns (flexible matching)
            $colMap = [
                'barcode'     => array_search('barcode', $header)     !== false ? array_search('barcode', $header)     : false,
                'productname' => array_search('productname', $header)  !== false ? array_search('productname', $header)  : (array_search('name', $header) !== false ? array_search('name', $header) : false),
                'category'    => array_search('category', $header)    !== false ? array_search('category', $header)    : false,
                'expirydate'  => array_search('expirydate', $header)  !== false ? array_search('expirydate', $header)  : (array_search('expiry', $header) !== false ? array_search('expiry', $header) : false),
                'quantity'    => array_search('quantity', $header)    !== false ? array_search('quantity', $header)    : (array_search('qty', $header) !== false ? array_search('qty', $header) : false),
                'unitprice'   => array_search('unitprice', $header)   !== false ? array_search('unitprice', $header)   : (array_search('price', $header) !== false ? array_search('price', $header) : false),
            ];

            $required = ['barcode', 'productname', 'category', 'expirydate'];
            $missing  = [];
            foreach ($required as $col) {
                if ($colMap[$col] === false) $missing[] = $col;
            }

            if (!empty($missing)) {
                $errors[] = 'Missing required columns: ' . implode(', ', $missing) . '. Check column headers match the template.';
                fclose($handle);
            } else {
                $rowNum = 1;
                $rowResults = [];

                $insStmt = $conn->prepare("
                    INSERT IGNORE INTO products
                        (company_id, branch_id, barcode, product_name, category, quantity, unit_price,
                         expiry_date, status, entered_by, entered_on)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");

                while (($row = fgetcsv($handle)) !== false) {
                    $rowNum++;
                    if (count(array_filter($row)) === 0) continue; // skip empty rows

                    $barcode      = trim($row[$colMap['barcode']]     ?? '');
                    $product_name = trim($row[$colMap['productname']] ?? '');
                    $category     = trim($row[$colMap['category']]    ?? '');
                    $expiry_raw   = trim($row[$colMap['expirydate']]  ?? '');
                    $quantity     = max(1, (int)($colMap['quantity'] !== false ? ($row[$colMap['quantity']] ?? 1) : 1));
                    $unit_price   = $colMap['unitprice'] !== false && isset($row[$colMap['unitprice']]) && $row[$colMap['unitprice']] !== ''
                                    ? round((float)$row[$colMap['unitprice']], 2) : null;

                    // Validate required fields
                    if ($barcode === '' || $product_name === '' || $category === '' || $expiry_raw === '') {
                        $rowResults[] = ['row'=>$rowNum, 'ok'=>false, 'msg'=>"Row $rowNum: Missing required field — skipped."];
                        $skipped++;
                        continue;
                    }

                    // Normalise date (accept YYYY-MM-DD, DD/MM/YYYY, MM/DD/YYYY)
                    $expiry_date = null;
                    foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y'] as $fmt) {
                        $dt = DateTime::createFromFormat($fmt, $expiry_raw);
                        if ($dt) { $expiry_date = $dt->format('Y-m-d'); break; }
                    }
                    if (!$expiry_date) {
                        $rowResults[] = ['row'=>$rowNum, 'ok'=>false, 'msg'=>"Row $rowNum: Invalid date '$expiry_raw' — use YYYY-MM-DD."];
                        $skipped++;
                        continue;
                    }

                    // Determine status
                    $daysLeft  = (strtotime($expiry_date) - strtotime($today)) / 86400;
                    $alertDays = $catRules[strtolower($category)] ?? 4;
                    if ($daysLeft < 0)             $status = 'expired';
                    elseif ($daysLeft <= $alertDays) $status = 'near_expiry';
                    else                             $status = 'active';

                    $insStmt->bind_param('iisssisssis',
                        $myCompanyId, $targetBranchId, $barcode, $product_name,
                        $category, $quantity, $unit_price, $expiry_date, $status, $myUserId
                    );
                    $insStmt->execute();

                    if ($insStmt->affected_rows > 0) {
                        $imported++;
                        $rowResults[] = ['row'=>$rowNum, 'ok'=>true, 'msg'=>"Row $rowNum: ✓ $product_name ($expiry_date)"];
                    } else {
                        $skipped++;
                        $rowResults[] = ['row'=>$rowNum, 'ok'=>false, 'msg'=>"Row $rowNum: Duplicate — $barcode with expiry $expiry_date already exists in this branch."];
                    }
                }

                fclose($handle);
                $insStmt->close();
                $results = $rowResults;
            }
        }
    }
}
?>

<div class="page-header">
  <div class="page-header-text">
    <h1>Bulk Import</h1>
    <p>Import products from a CSV or Excel file</p>
  </div>
  <a href="data:text/csv;charset=utf-8,barcode,product_name,category,expiry_date,quantity,unit_price%0A7622201500061,Example Product,Snacks,2026-12-31,1,2.50"
     download="import_template.csv" class="btn-eg btn-ghost-eg btn-sm-eg">
    <i class="bi bi-download"></i> Download Template
  </a>
</div>

<!-- Instructions -->
<div class="eg-card" style="margin-bottom:16px">
  <div class="eg-card-header"><span class="eg-card-title"><i class="bi bi-info-circle me-2"></i>How to Import</span></div>
  <div class="eg-card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px">
      <div style="display:flex;gap:10px">
        <div style="width:28px;height:28px;border-radius:7px;background:var(--blue-light);color:var(--blue);font-weight:800;font-size:.8rem;display:flex;align-items:center;justify-content:center;flex-shrink:0">1</div>
        <div>
          <div style="font-weight:600;font-size:.85rem">Download Template</div>
          <div style="font-size:.78rem;color:var(--text-muted)">Use the template above or create a CSV with the required columns</div>
        </div>
      </div>
      <div style="display:flex;gap:10px">
        <div style="width:28px;height:28px;border-radius:7px;background:var(--blue-light);color:var(--blue);font-weight:800;font-size:.8rem;display:flex;align-items:center;justify-content:center;flex-shrink:0">2</div>
        <div>
          <div style="font-weight:600;font-size:.85rem">Fill Your Data</div>
          <div style="font-size:.78rem;color:var(--text-muted)">Fill in your products. Date format: <code>YYYY-MM-DD</code></div>
        </div>
      </div>
      <div style="display:flex;gap:10px">
        <div style="width:28px;height:28px;border-radius:7px;background:var(--blue-light);color:var(--blue);font-weight:800;font-size:.8rem;display:flex;align-items:center;justify-content:center;flex-shrink:0">3</div>
        <div>
          <div style="font-weight:600;font-size:.85rem">Save as CSV</div>
          <div style="font-size:.78rem;color:var(--text-muted)">In Excel: File → Save As → CSV (Comma delimited)</div>
        </div>
      </div>
      <div style="display:flex;gap:10px">
        <div style="width:28px;height:28px;border-radius:7px;background:var(--blue-light);color:var(--blue);font-weight:800;font-size:.8rem;display:flex;align-items:center;justify-content:center;flex-shrink:0">4</div>
        <div>
          <div style="font-weight:600;font-size:.85rem">Upload & Import</div>
          <div style="font-size:.78rem;color:var(--text-muted)">Select the branch and upload. Duplicates are skipped automatically.</div>
        </div>
      </div>
    </div>
    <div style="margin-top:14px;padding:10px 14px;background:var(--bg);border-radius:8px;font-size:.8rem;color:var(--text-muted)">
      <strong>Required columns:</strong> barcode, product_name, category, expiry_date &nbsp;|&nbsp;
      <strong>Optional:</strong> quantity, unit_price &nbsp;|&nbsp;
      <strong>Accepted date formats:</strong> YYYY-MM-DD, DD/MM/YYYY, MM/DD/YYYY
    </div>
  </div>
</div>

<!-- Upload form -->
<div class="eg-card" style="margin-bottom:16px">
  <div class="eg-card-header"><span class="eg-card-title"><i class="bi bi-upload me-2"></i>Upload CSV File</span></div>
  <div class="eg-card-body">
    <?php if (!empty($errors)): ?>
    <div style="background:var(--red-light);color:#991b1b;border-radius:9px;padding:12px 16px;margin-bottom:16px;font-size:.85rem">
      <?php foreach ($errors as $e): ?><div><i class="bi bi-x-circle me-1"></i><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
      <?php if ($userRole !== 'branch_manager'): ?>
      <div style="min-width:200px">
        <label class="eg-label">Target Branch *</label>
        <select name="branch_id" class="eg-select" required>
          <option value="">Select branch…</option>
          <?php foreach ($branchList as $br): ?>
          <option value="<?= (int)$br['id'] ?>"><?= htmlspecialchars($br['branch_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
      <input type="hidden" name="branch_id" value="<?= $myBranchId ?>">
      <div style="padding:8px 14px;background:var(--bg);border-radius:9px;font-size:.85rem;font-weight:600;align-self:flex-end">
        <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($branchList[0]['branch_name'] ?? 'Your Branch') ?>
      </div>
      <?php endif; ?>

      <div style="flex:1;min-width:220px">
        <label class="eg-label">CSV File *</label>
        <input type="file" name="csv_file" accept=".csv,.txt" class="eg-input" required
               style="padding:6px 10px;cursor:pointer">
      </div>

      <button type="submit" class="btn-eg btn-primary-eg">
        <i class="bi bi-cloud-upload"></i> Import Products
      </button>
    </form>
  </div>
</div>

<!-- Results -->
<?php if ($results !== null): ?>
<div class="eg-card">
  <div class="eg-card-header">
    <span class="eg-card-title"><i class="bi bi-clipboard-check me-2"></i>Import Results</span>
    <div style="display:flex;gap:12px;font-size:.82rem">
      <span style="color:var(--green);font-weight:700"><i class="bi bi-check-circle me-1"></i><?= $imported ?> imported</span>
      <span style="color:var(--text-muted);font-weight:700"><i class="bi bi-skip-forward me-1"></i><?= $skipped ?> skipped</span>
    </div>
  </div>

  <?php if ($imported > 0): ?>
  <div style="padding:12px 20px;background:var(--green-light);border-bottom:1px solid var(--border)">
    <span style="color:#065f46;font-weight:700"><i class="bi bi-check-circle-fill me-2"></i><?= $imported ?> product<?= $imported>1?'s':'' ?> successfully imported into inventory.</span>
  </div>
  <?php endif; ?>

  <div class="eg-table-wrap">
    <table class="eg-table">
      <thead><tr><th>Status</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($results as $r): ?>
        <tr>
          <td style="width:80px">
            <?php if ($r['ok']): ?>
            <span class="badge-eg badge-active"><i class="bi bi-check-lg me-1"></i>OK</span>
            <?php else: ?>
            <span class="badge-eg badge-removed"><i class="bi bi-x-lg me-1"></i>Skip</span>
            <?php endif; ?>
          </td>
          <td style="font-size:.82rem;color:<?= $r['ok']?'var(--text)':'var(--text-muted)' ?>"><?= htmlspecialchars($r['msg']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include 'layout_bottom.php'; ?>
