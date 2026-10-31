<?php
/**
 * Maruf Traders - Company Payments Module
 */

define('APP_INIT', true);
$pageTitle = 'Company Payments';
$breadcrumb = 'Company Payments';
$activeMenu = 'company_payments';

require_once __DIR__ . '/../../includes/header.php';
requirePermission('company_payments.manage');

$db = Database::getConnection();

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$companyId = (int)($_GET['company_id'] ?? 0);

$cStmt = $db->query("SELECT id, name, company_code, current_payable FROM companies WHERE status = 'active' ORDER BY name ASC");
$allCompanies = $cStmt->fetchAll();

$sql = "SELECT cp.*, c.company_code, c.name as company_name 
        FROM company_payments cp
        JOIN companies c ON cp.company_id = c.id
        WHERE cp.payment_date BETWEEN :start AND :end";
$params = [':start' => $startDate, ':end' => $endDate];

if ($companyId) {
    $sql .= " AND cp.company_id = :cid";
    $params[':cid'] = $companyId;
}

$sql .= " ORDER BY cp.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$totalPaid = 0;
foreach ($payments as $p) {
    if ($p['status'] === 'active') {
        $totalPaid += (float)$p['amount'];
    }
}
?>

<div class="page-header-container no-print">
    <div>
        <h2 class="page-title">Company Payments (কোম্পানি পেমেন্ট ও পরিশোধ)</h2>
        <div class="page-subtitle">Record bank transfers, cheques and cash payments made to cement manufacturers.</div>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary-custom" onclick="openPaymentModal()">
            <i class="fa-solid fa-money-bill-transfer me-1"></i> Make Company Payment
        </button>
    </div>
</div>

<!-- Filter Bar -->
<div class="dark-card mb-4 no-print">
    <form method="GET" action="" class="row g-3 align-items-end">
        <div class="col-md-4">
            <label for="company_id" class="form-label-custom">Filter Company</label>
            <select name="company_id" id="company_id" class="form-select form-select-custom">
                <option value="">-- All Companies --</option>
                <?php foreach ($allCompanies as $comp): ?>
                    <option value="<?php echo $comp['id']; ?>" <?php echo ($comp['id'] == $companyId) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($comp['company_code'] . ' — ' . $comp['name']); ?> (Payable: <?php echo formatBDT($comp['current_payable']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label for="start_date" class="form-label-custom">From Date</label>
            <input type="date" name="start_date" id="start_date" class="form-control form-control-custom" value="<?php echo htmlspecialchars($startDate); ?>">
        </div>
        <div class="col-md-3">
            <label for="end_date" class="form-label-custom">To Date</label>
            <input type="date" name="end_date" id="end_date" class="form-control form-control-custom" value="<?php echo htmlspecialchars($endDate); ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary-custom w-100">
                <i class="fa-solid fa-filter me-1"></i> Filter
            </button>
        </div>
    </form>
</div>

<!-- KPI Strip -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="dark-card py-3">
            <div class="text-secondary small fw-bold text-uppercase">Payments Made Count</div>
            <div class="fs-4 fw-bold" style="color: var(--text-primary);"><?php echo count($payments); ?> Vouchers</div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="dark-card py-3">
            <div class="text-secondary small fw-bold text-uppercase">Total Period Company Payments</div>
            <div class="fs-4 fw-bold text-success"><?php echo formatBDT($totalPaid); ?></div>
        </div>
    </div>
</div>

<!-- Payments Table -->
<div class="dark-card">
    <div class="card-header-clean">
        <div class="card-title-clean">
            <i class="fa-solid fa-building-columns text-primary-light"></i>
            <span>Payment Vouchers</span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="dark-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Payment Code</th>
                    <th>Company / Supplier</th>
                    <th>Payment Method</th>
                    <th>Bank / Cheque Ref</th>
                    <th class="text-end">Amount Paid (৳)</th>
                    <th class="text-center">Status</th>
                    <th>Notes</th>
                    <th class="text-end no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No payments recorded for the selected period.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <?php $isVoid = ($p['status'] === 'cancelled'); ?>
                        <tr>
                            <td><?php echo formatDate($p['payment_date']); ?></td>
                            <td class="fw-bold text-info"><?php echo htmlspecialchars($p['payment_code']); ?></td>
                            <td class="fw-bold" style="color: var(--text-primary);"><?php echo htmlspecialchars($p['company_name']); ?></td>
                            <td>
                                <span class="badge-custom badge-primary"><?php echo htmlspecialchars($p['payment_method']); ?></span>
                            </td>
                            <td class="text-secondary"><?php echo htmlspecialchars($p['reference_no'] ?: ($p['bank_name'] ?: '—')); ?></td>
                            <td class="text-end fw-bold text-success fs-6"><?php echo formatBDT($p['amount']); ?></td>
                            <td class="text-center">
                                <span class="badge-custom <?php echo ($p['status'] === 'active') ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo ucfirst($p['status']); ?>
                                </span>
                            </td>
                            <td class="text-secondary small"><?php echo htmlspecialchars($p['notes'] ?: '—'); ?></td>
                            <td class="text-end no-print">
                                <?php if (!$isVoid && hasPermission('company_payments.manage')): ?>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-secondary-custom btn-sm" onclick='editCompanyPayment(<?php echo htmlspecialchars(json_encode($p), ENT_QUOTES); ?>)' title="Edit Payment">
                                            <i class="fa-solid fa-pen-to-square text-cyan"></i>
                                        </button>
                                        <button type="button" class="btn btn-danger-custom btn-sm" onclick="cancelCompanyPayment(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars($p['payment_code'], ENT_QUOTES); ?>')" title="Cancel Payment">
                                            <i class="fa-solid fa-ban"></i>
                                        </button>
                                    </div>
                                <?php elseif ($isVoid): ?>
                                    <span class="text-secondary small" title="<?php echo htmlspecialchars($p['cancel_reason'] ?: ''); ?>">
                                        <i class="fa-solid fa-circle-info"></i> Voided
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Make Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content dark-modal">
            <div class="modal-header dark-modal-header">
                <h5 class="modal-title" id="payModalTitle" style="color: var(--text-primary);">Record Company Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="paymentForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="id" id="pay_id" value="0">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="pay_company_id" class="form-label-custom">Company / Supplier <span class="required">*</span></label>
                        <select class="form-select form-select-custom" id="pay_company_id" name="company_id" required onchange="onCompanyPayChange()">
                            <option value="">-- Select Company --</option>
                            <?php foreach ($allCompanies as $comp): ?>
                                <option value="<?php echo $comp['id']; ?>" data-payable="<?php echo $comp['current_payable']; ?>">
                                    <?php echo htmlspecialchars($comp['company_code'] . ' — ' . $comp['name']); ?> (Payable: <?php echo formatBDT($comp['current_payable']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="pay_date" class="form-label-custom">Payment Date <span class="required">*</span></label>
                            <input type="date" class="form-control form-control-custom" id="pay_date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="pay_amount" class="form-label-custom">Amount (৳) <span class="required">*</span></label>
                            <input type="number" step="0.01" class="form-control form-control-custom" id="pay_amount" name="amount" min="1" required placeholder="0.00">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="pay_pmethod" class="form-label-custom">Payment Method</label>
                            <select class="form-select form-select-custom" id="pay_pmethod" name="payment_method">
                                <option value="Bank">Bank Transfer / Online</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Cash">Cash</option>
                                <option value="bKash">bKash</option>
                                <option value="Nagad">Nagad</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="pay_ref" class="form-label-custom">Cheque / TrxID / Deposit Ref</label>
                            <input type="text" class="form-control form-control-custom" id="pay_ref" name="reference_no" placeholder="e.g. Cheque #492812">
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="pay_bank" class="form-label-custom">Bank Name / Branch</label>
                            <input type="text" class="form-control form-control-custom" id="pay_bank" name="bank_name" placeholder="e.g. Islami Bank, Chunarughat">
                        </div>
                        <div class="col-md-6">
                            <label for="pay_notes" class="form-label-custom">Notes</label>
                            <input type="text" class="form-control form-control-custom" id="pay_notes" name="notes" placeholder="Optional notes">
                        </div>
                    </div>
                </div>

                <div class="modal-footer dark-modal-footer">
                    <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom" id="savePayBtn">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let payModalInstance = null;
let isPaySubmitting = false;

function openPaymentModal() {
    document.getElementById('paymentForm').reset();
    document.getElementById('pay_id').value = '0';
    document.getElementById('payModalTitle').textContent = 'Record Company Payment';
    document.getElementById('pay_date').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('pay_company_id').disabled = false;
    if (!payModalInstance) {
        payModalInstance = new bootstrap.Modal(document.getElementById('paymentModal'));
    }
    payModalInstance.show();
}

function editCompanyPayment(p) {
    if (!payModalInstance) {
        payModalInstance = new bootstrap.Modal(document.getElementById('paymentModal'));
    }
    document.getElementById('paymentForm').reset();
    document.getElementById('pay_id').value = p.id;
    document.getElementById('payModalTitle').textContent = 'Edit Company Payment (' + (p.payment_code || '') + ')';
    
    document.getElementById('pay_company_id').value = p.company_id;
    document.getElementById('pay_date').value = p.payment_date;
    document.getElementById('pay_amount').value = p.amount;
    document.getElementById('pay_pmethod').value = p.payment_method || 'Bank';
    document.getElementById('pay_ref').value = p.reference_no || '';
    document.getElementById('pay_bank').value = p.bank_name || '';
    document.getElementById('pay_notes').value = p.notes || '';
    
    onCompanyPayChange();
    payModalInstance.show();
}

async function cancelCompanyPayment(id, paymentCode) {
    if (!confirm(`Are you sure you want to cancel payment voucher ${paymentCode}? This will revert the company balance.`)) {
        return;
    }
    const reason = prompt('Please enter a cancellation reason:');
    if (reason === null) return;

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('cancel_reason', reason);
        formData.append('csrf_token', '<?php echo getCsrfToken(); ?>');

        const res = await fetch(`${window.BASE_URL}/ajax/company_payments/cancel.php`, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await res.json();
        if (result.success) {
            alert(result.message || 'Payment cancelled successfully.');
            window.location.reload();
        } else {
            alert(result.message || 'Failed to cancel payment.');
        }
    } catch (err) {
        console.error('Cancel payment error:', err);
        alert('An error occurred while cancelling the payment.');
    }
}

function onCompanyPayChange() {
    const sel = document.getElementById('pay_company_id');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.payable) {
        const payable = parseFloat(opt.dataset.payable) || 0;
        document.getElementById('pay_amount').placeholder = `Current Payable: ${payable}`;
    }
}

/**
 * Direct fetch()-based AJAX submit handler with double-click locking.
 */
document.getElementById('paymentForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    if (isPaySubmitting) return;

    const form = e.target;
    const formData = new FormData(form);
    const saveBtn = document.getElementById('savePayBtn');
    const originalBtnHtml = saveBtn.innerHTML;

    isPaySubmitting = true;
    saveBtn.disabled = true;
    saveBtn.style.pointerEvents = 'none';
    saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving...';

    try {
        const res = await fetch(`${window.BASE_URL}/ajax/company_payments/save.php`, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const rawText = await res.text();
        let result;
        try {
            result = JSON.parse(rawText);
        } catch (jsonErr) {
            console.error('Non-JSON response:', rawText);
            alert('Server error: ' + (rawText || 'Invalid response from server.'));
            return;
        }

        if (result.success) {
            if (payModalInstance) payModalInstance.hide();
            setTimeout(() => window.location.reload(), 500);
        } else {
            alert(result.message || 'Failed to save company payment.');
        }
    } catch (err) {
        console.error('Company payment save failed:', err);
        alert('Something went wrong while saving. Check the browser console for details.');
    } finally {
        saveBtn.disabled = false;
        saveBtn.style.pointerEvents = 'auto';
        saveBtn.innerHTML = originalBtnHtml;
        isPaySubmitting = false;
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>