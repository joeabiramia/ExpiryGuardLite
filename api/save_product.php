<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_auth.php';

if (function_exists('addSecurityHeaders')) {
    addSecurityHeaders();
} else {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

$apiUser = resolveApiUser($conn);

if (($apiUser['role'] ?? 'viewer') === 'viewer') {
    jsonResponse(false, 'Access denied — viewers cannot add products', null, 403);
}

$barcode      = trim((string)($_POST['barcode'] ?? ''));
$product_name = trim((string)($_POST['product_name'] ?? ''));
$category     = trim((string)($_POST['category'] ?? ''));
$expiry_date  = trim((string)($_POST['expiry_date'] ?? ''));
$quantity     = max(1, (int)($_POST['quantity'] ?? 1));
$unit         = trim((string)($_POST['unit'] ?? ''));
$notes        = trim((string)($_POST['notes'] ?? ''));

$unit_price = isset($_POST['unit_price']) && $_POST['unit_price'] !== ''
    ? round((float)$_POST['unit_price'], 2)
    : null;

if ($barcode === '' || $product_name === '' || $category === '' || $expiry_date === '') {
    jsonResponse(false, 'Barcode, product name, category, and expiry date are required', null, 400);
}

/*
|--------------------------------------------------------------------------
| Mandatory mobile dropdown validation
|--------------------------------------------------------------------------
| Mobile app must send one of:
| Receiving, Transfers, Daily Check
|--------------------------------------------------------------------------
*/
$allowedNotes = ['Receiving', 'Transfers', 'Daily Check'];

if ($notes === '' || !in_array($notes, $allowedNotes, true)) {
    jsonResponse(false, 'Please select a valid entry type.', null, 400);
}

// Validate expiry date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_date)) {
    jsonResponse(false, 'Invalid expiry date format. Use YYYY-MM-DD', null, 400);
}

// Validate real date
$dateParts = explode('-', $expiry_date);
if (
    count($dateParts) !== 3 ||
    !checkdate((int)$dateParts[1], (int)$dateParts[2], (int)$dateParts[0])
) {
    jsonResponse(false, 'Invalid expiry date.', null, 400);
}

// Enforce company and branch scope from authenticated user
$entered_by = (int)$apiUser['id'];
$company_id = (int)$apiUser['company_id'];
$branch_id  = (int)$apiUser['branch_id'];

// super_admin / company_admin may specify a branch explicitly
if (in_array($apiUser['role'], ['super_admin', 'company_admin'], true)) {
    $posted_company = (int)($_POST['company_id'] ?? 0);
    $posted_branch  = (int)($_POST['branch_id']  ?? 0);

    if ($posted_company > 0) {
        $company_id = $posted_company;
    }

    if ($posted_branch > 0) {
        $branch_id = $posted_branch;
    }
}

if ($branch_id <= 0) {
    jsonResponse(false, 'Branch is required to save a product', null, 400);
}

// Duplicate check: same branch + barcode + expiry
$check = $conn->prepare("
    SELECT id
    FROM products
    WHERE branch_id = ?
      AND barcode = ?
      AND expiry_date = ?
    LIMIT 1
");

if (!$check) {
    jsonResponse(false, 'Database error: ' . $conn->error, null, 500);
}

$check->bind_param('iss', $branch_id, $barcode, $expiry_date);
$check->execute();

$checkRes    = $check->get_result();
$checkExists = $checkRes->fetch_assoc();

$checkRes->free();
$check->close();

if ($checkExists) {
    jsonResponse(false, 'This product batch already exists in this branch', null, 409);
}

// Determine status based on category alert rule
$daysLeft = (strtotime($expiry_date) - strtotime(date('Y-m-d'))) / 86400;

$ruleStmt = $conn->prepare("
    SELECT alert_days_before, auto_remove_days_before
    FROM category_rules
    WHERE category_name = ?
    LIMIT 1
");

if (!$ruleStmt) {
    jsonResponse(false, 'Database error: ' . $conn->error, null, 500);
}

$ruleStmt->bind_param('s', $category);
$ruleStmt->execute();

$ruleRes = $ruleStmt->get_result();
$rule = $ruleRes->fetch_assoc();

$alertDays = $rule ? (int)$rule['alert_days_before'] : 4;
$autoRemoveDays = $rule ? (int)$rule['auto_remove_days_before'] : 0;

$ruleRes->free();
$ruleStmt->close();

// auto_remove_days_before = days AFTER expiry before auto-removal
// If inserting a product already past that threshold, block it
if ($autoRemoveDays > 0 && $daysLeft < 0 && abs($daysLeft) >= $autoRemoveDays) {
    jsonResponse(false, 'Product is past its auto-remove period for this category and cannot be added', null, 400);
}

if ($daysLeft < 0) {
    $status = 'expired';
} elseif ($daysLeft <= $alertDays) {
    $status = 'near_expiry';
} else {
    $status = 'active';
}

$stmt = $conn->prepare("
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
");

if (!$stmt) {
    jsonResponse(false, 'Database error: ' . $conn->error, null, 500);
}

// Types:
// company_id i
// branch_id i
// barcode s
// product_name s
// category s
// quantity i
// unit_price d
// unit s
// expiry_date s
// status s
// entered_by i
// notes s
$stmt->bind_param(
    'iisssidsssis',
    $company_id,
    $branch_id,
    $barcode,
    $product_name,
    $category,
    $quantity,
    $unit_price,
    $unit,
    $expiry_date,
    $status,
    $entered_by,
    $notes
);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();

    if (str_contains($error, 'Duplicate entry')) {
        jsonResponse(false, 'This product batch already exists in this branch', null, 409);
    }

    jsonResponse(false, 'Failed to save product: ' . $error, null, 500);
}

$productId = $stmt->insert_id;
$stmt->close();

logActivity(
    $conn,
    $company_id,
    $branch_id,
    $entered_by,
    'create_product',
    'products',
    $productId,
    "Added product: $product_name (barcode: $barcode, entry type: $notes)"
);

// Clear notification cache if used
unset($_SESSION['notif_count_cache']);
unset($_SESSION['notif_count_ts']);

jsonResponse(true, 'Product saved successfully', [
    'product_id' => $productId,
    'status'     => $status,
    'notes'      => $notes,
]);