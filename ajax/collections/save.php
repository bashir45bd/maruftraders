<?php
/**
 * Maruf Traders - AJAX Save Collection
 */

define('APP_INIT', true);
define('IS_AJAX', true);
require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/includes/auth_check.php';
require_once ROOT_PATH . '/includes/permission_check.php';

requirePermission('collections.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    jsonResponse(false, 'Invalid CSRF token.', [], 403);
}

// Anti-Duplicate Submission Lock
preventDuplicateSubmission('col_save', $_POST);

$collectionId = (int)($_POST['id'] ?? 0);
$retailerId = (int)($_POST['retailer_id'] ?? 0);
$collectionDate = trim($_POST['collection_date'] ?? date('Y-m-d'));
$amount = (float)($_POST['amount'] ?? 0.00);
$paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
$bankAccount = trim($_POST['bank_account'] ?? '');
$transactionRef = trim($_POST['transaction_ref'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if (!$retailerId) {
    jsonResponse(false, 'Please select a Retailer.');
}

if ($amount <= 0) {
    jsonResponse(false, 'Collection amount must be greater than 0.');
}

$db = Database::getConnection();

try {
    assertMonthOpen($collectionDate);

    $db->beginTransaction();

    /*
     * Lock retailer row so two collections cannot
     * update the same balance incorrectly at the same time.
     */
    $rStmt = $db->prepare(
        "SELECT * FROM retailers WHERE id = :id FOR UPDATE"
    );
    $rStmt->execute([':id' => $retailerId]);
    $retailer = $rStmt->fetch();

    if (!$retailer) {
        throw new Exception("Retailer not found.");
    }

    // -------------------------------------------------------------
    // EDIT MODE
    // -------------------------------------------------------------
    if ($collectionId > 0) {
        $oldStmt = $db->prepare("SELECT * FROM collections WHERE id = :id FOR UPDATE");
        $oldStmt->execute([':id' => $collectionId]);
        $oldCol = $oldStmt->fetch();
        if (!$oldCol) throw new Exception("Collection record not found.");
        if ($oldCol['status'] === 'cancelled') throw new Exception("Cannot edit a cancelled collection.");

        // Recover previous split from audit log
        $alStmt = $db->prepare("SELECT new_data FROM audit_logs WHERE module = 'collections' AND reference_id = :ref ORDER BY id DESC LIMIT 1");
        $alStmt->execute([':ref' => $oldCol['collection_code']]);
        $auditRow = $alStmt->fetch();

        $dueRestored = (float)$oldCol['amount'];
        $advToRemove = 0.00;
        if ($auditRow && !empty($auditRow['new_data'])) {
            $snap = json_decode($auditRow['new_data'], true);
            if (isset($snap['old_due'], $snap['new_due'], $snap['old_advance'], $snap['new_advance'])) {
                $d = round((float)$snap['old_due'] - (float)$snap['new_due'], 2);
                $a = round((float)$snap['new_advance'] - (float)$snap['old_advance'], 2);
                if (round($d + $a, 2) === round((float)$oldCol['amount'], 2)) {
                    $dueRestored = $d;
                    $advToRemove = $a;
                }
            }
        }

        // Base balance before this collection took place
        $baseDue = max(0, round((float)$retailer['current_due'] + $dueRestored, 2));
        $baseAdvance = max(0, round((float)$retailer['advance_balance'] - $advToRemove, 2));

        if ($amount <= $baseDue) {
            $newDue = round($baseDue - $amount, 2);
            $newAdvance = $baseAdvance;
        } else {
            $extra = round($amount - $baseDue, 2);
            $newDue = 0.00;
            $newAdvance = round($baseAdvance + $extra, 2);
        }

        // Guarantee retailer never holds both due and advance simultaneously
        if ($newDue > 0 && $newAdvance > 0) {
            $settle = min($newDue, $newAdvance);
            $newDue = round($newDue - $settle, 2);
            $newAdvance = round($newAdvance - $settle, 2);
        }

        $updCol = $db->prepare("UPDATE collections SET 
            collection_date = :cdate, amount = :amt, payment_method = :pmethod, 
            bank_account = :bank, transaction_ref = :txref, notes = :notes 
            WHERE id = :id");
        $updCol->execute([
            ':cdate'   => $collectionDate,
            ':amt'     => $amount,
            ':pmethod' => $paymentMethod,
            ':bank'    => $bankAccount,
            ':txref'   => $transactionRef,
            ':notes'   => $notes,
            ':id'      => $collectionId
        ]);

        $uStmt = $db->prepare("UPDATE retailers SET current_due = :due, advance_balance = :adv WHERE id = :id");
        $uStmt->execute([':due' => $newDue, ':adv' => $newAdvance, ':id' => $retailerId]);

        $diff = round($amount - (float)$oldCol['amount'], 2);
        $desc = "Collection {$oldCol['collection_code']} edited (৳" . number_format($oldCol['amount'], 2) . " → ৳" . number_format($amount, 2) . ")";

        $rlStmt = $db->prepare("INSERT INTO retailer_ledger 
            (retailer_id, transaction_date, transaction_type, reference_id, description, debit, credit, running_balance, created_by)
            VALUES (:rid, :tdate, 'ADJUSTMENT', :ref, :desc, :deb, :cred, :run_bal, :uid)");
        $rlStmt->execute([
            ':rid'     => $retailerId,
            ':tdate'   => $collectionDate,
            ':ref'     => $oldCol['collection_code'],
            ':desc'    => $desc,
            ':deb'     => ($diff < 0 ? abs($diff) : 0.00),
            ':cred'    => ($diff > 0 ? $diff : 0.00),
            ':run_bal' => $newDue,
            ':uid'     => $_SESSION['user_id'] ?? null
        ]);

        logAudit('collections', 'update', $oldCol['collection_code'], $oldCol, [
            'old_amount' => $oldCol['amount'], 'new_amount' => $amount,
            'old_due' => $baseDue, 'new_due' => $newDue,
            'old_advance' => $baseAdvance, 'new_advance' => $newAdvance
        ]);

        $db->commit();
        jsonResponse(true, "Collection {$oldCol['collection_code']} updated successfully. Retailer balance updated.", [
            'code' => $oldCol['collection_code'], 'current_due' => $newDue, 'advance_balance' => $newAdvance
        ]);
    }


    /*
     * ---------------------------------------------------------
     * BALANCE CALCULATION
     * ---------------------------------------------------------
     *
     * current_due     = retailer owes us
     * advance_balance = retailer has extra money deposited
     *
     * Collection logic:
     *
     * 1. Collection first clears current_due.
     * 2. If collection is greater than current_due,
     *    the extra amount becomes advance.
     * 3. Existing advance is preserved.
     *
     * Example:
     *
     * Due = 20,000
     * Advance = 0
     * Collection = 30,000
     *
     * New Due = 0
     * New Advance = 10,000
     *
     * ---------------------------------------------------------
     */

    $oldDue = max(
        0,
        (float)$retailer['current_due']
    );

    $oldAdvance = max(
        0,
        (float)$retailer['advance_balance']
    );


    if ($amount <= $oldDue) {

        /*
         * Collection is enough only to reduce
         * the existing due.
         */
        $newDue = $oldDue - $amount;
        $newAdvance = $oldAdvance;

    } else {

        /*
         * Due is completely paid.
         *
         * Remaining amount becomes Advance.
         */
        $extraAmount = $amount - $oldDue;

        $newDue = 0;
        $newAdvance = $oldAdvance + $extraAmount;
    }

    // Guarantee retailer never holds both due and advance simultaneously
    if ($newDue > 0 && $newAdvance > 0) {
        $settle = min($newDue, $newAdvance);
        $newDue = round($newDue - $settle, 2);
        $newAdvance = round($newAdvance - $settle, 2);
    }


    // Generate Collection Code
    $colCode = generateCode(
        'collections',
        'collection_code',
        PREFIX_COLLECTION,
        5
    );


    // Insert Collection Record
    $cStmt = $db->prepare(
        "INSERT INTO collections
        (
            collection_code,
            retailer_id,
            collection_date,
            amount,
            payment_method,
            bank_account,
            transaction_ref,
            status,
            notes,
            created_by
        )
        VALUES
        (
            :code,
            :rid,
            :cdate,
            :amt,
            :pmethod,
            :bank,
            :txref,
            'active',
            :notes,
            :uid
        )"
    );

    $cStmt->execute([
        ':code'    => $colCode,
        ':rid'     => $retailerId,
        ':cdate'   => $collectionDate,
        ':amt'     => $amount,
        ':pmethod' => $paymentMethod,
        ':bank'    => $bankAccount,
        ':txref'   => $transactionRef,
        ':notes'   => $notes,
        ':uid'     => $_SESSION['user_id'] ?? null
    ]);


    /*
     * ---------------------------------------------------------
     * UPDATE RETAILER BALANCE
     * ---------------------------------------------------------
     */
    $uStmt = $db->prepare(
        "UPDATE retailers
         SET
            current_due = :due,
            advance_balance = :advance
         WHERE id = :id"
    );

    $uStmt->execute([
        ':due'     => $newDue,
        ':advance' => $newAdvance,
        ':id'      => $retailerId
    ]);


    /*
     * ---------------------------------------------------------
     * RETAILER LEDGER
     * ---------------------------------------------------------
     *
     * Ledger running_balance represents CURRENT DUE.
     *
     * If collection creates advance, the due becomes 0.
     * Advance is stored separately in retailers.advance_balance.
     *
     * ---------------------------------------------------------
     */
    $rlStmt = $db->prepare(
        "INSERT INTO retailer_ledger
        (
            retailer_id,
            transaction_date,
            transaction_type,
            reference_id,
            description,
            debit,
            credit,
            running_balance,
            created_by
        )
        VALUES
        (
            :rid,
            :tdate,
            'COLLECTION',
            :ref,
            :desc,
            0.00,
            :credit,
            :run_bal,
            :uid
        )"
    );

    $description =
        "Due Collection via {$paymentMethod}" .
        ($transactionRef
            ? " (Ref: {$transactionRef})"
            : ""
        );

    if ($amount > $oldDue) {

        $extraAmount = $amount - $oldDue;

        $description .=
            " | Due cleared; " .
            formatBDT($extraAmount) .
            " added to Advance";

    }

    $rlStmt->execute([
        ':rid'     => $retailerId,
        ':tdate'   => $collectionDate,
        ':ref'     => $colCode,
        ':desc'    => $description,
        ':credit'  => $amount,
        ':run_bal' => $newDue,
        ':uid'     => $_SESSION['user_id'] ?? null
    ]);


    /*
     * Audit Log
     */
    logAudit(
        'collections',
        'create',
        $colCode,
        null,
        [
            'retailer'       => $retailer['name'],
            'amount'         => $amount,
            'method'         => $paymentMethod,
            'old_due'        => $oldDue,
            'new_due'        => $newDue,
            'old_advance'    => $oldAdvance,
            'new_advance'    => $newAdvance
        ]
    );


    $db->commit();


    /*
     * Success message
     */
    $successMessage =
        "Collection of " .
        formatBDT($amount) .
        " received successfully ({$colCode}).";


    if ($amount > $oldDue) {

        $extraAmount = $amount - $oldDue;

        $successMessage .=
            " Due cleared and " .
            formatBDT($extraAmount) .
            " added to Advance Balance.";
    }


    jsonResponse(
        true,
        $successMessage,
        [
            'code'           => $colCode,
            'current_due'    => $newDue,
            'advance_balance' => $newAdvance
        ]
    );


} catch (Exception $e) {

    if ($db->inTransaction()) {
        $db->rollBack();
    }

    jsonResponse(
        false,
        'Collection failed: ' . $e->getMessage(),
        [],
        400
    );
}