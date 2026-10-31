<?php
/**
 * Maruf Traders - AJAX Cancel / Void Company Payment
 */

define('APP_INIT', true);
define('IS_AJAX', true);
require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/includes/auth_check.php';
require_once ROOT_PATH . '/includes/permission_check.php';

requirePermission('company_payments.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    jsonResponse(false, 'Invalid CSRF token.', [], 403);
}

// Anti-Duplicate Submission Lock
preventDuplicateSubmission('company_payment_cancel', $_POST);

$paymentId = (int)($_POST['id'] ?? 0);
$reason = trim($_POST['reason'] ?? 'Payment cancelled by administrator');

if (!$paymentId) {
    jsonResponse(false, 'Payment ID is required.');
}

$db = Database::getConnection();

try {
    $db->beginTransaction();

    $stmt = $db->prepare("SELECT * FROM company_payments WHERE id = :id FOR UPDATE");
    $stmt->execute([':id' => $paymentId]);
    $payment = $stmt->fetch();

    if (!$payment) throw new Exception("Payment record not found.");
    if ($payment['status'] === 'cancelled') throw new Exception("This payment has already been cancelled.");

    // Assert month is open
    assertMonthOpen($payment['payment_date']);

    // Revert Company Payable
    $cStmt = $db->prepare("SELECT id, name, current_payable FROM companies WHERE id = :cid FOR UPDATE");
    $cStmt->execute([':cid' => $payment['company_id']]);
    $company = $cStmt->fetch();

    if (!$company) throw new Exception("Company not found.");

    $amount = (float)$payment['amount'];
    $newPayable = round((float)$company['current_payable'] + $amount, 2);

    $uComp = $db->prepare("UPDATE companies SET current_payable = :payable WHERE id = :id");
    $uComp->execute([':payable' => $newPayable, ':id' => $company['id']]);

    // Insert Company Ledger Reversal
    $clStmt = $db->prepare("INSERT INTO company_ledger 
        (company_id, transaction_date, transaction_type, reference_id, description, debit, credit, running_balance, created_by)
        VALUES (:cid, CURDATE(), 'PAYMENT_CANCEL', :ref, :desc, :debit, 0.00, :run_bal, :uid)");
    $clStmt->execute([
        ':cid'     => $company['id'],
        ':ref'     => 'REV-' . $payment['payment_code'],
        ':desc'    => "Payment cancelled: {$reason} (Payable restored: ৳" . number_format($amount, 2) . ")",
        ':debit'   => $amount,
        ':run_bal' => $newPayable,
        ':uid'     => $_SESSION['user_id'] ?? null
    ]);

    // Mark Payment as Cancelled
    $canStmt = $db->prepare("UPDATE company_payments 
                             SET status = 'cancelled', cancel_reason = :reason, cancelled_by = :uid, cancelled_at = NOW() 
                             WHERE id = :id");
    $canStmt->execute([
        ':reason' => $reason,
        ':uid'    => $_SESSION['user_id'] ?? null,
        ':id'     => $paymentId
    ]);

    logAudit('company_payments', 'cancel', $payment['payment_code'], $payment, ['reason' => $reason]);

    $db->commit();
    jsonResponse(true, "Company payment {$payment['payment_code']} cancelled successfully. Company payable balance restored.");

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(false, 'Cancellation failed: ' . $e->getMessage(), [], 400);
}
