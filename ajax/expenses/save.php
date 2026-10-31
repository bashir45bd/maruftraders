<?php
/**
 * Maruf Traders - AJAX Save Operating Expense
 */

define('APP_INIT', true);
define('IS_AJAX', true);
require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/includes/auth_check.php';
require_once ROOT_PATH . '/includes/permission_check.php';

requirePermission('expenses.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    jsonResponse(false, 'Invalid CSRF token.', [], 403);
}

// Anti-Duplicate Submission Lock
preventDuplicateSubmission('exp_save', $_POST);

$expenseId = (int)($_POST['id'] ?? 0);
$categoryId = (int)($_POST['category_id'] ?? 0);
$expenseDate = trim($_POST['expense_date'] ?? date('Y-m-d'));
$title = trim($_POST['title'] ?? '');
$amount = (float)($_POST['amount'] ?? 0.00);
$paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
$paidTo = trim($_POST['paid_to'] ?? '');
$voucherNo = trim($_POST['voucher_no'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if (!$categoryId) jsonResponse(false, 'Please select an Expense Category.');
if (empty($title)) jsonResponse(false, 'Expense title is required.');
if ($amount <= 0) jsonResponse(false, 'Expense amount must be greater than 0.');

$db = Database::getConnection();

try {
    assertMonthOpen($expenseDate);

    $db->beginTransaction();

    if ($expenseId > 0) {
        $oldStmt = $db->prepare("SELECT * FROM expenses WHERE id = :id FOR UPDATE");
        $oldStmt->execute([':id' => $expenseId]);
        $oldExp = $oldStmt->fetch();
        if (!$oldExp) throw new Exception("Expense record not found.");
        if ($oldExp['status'] === 'cancelled') throw new Exception("Cannot edit a cancelled expense.");

        $uStmt = $db->prepare("UPDATE expenses SET 
            category_id = :cid, expense_date = :edate, title = :title, 
            amount = :amt, payment_method = :pmethod, paid_to = :paidto, 
            voucher_no = :vouch, notes = :notes 
            WHERE id = :id");
        $uStmt->execute([
            ':cid'     => $categoryId,
            ':edate'   => $expenseDate,
            ':title'   => $title,
            ':amt'     => $amount,
            ':pmethod' => $paymentMethod,
            ':paidto'  => $paidTo,
            ':vouch'   => $voucherNo,
            ':notes'   => $notes,
            ':id'      => $expenseId
        ]);

        logAudit('expenses', 'update', $oldExp['expense_code'], $oldExp, [
            'title' => $title, 'amount' => $amount, 'category_id' => $categoryId
        ]);

        $db->commit();
        jsonResponse(true, "Expense voucher {$oldExp['expense_code']} updated successfully.", ['code' => $oldExp['expense_code']]);
    }

    $expCode = generateCode('expenses', 'expense_code', PREFIX_EXPENSE, 5);

    $stmt = $db->prepare("INSERT INTO expenses 
        (expense_code, category_id, expense_date, title, amount, payment_method, paid_to, voucher_no, status, notes, created_by)
        VALUES (:code, :cid, :edate, :title, :amt, :pmethod, :paidto, :vouch, 'active', :notes, :uid)");

    $stmt->execute([
        ':code'    => $expCode,
        ':cid'     => $categoryId,
        ':edate'   => $expenseDate,
        ':title'   => $title,
        ':amt'     => $amount,
        ':pmethod' => $paymentMethod,
        ':paidto'  => $paidTo,
        ':vouch'   => $voucherNo,
        ':notes'   => $notes,
        ':uid'     => $_SESSION['user_id'] ?? null
    ]);

    logAudit('expenses', 'create', $expCode, null, [
        'title' => $title, 'amount' => $amount, 'category_id' => $categoryId
    ]);

    $db->commit();
    jsonResponse(true, "Expense of " . formatBDT($amount) . " recorded successfully ({$expCode}).", ['code' => $expCode]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(false, 'Expense entry failed: ' . $e->getMessage(), [], 400);
}
