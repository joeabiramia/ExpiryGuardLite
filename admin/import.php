<?php
include 'layout_top.php';

$userRole = $_SESSION['role'] ?? 'viewer';

if (!in_array($userRole, ['super_admin', 'company_admin', 'branch_manager'], true)) {
    header('Location: dashboard.php');
    exit();
}

$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id'] ?? 0);
$myUserId    = (int)($_SESSION['user_id'] ?? 0);

$successMessage = '';
$errorMessage = '';
$importedCount = 0;
$updatedCount = 0;
$skippedRows = [];

/**
 * Load branches for dropdown.
 */
$branchSql = "SELECT id, branch_name FROM branches WHERE company_id = ? AND is_active = 1";
$branchParams = [$myCompanyId];
$branchTypes = "i";

if ($userRole === 'branch_manager' && $myBranchId > 0) {
    $branchSql .= " AND id = ?";
    $branchParams[] = $myBranchId;
    $branchTypes .= "i";
}

$branchSql .= " ORDER BY branch_name ASC";

$branchStmt = $conn->prepare($branchSql);
$branchStmt->bind_param($branchTypes, ...$branchParams);
$branchStmt->execute();
$branchResult = $branchStmt->get_result();
$branchList = $branchResult->fetch_all(MYSQLI_ASSOC);
$branchResult->free();
$branchStmt->close();

/**
 * Helpers
 */
function cleanImportValue(mixed $value): string
{
    return trim((string)$value);
}

function cleanImportHeader(mixed $headerName): string
{
    $headerName = (string)$headerName;
    $headerName = preg_replace('/^\xEF\xBB\xBF/', '', $headerName);
    $headerName = str_replace("\xEF\xBB\xBF", '', $headerName);

    return strtolower(trim(str_replace([' ', '-', '_'], '', $headerName)));
}

function findImportColumn(array $headers, array $possibleNames): ?int
{
    foreach ($possibleNames as $name) {
        $cleanName = cleanImportHeader($name);

        $index = array_search($cleanName, $headers, true);

        if ($index !== false) {
            return (int)$index;
        }
    }

    return null;
}

function getImportColumnValue(array $row, ?int $columnIndex, string $default = ''): string
{
    if ($columnIndex === null) {
        return $default;
    }

    return cleanImportValue($row[$columnIndex] ?? $default);
}

function normalizeImportBarcode(mixed $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    $value = str_replace(' ', '', $value);

    if (preg_match('/^[0-9]+(\.[0-9]+)?E\+[0-9]+$/i', $value)) {
        $value = number_format((float)$value, 0, '', '');
    }

    if (preg_match('/^\d+\.0$/', $value)) {
        $value = preg_replace('/\.0$/', '', $value);
    }

    return $value;
}

function normalizeImportQuantity(mixed $value): int
{
    $value = trim((string)$value);

    if ($value === '') {
        return 1;
    }

    $value = str_replace(',', '', $value);

    if (!is_numeric($value)) {
        return 1;
    }

    return max(1, (int)$value);
}

function normalizeImportDate(mixed $value): ?string
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $formats = [
        'Y-m-d',
        'd/m/Y',
        'm/d/Y',
        'd-m-Y',
        'm-d-Y',
        'Y/m/d',
    ];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);

        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    // Excel date serial number support
    if (is_numeric($value)) {
        $serial = (int)$value;

        if ($serial > 25000 && $serial < 90000) {
            $timestamp = ($serial - 25569) * 86400;
            return gmdate('Y-m-d', $timestamp);
        }
    }

    return null;
}

function calculateImportStatus(mysqli $conn, string $category, string $expiryDate): string
{
    $alertDays = 4;

    $stmt = $conn->prepare("
        SELECT alert_days_before
        FROM category_rules
        WHERE LOWER(category_name) = LOWER(?)
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('s', $category);
        $stmt->execute();

        $res = $stmt->get_result();
        $rule = $res->fetch_assoc();

        if ($rule) {
            $alertDays = (int)$rule['alert_days_before'];
        }

        $res->free();
        $stmt->close();
    }

    $daysLeft = (strtotime($expiryDate) - strtotime(date('Y-m-d'))) / 86400;

    if ($daysLeft < 0) {
        return 'expired';
    }

    if ($daysLeft <= $alertDays) {
        return 'near_expiry';
    }

    return 'active';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetBranchId = (int)($_POST['branch_id'] ?? 0);

    if ($userRole === 'branch_manager') {
        $targetBranchId = $myBranchId;
    }

    if ($targetBranchId <= 0) {
        $errorMessage = 'Please select a branch.';
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errorMessage = 'Please upload a valid CSV file.';
    } else {
        $fileTmpPath = $_FILES['csv_file']['tmp_name'];
        $fileName = $_FILES['csv_file']['name'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($fileExt, ['csv', 'txt'], true)) {
            $errorMessage = 'Only CSV files are allowed. Save your Excel file as CSV first.';
        } else {
            $handle = fopen($fileTmpPath, 'r');

            if (!$handle) {
                $errorMessage = 'Could not open uploaded CSV file.';
            } else {
                $header = fgetcsv($handle);

                if (!$header) {
                    $errorMessage = 'CSV file is empty or invalid.';
                } else {
                    $headers = array_map('cleanImportHeader', $header);

                    $barcodeCol = findImportColumn($headers, ['barcode', 'bar code', 'ean', 'upc']);
                    $expiryCol  = findImportColumn($headers, ['expiry_date', 'expiry date', 'expiry', 'expiration_date', 'expiration date']);
                    $qtyCol     = findImportColumn($headers, ['quantity', 'qty', 'stock', 'count']);

                    $missing = [];

                    if ($barcodeCol === null) {
                        $missing[] = 'barcode';
                    }

                    if ($expiryCol === null) {
                        $missing[] = 'expiry_date';
                    }

                    if (!empty($missing)) {
                        $errorMessage = 'Missing required columns: ' . implode(', ', $missing);
                    } else {
                        $catalogStmt = $conn->prepare("
                            SELECT
                                barcode,
                                product_name,
                                category,
                                measurement,
                                unit_price
                            FROM product_catalog
                            WHERE barcode = ?
                            LIMIT 1
                        ");

                        $insertStmt = $conn->prepare("
                            INSERT INTO products
                                (
                                    company_id,
                                    branch_id,
                                    barcode,
                                    product_name,
                                    category,
                                    quantity,
                                    unit_price,
                                    unit,
                                    expiry_date,
                                    status,
                                    entered_by,
                                    entered_on,
                                    notes
                                )
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                            ON DUPLICATE KEY UPDATE
                                quantity = quantity + VALUES(quantity),
                                unit_price = VALUES(unit_price),
                                unit = VALUES(unit),
                                status = VALUES(status),
                                entered_by = VALUES(entered_by),
                                entered_on = NOW(),
                                notes = VALUES(notes)
                        ");

                        if (!$catalogStmt || !$insertStmt) {
                            $errorMessage = 'Database error: ' . $conn->error;
                        } else {
                            $rowNumber = 1;

                            while (($row = fgetcsv($handle)) !== false) {
                                $rowNumber++;

                                if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
                                    continue;
                                }

                                $barcode = normalizeImportBarcode(getImportColumnValue($row, $barcodeCol));
                                $expiryDate = normalizeImportDate(getImportColumnValue($row, $expiryCol));
                                $quantity = normalizeImportQuantity(getImportColumnValue($row, $qtyCol, '1'));

                                if ($barcode === '') {
                                    $skippedRows[] = "Row $rowNumber skipped: barcode is empty.";
                                    continue;
                                }

                                if ($expiryDate === null) {
                                    $skippedRows[] = "Row $rowNumber skipped: invalid expiry date.";
                                    continue;
                                }

                                $catalogStmt->bind_param('s', $barcode);
                                $catalogStmt->execute();

                                $catalogResult = $catalogStmt->get_result();
                                $catalog = $catalogResult->fetch_assoc();
                                $catalogResult->free();

                                if (!$catalog) {
                                    $skippedRows[] = "Row $rowNumber skipped: barcode $barcode was not found in product catalog.";
                                    continue;
                                }

                                $productName = $catalog['product_name'];
                                $category = $catalog['category'];
                                $unit = $catalog['measurement'];
                                $unitPrice = $catalog['unit_price'] !== null ? (float)$catalog['unit_price'] : 0.00;
                                $status = calculateImportStatus($conn, $category, $expiryDate);
                                $notes = 'Imported from catalog CSV';

                                $beforeAffected = 0;

                                $insertStmt->bind_param(
                                    'iisssidsssis',
                                    $myCompanyId,
                                    $targetBranchId,
                                    $barcode,
                                    $productName,
                                    $category,
                                    $quantity,
                                    $unitPrice,
                                    $unit,
                                    $expiryDate,
                                    $status,
                                    $myUserId,
                                    $notes
                                );

                                if (!$insertStmt->execute()) {
                                    $skippedRows[] = "Row $rowNumber skipped: database insert failed for barcode $barcode.";
                                    continue;
                                }

                                if ($insertStmt->affected_rows === 1) {
                                    $importedCount++;
                                } elseif ($insertStmt->affected_rows === 2) {
                                    $updatedCount++;
                                } else {
                                    $updatedCount++;
                                }
                            }

                            $catalogStmt->close();
                            $insertStmt->close();

                            $successMessage = "Import finished. Added: $importedCount. Updated existing batches: $updatedCount. Skipped: " . count($skippedRows) . ".";
                        }
                    }
                }

                fclose($handle);
            }
        }
    }
}
?>

<div class="page-header">
  <div class="page-header-text">
    <h1>Import Products From Catalog</h1>
    <p>Upload a CSV with barcode and expiry date. Product details will be filled from the master catalog.</p>
  </div>

  <a href="data:text/csv;charset=utf-8,barcode,expiry_date,quantity%0A5281080000000,2026-05-10,5%0A5281080000000,2026-06-20,8"
     download="import_products_from_catalog_template.csv"
     class="btn-eg btn-ghost-eg btn-sm-eg">
    <i class="bi bi-download"></i> Download Template
  </a>
</div>

<?php if ($successMessage): ?>
  <div class="alert alert-success">
    <?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?>
  </div>
<?php endif; ?>

<?php if ($errorMessage): ?>
  <div class="alert alert-danger">
    <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
  </div>
<?php endif; ?>

<div class="eg-card" style="margin-bottom:16px">
  <div class="eg-card-header">
    <span class="eg-card-title">
      <i class="bi bi-info-circle me-2"></i>How this import works
    </span>
  </div>

  <div class="eg-card-body">
    <p style="margin-bottom:10px;color:var(--text-muted)">
      Your CSV only needs barcode, expiry_date, and optional quantity.
      The importer will search the barcode in <strong>product_catalog</strong> and fill the product name, category, measurement, and price automatically.
    </p>

    <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px">
      <code>barcode,expiry_date,quantity</code><br>
      <code>5281080000000,2026-05-10,5</code><br>
      <code>5281080000000,2026-06-20,8</code>
    </div>

    <p style="margin-top:10px;margin-bottom:0;color:var(--text-muted);font-size:.85rem">
      Same barcode with different expiry dates is allowed. Same barcode with the same expiry date in the same branch will update quantity instead of creating a duplicate.
    </p>
  </div>
</div>

<div class="eg-card">
  <div class="eg-card-header">
    <span class="eg-card-title">
      <i class="bi bi-cloud-upload me-2"></i>Upload CSV
    </span>
  </div>

  <div class="eg-card-body">
    <form method="POST" enctype="multipart/form-data">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;align-items:end">

        <div>
          <label class="eg-label">Branch</label>
          <select name="branch_id" class="branch-select" required <?= $userRole === 'branch_manager' ? 'disabled' : '' ?>>
            <option value="">Select branch</option>
            <?php foreach ($branchList as $branch): ?>
              <option value="<?= (int)$branch['id'] ?>" <?= $userRole === 'branch_manager' && (int)$branch['id'] === $myBranchId ? 'selected' : '' ?>>
                <?= htmlspecialchars($branch['branch_name'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>

          <?php if ($userRole === 'branch_manager'): ?>
            <input type="hidden" name="branch_id" value="<?= (int)$myBranchId ?>">
          <?php endif; ?>
        </div>

        <div>
          <label class="eg-label">CSV File</label>
          <input type="file" name="csv_file" accept=".csv,.txt" class="form-control" required>
        </div>

        <div>
          <button type="submit" class="btn-eg btn-primary-eg">
            <i class="bi bi-upload"></i> Import Products
          </button>
        </div>

      </div>
    </form>
  </div>
</div>

<?php if (!empty($skippedRows)): ?>
  <div class="eg-card" style="margin-top:16px">
    <div class="eg-card-header">
      <span class="eg-card-title">
        <i class="bi bi-exclamation-triangle me-2"></i>Skipped Rows
      </span>
    </div>

    <div class="eg-card-body">
      <div style="max-height:280px;overflow:auto">
        <?php foreach ($skippedRows as $row): ?>
          <div style="padding:8px 10px;border-bottom:1px solid var(--border);font-size:.85rem;color:#991b1b">
            <?= htmlspecialchars($row, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php include 'layout_bottom.php'; ?>