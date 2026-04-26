<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

$apiUser = resolveApiUser($conn);
$barcode = trim($_GET['barcode'] ?? '');

if ($barcode === '') {
    jsonResponse(false, 'Barcode is required', null, 400);
}

// Scope lookup to user's company for data isolation
$stmt = $conn->prepare("
    SELECT product_name, category, quantity, unit
    FROM products
    WHERE barcode = ? AND company_id = ?
    ORDER BY entered_on DESC
    LIMIT 1
");
$stmt->bind_param('si', $barcode, $apiUser['company_id']);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if ($product) {
    jsonResponse(true, 'Product found', $product);
}

jsonResponse(false, 'Barcode not found', null, 404);