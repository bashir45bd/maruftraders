<?php
/**
 * Maruf Traders - AJAX Save Company / Supplier
 */

define('APP_INIT', true);
define('IS_AJAX', true);
require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/includes/auth_check.php';
require_once ROOT_PATH . '/includes/permission_check.php';

requirePermission('companies.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    jsonResponse(false, 'Invalid CSRF token.', [], 403);
}

$id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
$name = trim($_POST['name'] ?? '');
$contactPerson = trim($_POST['contact_person'] ?? '');
$mobile = trim($_POST['mobile'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$openingPayable = (float)($_POST['opening_payable'] ?? 0.00);
$status = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';
$notes = trim($_POST['notes'] ?? '');

if (empty($name)) {
    jsonResponse(false, 'Company Name is required.');
}
if (empty($mobile)) {
    jsonResponse(false, 'Contact Mobile Number is required.');
}

$db = Database::getConnection();

try {
    $db->beginTransaction();

    if ($id) {
        // Update Existing Company
        $stmt = $db->prepare("SELECT * FROM companies WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $id]);
        $oldCompany = $stmt->fetch();

        if (!$oldCompany) {
            $db->rollBack();
            jsonResponse(false, 'Company not found.');
        }

        $uStmt = $db->prepare("UPDATE companies 
                               SET name = :name, contact_person = :cp, mobile = :mobile, 
                                   email = :email, address = :address, status = :status, notes = :notes 
                               WHERE id = :id");
        $uStmt->execute([
            ':name'    => $name,
            ':cp'      => $contactPerson,
            ':mobile'  => $mobile,
            ':email'   => $email,
            ':address' => $address,
            ':status'  => $status,
            ':notes'   => $notes,
            ':id'      => $id
        ]);

        logAudit('companies', 'update', $oldCompany['company_code'], $oldCompany, [
            'name' => $name, 'mobile' => $mobile, 'status' => $status
        ]);

        $db->commit();
        jsonResponse(true, 'Company details updated.', ['id' => $id, 'code' => $oldCompany['company_code']]);

    } else {
        // Create New Company with Auto Code
        $code = generateCode('companies', 'company_code', PREFIX_COMPANY, 3);
        $currentPayable = $openingPayable;

        $insStmt = $db->prepare("INSERT INTO companies 
            (company_code, name, contact_person, mobile, email, address, opening_payable, current_payable, status, notes) 
            VALUES (:code, :name, :cp, :mobile, :email, :address, :opening, :payable, :status, :notes)");

        $insStmt->execute([
            ':code'    => $code,
            ':name'    => $name,
            ':cp'      => $contactPerson,
            ':mobile'  => $mobile,
            ':email'   => $email,
            ':address' => $address,
            ':opening' => $openingPayable,
            ':payable' => $currentPayable,
            ':status'  => $status,
            ':notes'   => $notes
        ]);

        $newId = (int)$db->lastInsertId();

        // Create Company Ledger entry if Opening Payable > 0
        if ($openingPayable > 0) {
            $lStmt = $db->prepare("INSERT INTO company_ledger 
                (company_id, transaction_date, transaction_type, reference_id, description, debit, credit, running_balance, created_by)
                VALUES (:cid, CURDATE(), 'OPENING', :ref, 'Opening Payable Balance Recorded', :debit, 0.00, :run_bal, :uid)");
            
            $lStmt->execute([
                ':cid'     => $newId,
                ':ref'     => 'INIT-PAY-' . $code,
                ':debit'   => $openingPayable,
                ':run_bal' => $openingPayable,
                ':uid'     => $_SESSION['user_id'] ?? null
            ]);
        }

        logAudit('companies', 'create', $code, null, [
            'name' => $name, 'opening_payable' => $openingPayable, 'mobile' => $mobile
        ]);

        $db->commit();
        jsonResponse(true, 'Company created successfully.', ['id' => $newId, 'code' => $code]);
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(false, 'Failed to save company: ' . $e->getMessage(), [], 500);
}
