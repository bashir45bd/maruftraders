<?php
/**
 * Maruf Traders - AJAX Save Cement Product
 */

define('APP_INIT', true);
define('IS_AJAX', true);
require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/includes/auth_check.php';
require_once ROOT_PATH . '/includes/permission_check.php';

requirePermission('products.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    jsonResponse(false, 'Invalid CSRF token.', [], 403);
}

$id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
$companyId = (int)($_POST['company_id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$brand = trim($_POST['brand'] ?? '');
$unit = trim($_POST['unit'] ?? 'Bag');
$bagSizeKg = (float)($_POST['bag_size_kg'] ?? 50.00);
$purchasePrice = (float)($_POST['default_purchase_price'] ?? 0.00);
$salePrice = (float)($_POST['default_sale_price'] ?? 0.00);
$openingStock = (int)($_POST['opening_stock'] ?? 0);
$lowStockLimit = (int)($_POST['low_stock_limit'] ?? 100);
$status = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';
$notes = trim($_POST['notes'] ?? '');

if (!$companyId) {
    jsonResponse(false, 'Please select a Company / Supplier.');
}
if (empty($name)) {
    jsonResponse(false, 'Product Name is required.');
}
if (empty($brand)) {
    jsonResponse(false, 'Brand Name is required.');
}

$db = Database::getConnection();

try {
    $db->beginTransaction();

    if ($id) {
        // Update Existing Product
        $stmt = $db->prepare("SELECT * FROM products WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $id]);
        $oldProduct = $stmt->fetch();

        if (!$oldProduct) {
            $db->rollBack();
            jsonResponse(false, 'Product not found.');
        }

        $uStmt = $db->prepare("UPDATE products 
                               SET company_id = :cid, name = :name, brand = :brand, unit = :unit, 
                                   bag_size_kg = :kg, default_purchase_price = :pprice, 
                                   default_sale_price = :sprice, low_stock_limit = :limit, 
                                   status = :status, notes = :notes 
                               WHERE id = :id");
        $uStmt->execute([
            ':cid'    => $companyId,
            ':name'   => $name,
            ':brand'  => $brand,
            ':unit'   => $unit,
            ':kg'     => $bagSizeKg,
            ':pprice' => $purchasePrice,
            ':sprice' => $salePrice,
            ':limit'  => $lowStockLimit,
            ':status' => $status,
            ':notes'  => $notes,
            ':id'     => $id
        ]);

        logAudit('products', 'update', $oldProduct['product_code'], $oldProduct, [
            'name' => $name, 'purchase_price' => $purchasePrice, 'sale_price' => $salePrice
        ]);

        $db->commit();
        jsonResponse(true, 'Product updated successfully.', ['id' => $id, 'code' => $oldProduct['product_code']]);

    } else {
        // Create New Product with Auto Code
        $code = generateCode('products', 'product_code', PREFIX_PRODUCT, 3);
        $currentStock = $openingStock;

        $insStmt = $db->prepare("INSERT INTO products 
            (company_id, product_code, name, brand, unit, bag_size_kg, default_purchase_price, default_sale_price, opening_stock, current_stock, low_stock_limit, status, notes) 
            VALUES (:cid, :code, :name, :brand, :unit, :kg, :pprice, :sprice, :opening, :stock, :limit, :status, :notes)");

        $insStmt->execute([
            ':cid'     => $companyId,
            ':code'    => $code,
            ':name'    => $name,
            ':brand'   => $brand,
            ':unit'    => $unit,
            ':kg'      => $bagSizeKg,
            ':pprice'  => $purchasePrice,
            ':sprice'  => $salePrice,
            ':opening' => $openingStock,
            ':stock'   => $currentStock,
            ':limit'   => $lowStockLimit,
            ':status'  => $status,
            ':notes'   => $notes
        ]);

        $newId = (int)$db->lastInsertId();

        // Create Stock Ledger Entry for Opening Stock if > 0
        if ($openingStock > 0) {
            $slStmt = $db->prepare("INSERT INTO stock_ledger 
                (product_id, company_id, transaction_date, transaction_type, reference_id, stock_in, stock_out, running_balance, notes, created_by)
                VALUES (:pid, :cid, CURDATE(), 'OPENING', :ref, :in_qty, 0, :run_bal, 'Opening stock initialization', :uid)");
            
            $slStmt->execute([
                ':pid'     => $newId,
                ':cid'     => $companyId,
                ':ref'     => 'INIT-STOCK-' . $code,
                ':in_qty'  => $openingStock,
                ':run_bal' => $openingStock,
                ':uid'     => $_SESSION['user_id'] ?? null
            ]);
        }

        logAudit('products', 'create', $code, null, [
            'name' => $name, 'brand' => $brand, 'opening_stock' => $openingStock, 'sale_price' => $salePrice
        ]);

        $db->commit();
        jsonResponse(true, 'Product created successfully.', ['id' => $newId, 'code' => $code]);
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(false, 'Failed to save product: ' . $e->getMessage(), [], 500);
}
