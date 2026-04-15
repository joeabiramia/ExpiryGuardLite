<?php
require_once '../config/db.php';
require_once '../config/helpers.php';

$barcode = trim($_GET['barcode'] ?? '');

if ($barcode === '') {
    jsonResponse(false, 'Barcode is required');
}

$stmt = $conn->prepare("
    SELECT product_name, category
    FROM products
    WHERE barcode = ?
    ORDER BY entered_on DESC
    LIMIT 1
");
$stmt->bind_param("s", $barcode);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if ($product) {
    jsonResponse(true, 'Product found', $product);
}

jsonResponse(false, 'Barcode not found');