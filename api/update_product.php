<?php
ini_set('display_errors', '0');
error_reporting(0);

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    $message = $e->getMessage();

    if (str_contains($message, 'Duplicate entry')) {
        $message = 'A product with the same branch, barcode, and expiry date already exists.';
    }

    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $message
    ]);
    exit;
});

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

if (function_exists('addSecurityHeaders')) {
    addSecurityHeaders();
}

requireLogin();
header('Content-Type: application/json; charset=utf-8');

$myUserId    = (int)($_SESSION['user_id'] ?? 0);
$myRole      = $_SESSION['role'] ?? 'viewer';
$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id'] ?? 0);

$productId   = (int)($_POST['product_id'] ?? 0);
$productName = trim((string)($_POST['product_name'] ?? ''));
$barcode     = trim((string)($_POST['barcode'] ?? ''));
$category    = trim((string)($_POST['category'] ?? ''));
$quantity    = max(1, (int)($_POST['quantity'] ?? 1));
$unitPriceRaw = trim((string)($_POST['unit_price'] ?? '0'));
$unit        = trim((string)($_POST['unit'] ?? ''));
$expiryDate  = trim((string)($_POST['expiry_date'] ?? ''));

$canEdit = in_array($myRole, ['super_admin', 'company_admin', 'branch_manager'], true)
    || userHasPermission($conn, $myUserId, 'manage_products');

if (!$canEdit) {
    jsonResponse(false, 'You do not have permission to edit products.', null, 403);
}

if ($productId <= 0) {
    jsonResponse(false, 'Invalid product ID.', null, 400);
}

if ($productName === '') {
    jsonResponse(false, 'Product name is required.', null, 400);
}

if ($barcode === '') {
    jsonResponse(false, 'Barcode is required.', null, 400);
}

if ($category === '') {
    jsonResponse(false, 'Category is required.', null, 400);
}

if ($expiryDate === '') {
    jsonResponse(false, 'Expiry date is required.', null, 400);
}

$dateObj = DateTime::createFromFormat('Y-m-d', $expiryDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $expiryDate) {
    jsonResponse(false, 'Expiry date must be in YYYY-MM-DD format.', null, 400);
}

$unitPrice = is_numeric($unitPriceRaw) ? (float)$unitPriceRaw : 0.00;

/*
|--------------------------------------------------------------------------
| Load product for scope check
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT id, company_id, branch_id, is_removed
    FROM products
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    jsonResponse(false, 'Database error: ' . $conn->error, null, 500);
}

$stmt->bind_param('i', $productId);
$stmt->execute();

$res = $stmt->get_result();
$product = $res->fetch_assoc();

$res->free();
$stmt->close();

if (!$product) {
    jsonResponse(false, 'Product not found.', null, 404);
}

if ((int)$product['is_removed'] === 1) {
    jsonResponse(false, 'Removed products cannot be edited from this page.', null, 409);
}

if ($myRole !== 'super_admin' && (int)$product['company_id'] !== $myCompanyId) {
    jsonResponse(false, 'You cannot edit products from another company.', null, 403);
}

if ($myRole === 'branch_manager' && $myBranchId > 0 && (int)$product['branch_id'] !== $myBranchId) {
    jsonResponse(false, 'You cannot edit products from another branch.', null, 403);
}

/*
|--------------------------------------------------------------------------
| Recalculate status based on category rule
|--------------------------------------------------------------------------
*/
function calculateProductStatusForUpdate(mysqli $conn, string $category, string $expiryDate): string
{
    $alertDays = 4;

    $stmt = $conn->prepare("
        SELECT alert_days_before
        FROM category_rules
        WHERE LOWER(category_name) = LOWER(?)
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('s', $category);
        $stmt->execute();

        $res = $stmt->get_result();
        $rule = $res->fetch_assoc();

        if ($rule) {
            $alertDays = (int)$rule['alert_days_before'];
        }

        $res->free();
        $stmt->close();
    }

    $today = strtotime(date('Y-m-d'));
    $expiry = strtotime($expiryDate);
    $daysLeft = (int)floor(($expiry - $today) / 86400);

    if ($daysLeft < 0) {
        return 'expired';
    }

    if ($daysLeft <= $alertDays) {
        return 'near_expiry';
    }

    return 'active';
}

$status = calculateProductStatusForUpdate($conn, $category, $expiryDate);

/*
|--------------------------------------------------------------------------
| Update product
|--------------------------------------------------------------------------
*/
$update = $conn->prepare("
    UPDATE products
    SET
        product_name = ?,
        barcode = ?,
        category = ?,
        quantity = ?,
        unit_price = ?,
        unit = ?,
        expiry_date = ?,
        status = ?
    WHERE id = ?
    LIMIT 1
");

if (!$update) {
    jsonResponse(false, 'Database error: ' . $conn->error, null, 500);
}

$update->bind_param(
    'sssidsssi',
    $productName,
    $barcode,
    $category,
    $quantity,
    $unitPrice,
    $unit,
    $expiryDate,
    $status,
    $productId
);

if (!$update->execute()) {
    $msg = $update->error;
    $update->close();

    if (str_contains($msg, 'Duplicate entry')) {
        jsonResponse(false, 'A product with the same branch, barcode, and expiry date already exists.', null, 409);
    }

    jsonResponse(false, 'Failed to update product: ' . $msg, null, 500);
}

$update->close();

unset($_SESSION['notif_count_cache']);
unset($_SESSION['notif_count_ts']);

jsonResponse(true, 'Product updated successfully.', [
    'product_id' => $productId,
    'status' => $status
]);