<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_auth.php';

addSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

$apiUser = resolveApiUser($conn);

if ($apiUser['role'] === 'viewer') {
    jsonResponse(false, 'Access denied — viewers cannot add products', null, 403);
}

$barcode      = trim($_POST['barcode'] ?? '');
$product_name = trim($_POST['product_name'] ?? '');
$category     = trim($_POST['category'] ?? '');
$expiry_date  = trim($_POST['expiry_date'] ?? '');
$quantity     = max(1, (int)($_POST['quantity'] ?? 1));
$unit         = trim($_POST['unit'] ?? '');
$notes        = trim($_POST['notes'] ?? '');

if ($barcode === '' || $product_name === '' || $category === '' || $expiry_date === '') {
    jsonResponse(false, 'Barcode, product name, category, and expiry date are required', null, 400);
}

// Validate expiry date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_date)) {
    jsonResponse(false, 'Invalid expiry date format. Use YYYY-MM-DD', null, 400);
}

// Enforce company and branch scope from authenticated user
$entered_by = (int)$apiUser['id'];
$company_id = (int)$apiUser['company_id'];
$branch_id  = (int)$apiUser['branch_id'];

// super_admin / company_admin may specify a branch explicitly
if (in_array($apiUser['role'], ['super_admin', 'company_admin'], true)) {
    $posted_company = (int)($_POST['company_id'] ?? 0);
    $posted_branch  = (int)($_POST['branch_id']  ?? 0);
    if ($posted_company > 0) $company_id = $posted_company;
    if ($posted_branch  > 0) $branch_id  = $posted_branch;
}

if ($branch_id <= 0) {
    jsonResponse(false, 'Branch is required to save a product', null, 400);
}

// Duplicate check (same branch + barcode + expiry)
$check = $conn->prepare("
    SELECT id FROM products
    WHERE branch_id = ? AND barcode = ? AND expiry_date = ?
    LIMIT 1
");
$check->bind_param('iss', $branch_id, $barcode, $expiry_date);
$check->execute();
$checkRes    = $check->get_result();
$checkExists = $checkRes->fetch_assoc();
$checkRes->free();
$check->close();
if ($checkExists) {
    jsonResponse(false, 'This product batch already exists in this branch');
}

// Determine status based on category alert rule
$daysLeft = (strtotime($expiry_date) - strtotime(date('Y-m-d'))) / 86400;

$ruleStmt = $conn->prepare("
    SELECT alert_days_before FROM category_rules WHERE category_name = ? LIMIT 1
");
$ruleStmt->bind_param('s', $category);
$ruleStmt->execute();
$ruleRes   = $ruleStmt->get_result();
$rule      = $ruleRes->fetch_assoc();
$alertDays = $rule ? (int)$rule['alert_days_before'] : 4;
$ruleRes->free();
$ruleStmt->close();

if ($daysLeft < 0) {
    $status = 'expired';
} elseif ($daysLeft <= $alertDays) {
    $status = 'near_expiry';
} else {
    $status = 'active';
}

$stmt = $conn->prepare("
    INSERT INTO products
        (company_id, branch_id, barcode, product_name, category, quantity, unit,
         expiry_date, status, entered_by, entered_on, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
");

if (!$stmt) {
    jsonResponse(false, 'Database error', null, 500);
}

$stmt->bind_param(
    'iisssssisss',
    $company_id,
    $branch_id,
    $barcode,
    $product_name,
    $category,
    $quantity,
    $unit,
    $expiry_date,
    $status,
    $entered_by,
    $notes
);

if (!$stmt->execute()) {
    jsonResponse(false, 'Failed to save product', null, 500);
}

$productId = $stmt->insert_id;

logActivity(
    $conn, $company_id, $branch_id, $entered_by,
    'create_product', 'products', $productId,
    "Added product: $product_name (barcode: $barcode)"
);

jsonResponse(true, 'Product saved successfully', [
    'product_id' => $productId,
    'status'     => $status,
]);