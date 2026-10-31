<?php
/**
 * Maruf Traders - AJAX Cancel / Void Sales Invoice
 */

define('APP_INIT', true);
define('IS_AJAX', true);
require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/includes/auth_check.php';
require_once ROOT_PATH . '/includes/permission_check.php';

requirePermission('sales.cancel');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    jsonResponse(false, 'Invalid CSRF token.', [], 403);
}

// Anti-duplicate lock
preventDuplicateSubmission('sale_cancel', $_POST);

$saleId = (int)($_POST['id'] ?? 0);
$reason = trim($_POST['reason'] ?? 'Invoice cancelled');

if (!$saleId) {
    jsonResponse(false, 'Sale ID is required.');
}

$db = Database::getConnection();

try {
    $db->beginTransaction();

    $stmt = $db->prepare("SELECT * FROM sales WHERE id = :id FOR UPDATE");
    $stmt->execute([':id' => $saleId]);
    $sale = $stmt->fetch();

    if (!$sale) throw new Exception("Sales invoice not found.");
    if ($sale['status'] === 'cancelled') throw new Exception("This invoice is already cancelled.");

    // Assert Month is open
    assertMonthOpen($sale['sale_date']);

    // Fetch Sale Items
    $siStmt = $db->prepare("SELECT * FROM sale_items WHERE sale_id = :sid");
    $siStmt->execute([':sid' => $saleId]);
    $items = $siStmt->fetchAll();

    $affectedCompanies = [];

    // 1. Restore Stock & Record Stock Ledger
    $uStock = $db->prepare("UPDATE products SET current_stock = current_stock + :qty WHERE id = :id");
    $pStockStmt = $db->prepare("SELECT current_stock FROM products WHERE id = :id");
    
    $slStmt = $db->prepare("INSERT INTO stock_ledger 
        (product_id, company_id, transaction_date, transaction_type, reference_id, stock_in, stock_out, running_balance, notes, created_by)
        VALUES (:pid, :cid, CURDATE(), 'SALE_CANCEL', :ref, :sin, 0, :run_bal, :notes, :uid)");

    foreach ($items as $item) {
        $uStock->execute([':qty' => $item['quantity'], ':id' => $item['product_id']]);
        
        $pStockStmt->execute([':id' => $item['product_id']]);
        $newProdStock = (int)$pStockStmt->fetchColumn();

        $slStmt->execute([
            ':pid'     => $item['product_id'],
            ':cid'     => $item['company_id'],
            ':ref'     => 'REV-' . $sale['invoice_no'],
            ':sin'     => $item['quantity'],
            ':run_bal' => $newProdStock,
            ':notes'   => "Sale cancelled: {$reason}",
            ':uid'     => $_SESSION['user_id'] ?? null
        ]);

        $affectedCompanies[(int)$item['company_id']] = true;
    }

    // 2. Reverse Retailer Balances & Ledger
    $rStmt = $db->prepare("SELECT * FROM retailers WHERE id = :id FOR UPDATE");
    $rStmt->execute([':id' => $sale['retailer_id']]);
    $retailer = $rStmt->fetch();

    $dueToRevert = (float)$sale['due_amount'];
    $advToRevert = (float)$sale['advance_deducted'];

    $curDue = (float)$retailer['current_due'];
    $curAdv = (float)$retailer['advance_balance'];

    $netDue = $curDue - $dueToRevert;
    $netAdv = $curAdv + $advToRevert;

    if ($netDue < 0) {
        $netAdv += abs($netDue);
        $netDue = 0.00;
    }

    $settle = 0.00;
    if ($netDue > 0 && $netAdv > 0) {
        $settle = min($netDue, $netAdv);
        $netDue = round($netDue - $settle, 2);
        $netAdv = round($netAdv - $settle, 2);
    } else {
        $netDue = round($netDue, 2);
        $netAdv = round($netAdv, 2);
    }

    $uRet = $db->prepare("UPDATE retailers SET current_due = :due, advance_balance = :adv WHERE id = :id");
    $uRet->execute([':due' => $netDue, ':adv' => $netAdv, ':id' => $retailer['id']]);

    // Restore advance_usage and advances table amounts
    if ($advToRevert > 0) {
        $auStmt = $db->prepare("SELECT * FROM advance_usage WHERE sale_id = :sid");
        $auStmt->execute([':sid' => $saleId]);
        $usages = $auStmt->fetchAll();

        $updAdv = $db->prepare("UPDATE advances SET used_amount = GREATEST(0, used_amount - :uamt), remaining_amount = remaining_amount + :uamt WHERE id = :aid");
        foreach ($usages as $u) {
            $updAdv->execute([':uamt' => $u['used_amount'], ':aid' => $u['advance_id']]);
        }

        $delAu = $db->prepare("DELETE FROM advance_usage WHERE sale_id = :sid");
        $delAu->execute([':sid' => $saleId]);
    }

    // Insert Retailer Ledger Reversal Entry
    $rlStmt = $db->prepare("INSERT INTO retailer_ledger 
        (retailer_id, transaction_date, transaction_type, reference_id, description, debit, credit, running_balance, created_by)
        VALUES (:rid, CURDATE(), 'SALE_CANCEL', :ref, :desc, 0.00, :credit, :run_bal, :uid)");

    $rlDesc = "Sale cancelled: {$reason} (Due reversed: ৳" . number_format($dueToRevert, 2);
    if ($advToRevert > 0) {
        $rlDesc .= ", Adv restored: ৳" . number_format($advToRevert, 2);
    }
    if ($settle > 0) {
        $rlDesc .= ", Auto-settled: ৳" . number_format($settle, 2);
    }
    $rlDesc .= ")";

    $rlStmt->execute([
        ':rid'     => $retailer['id'],
        ':ref'     => 'REV-' . $sale['invoice_no'],
        ':desc'    => $rlDesc,
        ':credit'  => $dueToRevert,
        ':run_bal' => $netDue,
        ':uid'     => $_SESSION['user_id'] ?? null
    ]);

    // 3. Mark any collections generated with this sale as cancelled
    $cCanStmt = $db->prepare("UPDATE collections 
                             SET status = 'cancelled', cancel_reason = :reason, cancelled_by = :uid, cancelled_at = NOW() 
                             WHERE sale_id = :sid AND status = 'active'");
    $cCanStmt->execute([
        ':reason' => "Parent sale invoice {$sale['invoice_no']} cancelled",
        ':uid'    => $_SESSION['user_id'] ?? null,
        ':sid'    => $saleId
    ]);

    // 4. Mark Sale as Cancelled
    $canSaleStmt = $db->prepare("UPDATE sales 
                                SET status = 'cancelled', cancel_reason = :reason, cancelled_by = :uid, cancelled_at = NOW() 
                                WHERE id = :id");
    $canSaleStmt->execute([
        ':reason' => $reason,
        ':uid'    => $_SESSION['user_id'] ?? null,
        ':id'     => $saleId
    ]);

    // 5. Recalculate Monthly Targets & Commission
    $saleMonth = (int)date('n', strtotime($sale['sale_date']));
    $saleYear = (int)date('Y', strtotime($sale['sale_date']));

    foreach (array_keys($affectedCompanies) as $cid) {
        recalculateTargetAndCommission($db, $cid, null, $saleMonth, $saleYear);
    }

    logAudit('sales', 'cancel', $sale['invoice_no'], $sale, ['reason' => $reason]);

    $db->commit();
    jsonResponse(true, "Sales invoice {$sale['invoice_no']} has been successfully cancelled. Stock and financial balances reversed.");

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(false, 'Cancellation failed: ' . $e->getMessage(), [], 400);
}
