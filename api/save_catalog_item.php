<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

// Only admin roles can add, edit, or delete catalog items
$apiUser = resolveAdminApiUser($conn);

$action = trim($_POST['action'] ?? 'save');
$id = (int)($_POST['id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Delete catalog item
|--------------------------------------------------------------------------
*/
if ($action === 'delete') {
    if ($id <= 0) {
        jsonResponse(false, 'Invalid catalog item ID', null, 400);
    }

    $stmt = $conn->prepare("DELETE FROM product_catalog WHERE id = ? LIMIT 1");

    if (!$stmt) {
        jsonResponse(false, 'Database error: ' . $conn->error, null, 500);
    }

    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        jsonResponse(false, 'Failed to delete catalog item: ' . $stmt->error, null, 500);
    }

    $stmt->close();

    jsonResponse(true, 'Catalog item deleted', ['id' => $id]);
}

/*
|--------------------------------------------------------------------------
| Add / Edit catalog item
|--------------------------------------------------------------------------
| Current product_catalog fields:
| id, item_id, barcode, product_name, category, measurement,
| unit_price, stock_level, supplier, image_url
|--------------------------------------------------------------------------
*/

function normalizeBarcodeForCatalog($value): string {
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

$itemId      = trim($_POST['item_id'] ?? '');
$barcode     = normalizeBarcodeForCatalog($_POST['barcode'] ?? '');
$productName = trim($_POST['product_name'] ?? '');
$category    = trim($_POST['category'] ?? '');

// Accept both measurement and unit, in case old form/app sends unit
$measurement = trim($_POST['measurement'] ?? ($_POST['unit'] ?? ''));

$unitPrice = 0.00;
if (isset($_POST['unit_price']) && trim((string)$_POST['unit_price']) !== '') {
    $unitPrice = round((float)$_POST['unit_price'], 2);
}

$stockLevel = 0;
if (isset($_POST['stock_level']) && trim((string)$_POST['stock_level']) !== '') {
    $stockLevel = (int)$_POST['stock_level'];
}

$supplier = trim($_POST['supplier'] ?? '');
$imageUrl = trim($_POST['image_url'] ?? '');

$itemId = $itemId !== '' ? $itemId : null;
$imageUrl = $imageUrl !== '' ? $imageUrl : null;

if ($barcode === '' || $productName === '') {
    jsonResponse(false, 'Barcode and product name are required', null, 400);
}

if ($id > 0) {
    /*
    |--------------------------------------------------------------------------
    | Update existing catalog item
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare("
        UPDATE product_catalog
        SET
            item_id = ?,
            barcode = ?,
            product_name = ?,
            category = ?,
            measurement = ?,
            unit_price = ?,
            stock_level = ?,
            supplier = ?,
            image_url = ?
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        jsonResponse(false, 'Database error: ' . $conn->error, null, 500);
    }

    $stmt->bind_param(
        'sssssdissi',
        $itemId,
        $barcode,
        $productName,
        $category,
        $measurement,
        $unitPrice,
        $stockLevel,
        $supplier,
        $imageUrl,
        $id
    );

    if (!$stmt->execute()) {
        jsonResponse(false, 'Failed to update catalog item: ' . $stmt->error, null, 500);
    }

    $stmt->close();

    jsonResponse(true, 'Catalog item updated', ['id' => $id]);
}

/*
|--------------------------------------------------------------------------
| Insert new catalog item
|--------------------------------------------------------------------------
| If barcode already exists, update the existing record.
|--------------------------------------------------------------------------
*/
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
    jsonResponse(false, 'Database error: ' . $conn->error, null, 500);
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

if (!$stmt->execute()) {
    jsonResponse(false, 'Failed to save catalog item: ' . $stmt->error, null, 500);
}

$newId = (int)$stmt->insert_id;
$stmt->close();

if ($newId <= 0) {
    $findStmt = $conn->prepare("SELECT id FROM product_catalog WHERE barcode = ? LIMIT 1");
    if ($findStmt) {
        $findStmt->bind_param('s', $barcode);
        $findStmt->execute();
        $findRes = $findStmt->get_result();
        $row = $findRes->fetch_assoc();
        $newId = (int)($row['id'] ?? 0);
        $findRes->free();
        $findStmt->close();
    }
}

jsonResponse(true, 'Catalog item saved', ['id' => $newId]);