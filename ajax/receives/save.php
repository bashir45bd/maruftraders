<?php
/**
 * Maruf Traders - AJAX Save Cement Receive / Purchase
 */

define('APP_INIT', true);
define('IS_AJAX', true);
require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/includes/auth_check.php';
require_once ROOT_PATH . '/includes/permission_check.php';

requirePermission('receives.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    jsonResponse(false, 'Invalid CSRF token.', [], 403);
}

// Anti-Duplicate Submission Lock
preventDuplicateSubmission('receive_save', $_POST);

$receiveId = (int)($_POST['id'] ?? 0);
$companyId = (int)($_POST['company_id'] ?? 0);
$productId = (int)($_POST['product_id'] ?? 0);
$receiveDate = trim($_POST['receive_date'] ?? date('Y-m-d'));
$quantity = (int)($_POST['quantity'] ?? 0);
$purchaseRate = (float)($_POST['purchase_rate'] ?? 0.00);
$transportCost = (float)($_POST['transport_cost'] ?? 0.00);
$loadingCost = (float)($_POST['loading_cost'] ?? 0.00);
$otherCost = (float)($_POST['other_cost'] ?? 0.00);
$paidAmount = (float)($_POST['paid_amount'] ?? 0.00);
$paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
$challanNo = trim($_POST['challan_no'] ?? '');
$vehicleNo = trim($_POST['vehicle_no'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if (!$companyId) jsonResponse(false, 'Please select a Supplier / Company.');
if (!$productId) jsonResponse(false, 'Please select a Cement Product.');
if ($quantity <= 0) jsonResponse(false, 'Received Quantity must be greater than 0 bags.');
if ($purchaseRate <= 0) jsonResponse(false, 'Purchase Rate must be greater than 0.');

// Backend Financial Calculation
$purchaseValue = round($quantity * $purchaseRate, 2);
$totalCost = round($purchaseValue + $transportCost + $loadingCost + $otherCost, 2);
$unitCostBasis = round($totalCost / $quantity, 2);
$payableAmount = round($totalCost - $paidAmount, 2);
$paymentStatus = ($payableAmount <= 0) ? 'paid' : (($paidAmount > 0) ? 'partial' : 'due');

$db = Database::getConnection();

try {
    // 1. Assert Month is Open
    assertMonthOpen($receiveDate);

    $db->beginTransaction();

    // Verify company & product
    $cStmt = $db->prepare("SELECT id, name, current_payable FROM companies WHERE id = :id FOR UPDATE");
    $cStmt->execute([':id' => $companyId]);
    $company = $cStmt->fetch();
    if (!$company) throw new Exception("Invalid company selected.");

    $pStmt = $db->prepare("SELECT id, name, current_stock FROM products WHERE id = :id AND company_id = :cid FOR UPDATE");
    $pStmt->execute([':id' => $productId, ':cid' => $companyId]);
    $product = $pStmt->fetch();
    if (!$product) throw new Exception("Selected product does not belong to this company.");

    $allowNegative = strtolower(getSetting('allow_negative_stock', 'no')) === 'yes';

    // -------------------------------------------------------------
    // EDIT MODE
    // -------------------------------------------------------------
    if ($receiveId > 0) {
        $oldStmt = $db->prepare("SELECT * FROM cement_receives WHERE id = :id FOR UPDATE");
        $oldStmt->execute([':id' => $receiveId]);
        $oldRec = $oldStmt->fetch();
        if (!$oldRec) throw new Exception("Receive record not found.");
        if ($oldRec['status'] === 'cancelled') throw new Exception("Cannot edit a cancelled receive.");

        $oldQty = (int)$oldRec['quantity'];
        $qtyDelta = $quantity - $oldQty;

        if (($product['current_stock'] + $qtyDelta < 0) && !$allowNegative) {
            throw new Exception("Cannot reduce received quantity: Current stock is {$product['current_stock']} bags. Reducing by " . abs($qtyDelta) . " would cause negative stock.");
        }

        $oldPayable = (float)$oldRec['payable_amount'];
        $payableDelta = round($payableAmount - $oldPayable, 2);

        $newStock = (int)$product['current_stock'] + $qtyDelta;
        $newPayable = round((float)$company['current_payable'] + $payableDelta, 2);

        // Update Receive record
        $uRecStmt = $db->prepare("UPDATE cement_receives SET 
            company_id = :cid, product_id = :pid, receive_date = :rdate, quantity = :qty, 
            purchase_rate = :prate, purchase_value = :pval, transport_cost = :tcost, 
            loading_cost = :lcost, other_cost = :ocost, total_cost = :tcost_tot, 
            unit_cost_basis = :ucost, paid_amount = :paid, payable_amount = :payable, 
            payment_status = :pstatus, payment_method = :pmethod, challan_no = :challan, 
            vehicle_no = :vehicle, notes = :notes WHERE id = :id");
        $uRecStmt->execute([
            ':cid'       => $companyId,
            ':pid'       => $productId,
            ':rdate'     => $receiveDate,
            ':qty'       => $quantity,
            ':prate'     => $purchaseRate,
            ':pval'      => $purchaseValue,
            ':tcost'     => $transportCost,
            ':lcost'     => $loadingCost,
            ':ocost'     => $otherCost,
            ':tcost_tot' => $totalCost,
            ':ucost'     => $unitCostBasis,
            ':paid'      => $paidAmount,
            ':payable'   => $payableAmount,
            ':pstatus'   => $paymentStatus,
            ':pmethod'   => $paymentMethod,
            ':challan'   => $challanNo,
            ':vehicle'   => $vehicleNo,
            ':notes'     => $notes,
            ':id'        => $receiveId
        ]);

        // Update Product Stock & Cost Basis
        $updProdStmt = $db->prepare("UPDATE products SET current_stock = :new_stock, default_purchase_price = :cost_basis WHERE id = :pid");
        $updProdStmt->execute([':new_stock' => $newStock, ':cost_basis' => $unitCostBasis, ':pid' => $productId]);

        // Update Company Current Payable
        $updCompStmt = $db->prepare("UPDATE companies SET current_payable = :payable WHERE id = :cid");
        $updCompStmt->execute([':payable' => $newPayable, ':cid' => $companyId]);

        // Stock Ledger adjustment
        if ($qtyDelta != 0) {
            $slType = $qtyDelta > 0 ? 'ADJUSTMENT_ADD' : 'ADJUSTMENT_SUB';
            $slStmt = $db->prepare("INSERT INTO stock_ledger 
                (product_id, company_id, transaction_date, transaction_type, reference_id, stock_in, stock_out, running_balance, notes, created_by)
                VALUES (:pid, :cid, :tdate, :ttype, :ref, :sin, :sout, :run_bal, :notes, :uid)");
            $slStmt->execute([
                ':pid'     => $productId,
                ':cid'     => $companyId,
                ':tdate'   => $receiveDate,
                ':ttype'   => $slType,
                ':ref'     => $oldRec['receive_code'],
                ':sin'     => ($qtyDelta > 0 ? $qtyDelta : 0),
                ':sout'    => ($qtyDelta < 0 ? abs($qtyDelta) : 0),
                ':run_bal' => $newStock,
                ':notes'   => "Receive {$oldRec['receive_code']} edited (qty changed from {$oldQty} to {$quantity})",
                ':uid'     => $_SESSION['user_id'] ?? null
            ]);
        }

        // Company Ledger adjustment
        if ($payableDelta != 0) {
            $clStmt = $db->prepare("INSERT INTO company_ledger 
                (company_id, transaction_date, transaction_type, reference_id, description, debit, credit, running_balance, created_by)
                VALUES (:cid, :tdate, 'ADJUSTMENT', :ref, :desc, :deb, :cred, :run_bal, :uid)");
            $clStmt->execute([
                ':cid'     => $companyId,
                ':tdate'   => $receiveDate,
                ':ref'     => $oldRec['receive_code'],
                ':desc'    => "Receive {$oldRec['receive_code']} edited (payable adjusted: ৳" . number_format($payableDelta, 2) . ")",
                ':deb'     => ($payableDelta > 0 ? $payableDelta : 0.00),
                ':cred'    => ($payableDelta < 0 ? abs($payableDelta) : 0.00),
                ':run_bal' => $newPayable,
                ':uid'     => $_SESSION['user_id'] ?? null
            ]);
        }

        logAudit('cement_receives', 'update', $oldRec['receive_code'], $oldRec, [
            'company' => $company['name'], 'product' => $product['name'], 'old_qty' => $oldQty, 'new_qty' => $quantity, 'payable_delta' => $payableDelta
        ]);

        $db->commit();
        jsonResponse(true, "Cement receive {$oldRec['receive_code']} updated successfully. Stock and company balance adjusted.", ['code' => $oldRec['receive_code']]);
    }

    // Generate Receive Code
    $receiveCode = generateCode('cement_receives', 'receive_code', PREFIX_RECEIVE, 5);

    // Insert Cement Receive
    $rStmt = $db->prepare("INSERT INTO cement_receives 
        (receive_code, company_id, product_id, receive_date, quantity, purchase_rate, purchase_value, 
         transport_cost, loading_cost, other_cost, total_cost, unit_cost_basis, paid_amount, payable_amount, 
         payment_status, payment_method, challan_no, vehicle_no, status, notes, created_by)
        VALUES 
        (:code, :cid, :pid, :rdate, :qty, :prate, :pval, :tcost, :lcost, :ocost, :tcost_tot, :ucost, :paid, :payable,
         :pstatus, :pmethod, :challan, :vehicle, 'active', :notes, :uid)");

    $rStmt->execute([
        ':code'      => $receiveCode,
        ':cid'       => $companyId,
        ':pid'       => $productId,
        ':rdate'     => $receiveDate,
        ':qty'       => $quantity,
        ':prate'     => $purchaseRate,
        ':pval'      => $purchaseValue,
        ':tcost'     => $transportCost,
        ':lcost'     => $loadingCost,
        ':ocost'     => $otherCost,
        ':tcost_tot' => $totalCost,
        ':ucost'     => $unitCostBasis,
        ':paid'      => $paidAmount,
        ':payable'   => $payableAmount,
        ':pstatus'   => $paymentStatus,
        ':pmethod'   => $paymentMethod,
        ':challan'   => $challanNo,
        ':vehicle'   => $vehicleNo,
        ':notes'     => $notes,
        ':uid'       => $_SESSION['user_id'] ?? null
    ]);

    // Increase Product Stock & Update Cost Basis
    $newStock = (int)$product['current_stock'] + $quantity;
    $updProdStmt = $db->prepare("UPDATE products 
                                 SET current_stock = :new_stock, default_purchase_price = :cost_basis 
                                 WHERE id = :pid");
    $updProdStmt->execute([
        ':new_stock'   => $newStock,
        ':cost_basis'  => $unitCostBasis,
        ':pid'         => $productId
    ]);

    // Record in Stock Ledger
    $slStmt = $db->prepare("INSERT INTO stock_ledger 
        (product_id, company_id, transaction_date, transaction_type, reference_id, stock_in, stock_out, running_balance, notes, created_by)
        VALUES (:pid, :cid, :tdate, 'RECEIVE', :ref, :in_qty, 0, :run_bal, :notes, :uid)");
    $slStmt->execute([
        ':pid'     => $productId,
        ':cid'     => $companyId,
        ':tdate'   => $receiveDate,
        ':ref'     => $receiveCode,
        ':in_qty'  => $quantity,
        ':run_bal' => $newStock,
        ':notes'   => "Cement received via Challan: " . ($challanNo ?: 'N/A'),
        ':uid'     => $_SESSION['user_id'] ?? null
    ]);

    // Update Company Current Payable
    $newPayable = (float)$company['current_payable'] + $payableAmount;
    $updCompStmt = $db->prepare("UPDATE companies SET current_payable = :payable WHERE id = :cid");
    $updCompStmt->execute([':payable' => $newPayable, ':cid' => $companyId]);

    // Record in Company Ledger
    $clStmt = $db->prepare("INSERT INTO company_ledger 
        (company_id, transaction_date, transaction_type, reference_id, description, debit, credit, running_balance, created_by)
        VALUES (:cid, :tdate, 'RECEIVE', :ref, :desc, :debit, :credit, :run_bal, :uid)");
    $clStmt->execute([
        ':cid'     => $companyId,
        ':tdate'   => $receiveDate,
        ':ref'     => $receiveCode,
        ':desc'    => "Received {$quantity} bags of {$product['name']}",
        ':debit'   => $totalCost,
        ':credit'  => $paidAmount,
        ':run_bal' => $newPayable,
        ':uid'     => $_SESSION['user_id'] ?? null
    ]);

    // Record Audit Log
    logAudit('cement_receives', 'create', $receiveCode, null, [
        'company' => $company['name'], 'product' => $product['name'], 'qty' => $quantity, 'total_cost' => $totalCost
    ]);

    $db->commit();
    jsonResponse(true, "Cement receive recorded successfully ({$receiveCode}). Stock updated by +{$quantity} bags.", ['code' => $receiveCode]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(false, $e->getMessage(), [], 400);
}
