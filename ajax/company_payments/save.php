<?php
/**
 * Maruf Traders - AJAX Save Company Payment
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
preventDuplicateSubmission('company_payment_save', $_POST);

$paymentId = (int)($_POST['id'] ?? 0);
$companyId = (int)($_POST['company_id'] ?? 0);
$paymentDate = trim($_POST['payment_date'] ?? date('Y-m-d'));
$amount = (float)($_POST['amount'] ?? 0.00);
$paymentMethod = trim($_POST['payment_method'] ?? 'Bank');
$referenceNo = trim($_POST['reference_no'] ?? '');
$bankName = trim($_POST['bank_name'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if (!$companyId) jsonResponse(false, 'Please select a Company / Supplier.');
if ($amount <= 0) jsonResponse(false, 'Payment amount must be greater than 0.');

$db = Database::getConnection();

try {
    assertMonthOpen($paymentDate);

    $db->beginTransaction();

    $cStmt = $db->prepare("SELECT * FROM companies WHERE id = :id FOR UPDATE");
    $cStmt->execute([':id' => $companyId]);
    $company = $cStmt->fetch();

    if (!$company) throw new Exception("Company not found.");

    $currentPayable = (float)$company['current_payable'];
    $allowOverpay = strtolower(getSetting('allow_company_overpayment', 'no')) === 'yes';

    // -------------------------------------------------------------
    // EDIT MODE
    // -------------------------------------------------------------
    if ($paymentId > 0) {
        $oldStmt = $db->prepare("SELECT * FROM company_payments WHERE id = :id FOR UPDATE");
        $oldStmt->execute([':id' => $paymentId]);
        $oldPayment = $oldStmt->fetch();

        if (!$oldPayment) throw new Exception("Payment record not found.");
        if ($oldPayment['status'] === 'cancelled') throw new Exception("Cannot edit a cancelled payment.");

        $oldAmount = (float)$oldPayment['amount'];
        $diff = round($amount - $oldAmount, 2);

        if (!$allowOverpay && ($currentPayable - $diff < 0)) {
            throw new Exception("New payment amount exceeds current payable balance. Overpayment is restricted in settings.");
        }

        $newPayable = round($currentPayable - $diff, 2);

        $updStmt = $db->prepare("UPDATE company_payments SET 
            payment_date = :pdate, amount = :amt, payment_method = :pmethod, 
            reference_no = :ref, bank_name = :bank, notes = :notes 
            WHERE id = :id");
        $updStmt->execute([
            ':pdate'   => $paymentDate,
            ':amt'     => $amount,
            ':pmethod' => $paymentMethod,
            ':ref'     => $referenceNo,
            ':bank'    => $bankName,
            ':notes'   => $notes,
            ':id'      => $paymentId
        ]);

        $uStmt = $db->prepare("UPDATE companies SET current_payable = :payable WHERE id = :id");
        $uStmt->execute([':payable' => $newPayable, ':id' => $companyId]);

        // Record Ledger Adjustment
        $desc = "Payment {$oldPayment['payment_code']} edited (৳" . number_format($oldAmount, 2) . " → ৳" . number_format($amount, 2) . ")";
        $clStmt = $db->prepare("INSERT INTO company_ledger 
            (company_id, transaction_date, transaction_type, reference_id, description, debit, credit, running_balance, created_by)
            VALUES (:cid, :tdate, 'ADJUSTMENT', :ref, :desc, :deb, :cred, :run_bal, :uid)");
        $clStmt->execute([
            ':cid'     => $companyId,
            ':tdate'   => $paymentDate,
            ':ref'     => $oldPayment['payment_code'],
            ':desc'    => $desc,
            ':deb'     => ($diff < 0 ? abs($diff) : 0.00),
            ':cred'    => ($diff > 0 ? $diff : 0.00),
            ':run_bal' => $newPayable,
            ':uid'     => $_SESSION['user_id'] ?? null
        ]);

        logAudit('company_payments', 'update', $oldPayment['payment_code'], $oldPayment, [
            'company' => $company['name'], 'old_amount' => $oldAmount, 'new_amount' => $amount
        ]);

        $db->commit();
        jsonResponse(true, "Company payment {$oldPayment['payment_code']} updated successfully. Company payable balance adjusted.", ['code' => $oldPayment['payment_code']]);
    }

    if (!$allowOverpay && $amount > $currentPayable) {
        throw new Exception("Payment amount (৳" . number_format($amount, 2) . ") exceeds current payable balance (৳" . number_format($currentPayable, 2) . "). Overpayment is restricted in settings.");
    }

    $paymentCode = generateCode('company_payments', 'payment_code', PREFIX_COMPANY_PAYMENT, 5);

    // Insert Company Payment
    $pStmt = $db->prepare("INSERT INTO company_payments 
        (payment_code, company_id, payment_date, amount, payment_method, reference_no, bank_name, status, notes, created_by)
        VALUES (:code, :cid, :pdate, :amt, :pmethod, :ref, :bank, 'active', :notes, :uid)");

    $pStmt->execute([
        ':code'    => $paymentCode,
        ':cid'     => $companyId,
        ':pdate'   => $paymentDate,
        ':amt'     => $amount,
        ':pmethod' => $paymentMethod,
        ':ref'     => $referenceNo,
        ':bank'    => $bankName,
        ':notes'   => $notes,
        ':uid'     => $_SESSION['user_id'] ?? null
    ]);

    // Update Company Current Payable
    $newPayable = $currentPayable - $amount;
    $uStmt = $db->prepare("UPDATE companies SET current_payable = :payable WHERE id = :id");
    $uStmt->execute([':payable' => $newPayable, ':id' => $companyId]);

    // Record Company Ledger Entry
    $clStmt = $db->prepare("INSERT INTO company_ledger 
        (company_id, transaction_date, transaction_type, reference_id, description, debit, credit, running_balance, created_by)
        VALUES (:cid, :tdate, 'PAYMENT', :ref, :desc, 0.00, :credit, :run_bal, :uid)");

    $clStmt->execute([
        ':cid'     => $companyId,
        ':tdate'   => $paymentDate,
        ':ref'     => $paymentCode,
        ':desc'    => "Company Payment via {$paymentMethod}" . ($referenceNo ? " (Ref: {$referenceNo})" : ""),
        ':credit'  => $amount,
        ':run_bal' => $newPayable,
        ':uid'     => $_SESSION['user_id'] ?? null
    ]);

    logAudit('company_payments', 'create', $paymentCode, null, [
        'company' => $company['name'], 'amount' => $amount, 'method' => $paymentMethod
    ]);

    $db->commit();
    jsonResponse(true, "Payment of " . formatBDT($amount) . " to {$company['name']} recorded successfully ({$paymentCode}).", ['code' => $paymentCode]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(false, 'Company payment failed: ' . $e->getMessage(), [], 400);
}
