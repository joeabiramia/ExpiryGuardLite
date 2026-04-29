<?php
ini_set('display_errors', '0');
error_reporting(0);

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

if (function_exists('addSecurityHeaders')) {
    addSecurityHeaders();
} else {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$myUserId    = (int)($_SESSION['user_id'] ?? 0);
$myRole      = $_SESSION['role'] ?? 'viewer';
$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id'] ?? 0);

$productId = (int)($_POST['product_id'] ?? 0);

if ($productId <= 0) {
    jsonResponse(false, 'Invalid product ID.', null, 400);
}

$isManagerRole = in_array($myRole, ['super_admin', 'company_admin', 'branch_manager'], true);
$isEmployee    = $myRole === 'employee';
$isViewer      = $myRole === 'viewer';

$hasRemovePermission = userHasPermission($conn, $myUserId, 'remove_expired_items');
$hasDeletePermission = userHasPermission($conn, $myUserId, 'delete_products');
$hasManagePermission = userHasPermission($conn, $myUserId, 'manage_products');

if ($isViewer) {
    jsonResponse(false, 'Viewers are not allowed to remove products.', null, 403);
}

if (!$isManagerRole && !$isEmployee && !$hasRemovePermission && !$hasDeletePermission && !$hasManagePermission) {
    jsonResponse(false, 'You do not have permission to remove products.', null, 403);
}

/*
|--------------------------------------------------------------------------
| Load product first
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT 
        id,
        company_id,
        branch_id,
        product_name,
        barcode,
        status,
        is_removed
    FROM products
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    jsonResponse(false, 'Database error: ' . $conn->error, null, 500);
}

$stmt->bind_param('i', $productId);
$stmt->execute();

$result = $stmt->get_result();
$product = $result->fetch_assoc();

$result->free();
$stmt->close();

if (!$product) {
    jsonResponse(false, 'Product not found.', null, 404);
}

if ((int)$product['is_removed'] === 1) {
    jsonResponse(false, 'Product is already removed.', null, 409);
}

/*
|--------------------------------------------------------------------------
| Scope protection
|--------------------------------------------------------------------------
| super_admin: any company can be allowed depending on your system,
| but here we still keep company protection unless company_id is 0.
| company_admin: own company
| branch_manager / employee: own branch
|--------------------------------------------------------------------------
*/

if ($myRole !== 'super_admin') {
    if ((int)$product['company_id'] !== $myCompanyId) {
        jsonResponse(false, 'You cannot remove products from another company.', null, 403);
    }
}

if (in_array($myRole, ['branch_manager', 'employee'], true) && $myBranchId > 0) {
    if ((int)$product['branch_id'] !== $myBranchId) {
        jsonResponse(false, 'You cannot remove products from another branch.', null, 403);
    }
}

/*
|--------------------------------------------------------------------------
| Employee rule
|--------------------------------------------------------------------------
| Employees can remove only near_expiry or expired items.
|--------------------------------------------------------------------------
*/

if ($isEmployee && !$isManagerRole) {
    if (!in_array($product['status'], ['near_expiry', 'expired'], true)) {
        jsonResponse(false, 'Employees can only remove near-expiry or expired products.', null, 403);
    }
}

/*
|--------------------------------------------------------------------------
| Remove product
|--------------------------------------------------------------------------
| This is a soft remove, not a hard delete.
|--------------------------------------------------------------------------
*/

$notes = 'Removed from products page';

$update = $conn->prepare("
    UPDATE products
    SET 
        is_removed = 1,
        status = 'removed',
        removed_by = ?,
        removed_on = NOW(),
        notes = CASE 
            WHEN notes IS NULL OR notes = '' THEN ?
            ELSE CONCAT(notes, '\n', ?)
        END
    WHERE id = ?
    LIMIT 1
");

if (!$update) {
    jsonResponse(false, 'Database error: ' . $conn->error, null, 500);
}

$update->bind_param('issi', $myUserId, $notes, $notes, $productId);

if (!$update->execute()) {
    $update->close();
    jsonResponse(false, 'Failed to remove product.', null, 500);
}

$affected = $update->affected_rows;
$update->close();

if ($affected <= 0) {
    jsonResponse(false, 'Product was not updated.', null, 409);
}

unset($_SESSION['notif_count_cache']);
unset($_SESSION['notif_count_ts']);

jsonResponse(true, 'Product removed successfully.', [
    'product_id' => $productId,
    'product_name' => $product['product_name'],
    'barcode' => $product['barcode'],
]);