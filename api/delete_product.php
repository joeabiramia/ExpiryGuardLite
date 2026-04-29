<?php
ini_set('display_errors', '0');
error_reporting(0);

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
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

$productId = (int)($_POST['product_id'] ?? 0);

if ($productId <= 0) {
    jsonResponse(false, 'Invalid product ID.', null, 400);
}

$canDelete = in_array($myRole, ['super_admin', 'company_admin', 'branch_manager'], true)
    || userHasPermission($conn, $myUserId, 'delete_products')
    || userHasPermission($conn, $myUserId, 'manage_products');

if (!$canDelete) {
    jsonResponse(false, 'You do not have permission to delete products.', null, 403);
}

/*
|--------------------------------------------------------------------------
| Load product for scope check
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT id, company_id, branch_id, product_name, barcode
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

if ($myRole !== 'super_admin' && (int)$product['company_id'] !== $myCompanyId) {
    jsonResponse(false, 'You cannot delete products from another company.', null, 403);
}

if ($myRole === 'branch_manager' && $myBranchId > 0 && (int)$product['branch_id'] !== $myBranchId) {
    jsonResponse(false, 'You cannot delete products from another branch.', null, 403);
}

/*
|--------------------------------------------------------------------------
| Permanent delete
|--------------------------------------------------------------------------
*/
$delete = $conn->prepare("
    DELETE FROM products
    WHERE id = ?
    LIMIT 1
");

if (!$delete) {
    jsonResponse(false, 'Database error: ' . $conn->error, null, 500);
}

$delete->bind_param('i', $productId);

if (!$delete->execute()) {
    $msg = $delete->error;
    $delete->close();
    jsonResponse(false, 'Failed to delete product: ' . $msg, null, 500);
}

$affected = $delete->affected_rows;
$delete->close();

if ($affected <= 0) {
    jsonResponse(false, 'Product was not deleted.', null, 409);
}

unset($_SESSION['notif_count_cache']);
unset($_SESSION['notif_count_ts']);

jsonResponse(true, 'Product deleted permanently.', [
    'product_id' => $productId,
    'product_name' => $product['product_name'],
    'barcode' => $product['barcode']
]);