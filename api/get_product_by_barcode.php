<?php
ini_set('display_errors', '0');
error_reporting(0);

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
        'data' => new stdClass()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_auth.php';

addSecurityHeaders();

$apiUser = resolveApiUser($conn);

function normalizeBarcodeLookup($value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    // Remove spaces
    $value = str_replace(' ', '', $value);

    // Convert Excel scientific notation, example: 5.28108E+12
    if (preg_match('/^[0-9]+(\.[0-9]+)?E\+[0-9]+$/i', $value)) {
        $value = number_format((float)$value, 0, '', '');
    }

    // Remove trailing .0, example: 5281080000000.0
    if (preg_match('/^\d+\.0$/', $value)) {
        $value = preg_replace('/\.0$/', '', $value);
    }

    return $value;
}

$barcode = normalizeBarcodeLookup($_GET['barcode'] ?? '');

if ($barcode === '') {
    jsonResponse(false, 'Barcode is required', null, 400);
}

/*
|--------------------------------------------------------------------------
| 1. Check master product catalog first
|--------------------------------------------------------------------------
| product_catalog columns:
| id, item_id, barcode, product_name, category, measurement,
| unit_price, stock_level, supplier, image_url
|--------------------------------------------------------------------------
*/
$cStmt = $conn->prepare("
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
    WHERE barcode = ?
    LIMIT 1
");

if (!$cStmt) {
    jsonResponse(false, 'Database error: ' . $conn->error, null, 500);
}

$cStmt->bind_param('s', $barcode);
$cStmt->execute();

$cRes = $cStmt->get_result();
$catalog = $cRes->fetch_assoc();

$cRes->free();
$cStmt->close();

if ($catalog) {
    jsonResponse(true, 'Product found in catalog', [
        'source'       => 'catalog',
        'id'           => (int)$catalog['id'],
        'item_id'      => $catalog['item_id'],
        'barcode'      => $catalog['barcode'],
        'product_name' => $catalog['product_name'],
        'category'     => $catalog['category'],
        'measurement'  => $catalog['measurement'],

        // Keep this for Android compatibility if the app still expects "unit"
        'unit'         => $catalog['measurement'],

        'unit_price'   => $catalog['unit_price'] !== null ? (float)$catalog['unit_price'] : 0.00,
        'stock_level'  => $catalog['stock_level'] !== null ? (int)$catalog['stock_level'] : 0,
        'supplier'     => $catalog['supplier'],
        'image_url'    => $catalog['image_url'],
    ]);
}

/*
|--------------------------------------------------------------------------
| 2. Fallback: check previous scanned products in same company
|--------------------------------------------------------------------------
*/
$hStmt = $conn->prepare("
    SELECT
        barcode,
        product_name,
        category,
        unit,
        unit_price
    FROM products
    WHERE barcode = ?
      AND company_id = ?
    ORDER BY entered_on DESC
    LIMIT 1
");

if (!$hStmt) {
    jsonResponse(false, 'Database error: ' . $conn->error, null, 500);
}

$companyId = (int)$apiUser['company_id'];

$hStmt->bind_param('si', $barcode, $companyId);
$hStmt->execute();

$hRes = $hStmt->get_result();
$history = $hRes->fetch_assoc();

$hRes->free();
$hStmt->close();

if ($history) {
    jsonResponse(true, 'Product found in history', [
        'source'       => 'history',
        'barcode'      => $history['barcode'],
        'product_name' => $history['product_name'],
        'category'     => $history['category'],
        'measurement'  => $history['unit'],
        'unit'         => $history['unit'],
        'unit_price'   => $history['unit_price'] !== null ? (float)$history['unit_price'] : 0.00,
        'stock_level'  => 0,
        'supplier'     => null,
        'image_url'    => null,
    ]);
}

jsonResponse(false, 'Barcode not found', null, 404);