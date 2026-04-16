<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(0);

require_once '../config/helpers.php';
require_once '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

$barcode = trim($_POST['barcode'] ?? '');
$product_name = trim($_POST['product_name'] ?? '');
$category = trim($_POST['category'] ?? '');
$expiry_date = trim($_POST['expiry_date'] ?? '');
$entered_by = (int)($_POST['entered_by'] ?? 0);

if (
    $barcode === '' ||
    $product_name === '' ||
    $category === '' ||
    $expiry_date === '' ||
    $entered_by <= 0
) {
    jsonResponse(false, 'Barcode, product name, category, expiry date, and entered_by are required');
}

$check = $conn->prepare("
    SELECT id
    FROM products
    WHERE barcode = ? AND expiry_date = ?
    LIMIT 1
");
if (!$check) {
    jsonResponse(false, 'Database error', ['detail' => $conn->error]);
}

$check->bind_param("ss", $barcode, $expiry_date);
if (!$check->execute()) {
    jsonResponse(false, 'Database execution error', ['detail' => $check->error]);
}

$existing = $check->get_result()->fetch_assoc();
if ($existing) {
    jsonResponse(false, 'This product batch already exists');
}

$currentDate = date('Y-m-d');
$daysLeft = (strtotime($expiry_date) - strtotime($currentDate)) / 86400;

$ruleStmt = $conn->prepare("
    SELECT alert_days_before
    FROM category_rules
    WHERE category_name = ?
    LIMIT 1
");
if (!$ruleStmt) {
    jsonResponse(false, 'Database error', ['detail' => $conn->error]);
}

$ruleStmt->bind_param("s", $category);
if (!$ruleStmt->execute()) {
    jsonResponse(false, 'Database execution error', ['detail' => $ruleStmt->error]);
}

$rule = $ruleStmt->get_result()->fetch_assoc();
$alertDays = $rule ? (int)$rule['alert_days_before'] : 4;

if ($daysLeft < 0) {
    $status = 'expired';
} elseif ($daysLeft <= $alertDays) {
    $status = 'near_expiry';
} else {
    $status = 'active';
}

$stmt = $conn->prepare("
    INSERT INTO products
    (barcode, product_name, category, expiry_date, status, entered_by, entered_on)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
");
if (!$stmt) {
    jsonResponse(false, 'Database error', ['detail' => $conn->error]);
}

$stmt->bind_param(
    'sssssi',
    $barcode,
    $product_name,
    $category,
    $expiry_date,
    $status,
    $entered_by
);

if (!$stmt->execute()) {
    jsonResponse(false, 'Failed to save product', ['detail' => $stmt->error]);
}

jsonResponse(true, 'Product saved successfully', [
    'product_id' => $stmt->insert_id,
    'status' => $status
]);
