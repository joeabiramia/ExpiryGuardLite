<?php
require_once '../config/db.php';
require_once '../config/helpers.php';

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
$check->bind_param("ss", $barcode, $expiry_date);
$check->execute();
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
$ruleStmt->bind_param("s", $category);
$ruleStmt->execute();
$rule = $ruleStmt->get_result()->fetch_assoc();

$alertDays = $rule ? (int)$rule['alert_days_before'] : 4;

$status = 'active';

if ($daysLeft < 0) {
    $status = 'expired';
} elseif ($daysLeft <= $alertDays) {
    $status = 'near_expiry';
}

$stmt = $conn->prepare("
    INSERT INTO products
    (barcode, product_name, category, expiry_date, status, entered_by, entered_on)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
");

$stmt->bind_param(
    'sssssi',
    $barcode,
    $product_name,
    $category,
    $expiry_date,
    $status,
    $entered_by
);

if ($stmt->execute()) {
    jsonResponse(true, 'Product saved successfully', [
        'product_id' => $stmt->insert_id,
        'status' => $status
    ]);
}

jsonResponse(false, 'Failed to save product');