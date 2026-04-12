<?php
require_once '../config/db.php';
require_once '../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

$barcode = trim($_POST['barcode'] ?? '');
$product_name = trim($_POST['product_name'] ?? '');
$expiry_date = trim($_POST['expiry_date'] ?? '');
$entered_by = (int)($_POST['entered_by'] ?? 0);

if ($barcode === '' || $product_name === '' || $expiry_date === '' || $entered_by <= 0) {
    jsonResponse(false, 'Barcode, product name, expiry date, and entered_by are required');
}

$currentDate = date('Y-m-d');
$daysLeft = (strtotime($expiry_date) - strtotime($currentDate)) / 86400;

$status = 'active';
if ($daysLeft < 0) {
    $status = 'expired';
} elseif ($daysLeft <= 4) {
    $status = 'near_expiry';
}

$stmt = $conn->prepare('INSERT INTO products (barcode, product_name, expiry_date, status, entered_by, entered_on) VALUES (?, ?, ?, ?, ?, NOW())');
$stmt->bind_param('ssssi', $barcode, $product_name, $expiry_date, $status, $entered_by);

if ($stmt->execute()) {
    jsonResponse(true, 'Product saved successfully', [
        'product_id' => $stmt->insert_id,
        'status' => $status
    ]);
}

jsonResponse(false, 'Failed to save product');

