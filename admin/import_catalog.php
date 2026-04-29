<?php
include 'layout_top.php';

$userRole ??= 'viewer';

$_impCatUid = (int)($_SESSION['user_id'] ?? 0);
if (!in_array($userRole, ['super_admin', 'company_admin', 'branch_manager'], true)
    && !userHasPermission($conn, $_impCatUid, 'manage_products')) {
    header('Location: dashboard.php');
    exit();
}

$successMessage = '';
$errorMessage = '';
$importedCount = 0;
$updatedCount = 0;
$skippedRows = [];

function cleanCsvValue($value) {
    return trim((string)$value);
}

function cleanHeaderName($headerName) {
    $headerName = (string)$headerName;

    // Remove UTF-8 BOM if Excel added it
    $headerName = preg_replace('/^\xEF\xBB\xBF/', '', $headerName);
    $headerName = str_replace("\xEF\xBB\xBF", '', $headerName);

    return strtolower(trim($headerName));
}

function getColumnValue($row, $columnIndex, $possibleNames) {
    foreach ($possibleNames as $name) {
        if (isset($columnIndex[$name])) {
            return cleanCsvValue($row[$columnIndex[$name]] ?? '');
        }
    }

    return '';
}

function hasAnyColumn($columnIndex, $possibleNames) {
    foreach ($possibleNames as $name) {
        if (isset($columnIndex[$name])) {
            return true;
        }
    }

    return false;
}

function normalizeBarcode($value) {
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    // Remove spaces
    $value = str_replace(' ', '', $value);

    // If Excel saved barcode as scientific notation, example: 5.28108E+12
    if (preg_match('/^[0-9]+(\.[0-9]+)?E\+[0-9]+$/i', $value)) {
        $value = number_format((float)$value, 0, '', '');
    }

    // Remove trailing .0 if Excel exported it like 5281080000000.0
    if (preg_match('/^\d+\.0$/', $value)) {
        $value = preg_replace('/\.0$/', '', $value);
    }

    return $value;
}

function normalizeNumber($value, $default = 0) {
    $value = trim((string)$value);

    if ($value === '') {
        return $default;
    }

    $value = str_replace(',', '', $value);

    if (!is_numeric($value)) {
        return null;
    }

    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errorMessage = 'Please upload a valid CSV file.';
    } else {
        $fileTmpPath = $_FILES['csv_file']['tmp_name'];
        $fileName = $_FILES['csv_file']['name'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileExt !== 'csv') {
            $errorMessage = 'Only CSV files are allowed.';
        } else {
            $handle = fopen($fileTmpPath, 'r');

            if (!$handle) {
                $errorMessage = 'Could not open uploaded CSV file.';
            } else {
                $header = fgetcsv($handle);

                if (!$header) {
                    $errorMessage = 'CSV file is empty or invalid.';
                } else {
                    $header = array_map('cleanHeaderName', $header);
                    $columnIndex = array_flip($header);

                    /*
                        Accepted headers:

                        Recommended:
                        item_id, barcode, product_name, category, measurement, unit_price, stock_level, supplier

                        Also accepted from cleaned Excel:
                        item_id, barcode, item_name, category_name, measurement_value, unit_price, stock_level, supplier_name

                        Optional:
                        image_url
                    */

                    $missingColumns = [];

                    if (!hasAnyColumn($columnIndex, ['barcode'])) {
                        $missingColumns[] = 'barcode';
                    }

                    if (!hasAnyColumn($columnIndex, ['product_name', 'item_name'])) {
                        $missingColumns[] = 'product_name or item_name';
                    }

                    if (!hasAnyColumn($columnIndex, ['category', 'category_name'])) {
                        $missingColumns[] = 'category or category_name';
                    }

                    if (!hasAnyColumn($columnIndex, ['measurement', 'measurement_value'])) {
                        $missingColumns[] = 'measurement or measurement_value';
                    }

                    if (!hasAnyColumn($columnIndex, ['unit_price'])) {
                        $missingColumns[] = 'unit_price';
                    }

                    if (!hasAnyColumn($columnIndex, ['stock_level'])) {
                        $missingColumns[] = 'stock_level';
                    }

                    if (!hasAnyColumn($columnIndex, ['supplier', 'supplier_name'])) {
                        $missingColumns[] = 'supplier or supplier_name';
                    }

                    if (!empty($missingColumns)) {
                        $errorMessage = 'Missing columns: ' . implode(', ', $missingColumns);
                    } else {
                        $stmt = $conn->prepare("
                            INSERT INTO product_catalog
                                (
                                    item_id,
                                    barcode,
                                    product_name,
                                    category,
                                    measurement,
                                    unit_price,
                                    stock_level,
                                    supplier,
                                    image_url
                                )
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                item_id = VALUES(item_id),
                                product_name = VALUES(product_name),
                                category = VALUES(category),
                                measurement = VALUES(measurement),
                                unit_price = VALUES(unit_price),
                                stock_level = VALUES(stock_level),
                                supplier = VALUES(supplier),
                                image_url = VALUES(image_url)
                        ");

                        if (!$stmt) {
                            $errorMessage = 'Database error: ' . $conn->error;
                        } else {
                            $rowNumber = 1;

                            while (($row = fgetcsv($handle)) !== false) {
                                $rowNumber++;

                                $itemId = getColumnValue($row, $columnIndex, ['item_id', 'id', 'item_code', 'item code']);
                                $barcode = normalizeBarcode(getColumnValue($row, $columnIndex, ['barcode']));
                                $productName = getColumnValue($row, $columnIndex, ['product_name', 'item_name']);
                                $category = getColumnValue($row, $columnIndex, ['category', 'category_name']);
                                $measurement = getColumnValue($row, $columnIndex, ['measurement', 'measurement_value']);
                                $unitPriceRaw = getColumnValue($row, $columnIndex, ['unit_price']);
                                $stockLevelRaw = getColumnValue($row, $columnIndex, ['stock_level']);
                                $supplier = getColumnValue($row, $columnIndex, ['supplier', 'supplier_name']);

                                $imageUrl = null;

                                if (isset($columnIndex['image_url'])) {
                                    $imageUrlValue = cleanCsvValue($row[$columnIndex['image_url']] ?? '');
                                    $imageUrl = $imageUrlValue !== '' ? $imageUrlValue : null;
                                }

                                if ($productName === '') {
                                    $skippedRows[] = "Row $rowNumber skipped: product name is empty.";
                                    continue;
                                }

                                if ($barcode === '') {
                                    $skippedRows[] = "Row $rowNumber skipped: barcode is empty.";
                                    continue;
                                }

                                $itemId = $itemId !== '' ? $itemId : null;

                                $unitPriceParsed = normalizeNumber($unitPriceRaw, 0);

                                if ($unitPriceParsed === null) {
                                    $skippedRows[] = "Row $rowNumber skipped: unit_price is not a valid number.";
                                    continue;
                                }

                                $unitPrice = round((float)$unitPriceParsed, 2);

                                $stockLevelParsed = normalizeNumber($stockLevelRaw, 0);

                                if ($stockLevelParsed === null) {
                                    $skippedRows[] = "Row $rowNumber skipped: stock_level is not a valid number.";
                                    continue;
                                }

                                $stockLevel = (int)$stockLevelParsed;

                                $exists = false;

                                if ($barcode !== '') {
                                    $checkStmt = $conn->prepare("SELECT id FROM product_catalog WHERE barcode = ? LIMIT 1");
                                    $checkStmt->bind_param('s', $barcode);
                                    $checkStmt->execute();
                                    $checkResult = $checkStmt->get_result();
                                    $exists = $checkResult->num_rows > 0;
                                    $checkResult->free();
                                    $checkStmt->close();
                                }

                                $stmt->bind_param(
                                    'sssssdiss',
                                    $itemId,
                                    $barcode,
                                    $productName,
                                    $category,
                                    $measurement,
                                    $unitPrice,
                                    $stockLevel,
                                    $supplier,
                                    $imageUrl
                                );

                                if ($stmt->execute()) {
                                    if ($exists) {
                                        $updatedCount++;
                                    } else {
                                        $importedCount++;
                                    }
                                } else {
                                    $skippedRows[] = "Row $rowNumber skipped: " . $stmt->error;
                                }
                            }

                            $stmt->close();

                            if ($importedCount > 0 || $updatedCount > 0) {
                                $successMessage = "Import completed. New items: $importedCount. Updated items: $updatedCount.";
                            } else {
                                $errorMessage = 'No rows were imported.';
                            }
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
    <h1>Import Product Catalog</h1>
    <p>Upload a CSV file to fill or update the product_catalog table.</p>
  </div>

  <a href="catalog.php" class="btn-eg btn-ghost-eg">
    <i class="bi bi-arrow-left"></i> Back to Catalog
  </a>
</div>

<div class="eg-card">
  <div class="eg-card-header">
    <span class="eg-card-title">
      <i class="bi bi-cloud-upload me-2"></i>CSV Import
    </span>
  </div>

  <div class="eg-card-body">

    <?php if ($successMessage): ?>
      <div style="background:var(--green-light);color:var(--green);padding:12px 14px;border-radius:10px;margin-bottom:16px;font-weight:600">
        <?=htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8')?>
      </div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
      <div style="background:var(--red-light);color:var(--red);padding:12px 14px;border-radius:10px;margin-bottom:16px;font-weight:600">
        <?=htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8')?>
      </div>
    <?php endif; ?>

    <div style="background:var(--blue-light);color:#1d4ed8;border-radius:10px;padding:14px;margin-bottom:18px;font-size:.85rem">
      <strong>Required CSV header:</strong>
      <br>
      <code>barcode,product_name,category,measurement,unit_price,stock_level,supplier</code>

      <br><br>

      <strong>Also accepted from your cleaned Excel:</strong>
      <br>
      <code>barcode,item_name,category_name,measurement_value,unit_price,stock_level,supplier_name</code>

      <br><br>

      <strong>Optional:</strong>
      <br>
      <code>item_id,image_url</code>

      <br><br>

      <strong>Important:</strong>
      <br>
      Barcode is saved as text. If Excel changed it to scientific notation, this importer tries to convert it back.
    </div>

    <form method="POST" enctype="multipart/form-data">
      <div class="form-group">
        <label class="eg-label">Choose CSV File</label>
        <input type="file" name="csv_file" class="eg-input" accept=".csv" required>
      </div>

      <button type="submit" class="btn-eg btn-primary-eg">
        <i class="bi bi-upload"></i> Import CSV
      </button>
    </form>

    <?php if (!empty($skippedRows)): ?>
      <div style="margin-top:24px">
        <h3 style="font-size:1rem;margin-bottom:10px">Skipped Rows</h3>

        <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px;max-height:250px;overflow:auto">
          <?php foreach ($skippedRows as $skip): ?>
            <div style="font-size:.82rem;color:var(--text-muted);margin-bottom:6px">
              <?=htmlspecialchars($skip, ENT_QUOTES, 'UTF-8')?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<?php include 'layout_bottom.php'; ?>