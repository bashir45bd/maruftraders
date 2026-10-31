<?php
/**
 * Maruf Traders - Retailer Management Module
 */

define('APP_INIT', true);
$pageTitle = 'Retailer Management';
$breadcrumb = 'Retailers';
$activeMenu = 'retailers';

require_once __DIR__ . '/../../includes/header.php';
requirePermission('retailers.manage');

$db = Database::getConnection();

// Handle manual balance sync request
if (isset($_GET['sync_id'])) {
    $syncId = (int)$_GET['sync_id'];
    recalculateRetailerBalanceFromLedger($syncId, $db);
    header("Location: index.php?msg=" . urlencode("রিটেইলারের বকেয়া ও অগ্রিম ব্যালেন্স সফলভাবে রিক্যালকুলেট করা হয়েছে!"));
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'sync_all') {
    $allR = $db->query("SELECT id FROM retailers")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($allR as $rid) {
        recalculateRetailerBalanceFromLedger((int)$rid, $db);
    }
    header("Location: index.php?msg=" . urlencode("সকল রিটেইলারের বকেয়া ও অগ্রিম ব্যালেন্স সফলভাবে রিক্যালকুলেট করা হয়েছে!"));
    exit;
}

// Auto-settle any retailers that currently have BOTH due and advance
$conflicts = $db->query("SELECT id FROM retailers WHERE current_due > 0 AND advance_balance > 0")->fetchAll(PDO::FETCH_COLUMN);
if (!empty($conflicts)) {
    foreach ($conflicts as $cid) {
        autoSettleRetailerAdvanceDue((int)$cid, $db);
    }
}

// Fetch all retailers
$stmt = $db->query("SELECT * FROM retailers ORDER BY id DESC");
$retailers = $stmt->fetchAll();

// Calculate total statistics
$totalDue = 0;
$totalAdvance = 0;
foreach ($retailers as $r) {
    $totalDue += (float)$r['current_due'];
    $totalAdvance += (float)$r['advance_balance'];
}
?>

<div class="page-header-container">
    <div>
        <h2 class="page-title">Retailer Management (রিটেইলার ম্যানেজমেন্ট)</h2>
        <div class="page-subtitle">Manage retail customers, credit limits, advance balances and ledger statements.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="?action=sync_all" class="btn btn-secondary-custom" onclick="return confirm('সকল রিটেইলারের বকেয়া ও অগ্রিম ব্যালেন্স লেজারের সাথে রিক্যালকুলেট ও সমন্বয় করবেন?');" title="Recalculate all party balances from ledger">
            <i class="fa-solid fa-arrows-rotate me-1 text-success"></i> Auto Sync All Balances
        </a>
        <a href="<?php echo BASE_URL; ?>/modules/retailers/due_report.php" class="btn btn-secondary-custom">
            <i class="fa-solid fa-file-invoice-dollar me-1"></i> Due Report
        </a>
        <button type="button" class="btn btn-primary-custom" onclick="openRetailerModal()">
            <i class="fa-solid fa-user-plus me-1"></i> Add Retailer
        </button>
    </div>
</div>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 py-2" role="alert" style="background: rgba(34, 197, 94, 0.15); border-color: rgba(34, 197, 94, 0.3); color: #4ade80;">
        <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($_GET['msg']); ?>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- KPI Summary Strip -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="dark-card py-3">
            <div class="text-secondary small fw-bold text-uppercase">Total Retailers</div>
            <div class="fs-4 fw-bold" style="color: var(--text-primary);"><?php echo count($retailers); ?> Parties</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="dark-card py-3">
            <div class="text-secondary small fw-bold text-uppercase">Total Outstanding Due</div>
            <div class="fs-4 fw-bold text-danger"><?php echo formatBDT($totalDue); ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="dark-card py-3">
            <div class="text-secondary small fw-bold text-uppercase">Total Advance Deposited</div>
            <div class="fs-4 fw-bold text-success"><?php echo formatBDT($totalAdvance); ?></div>
        </div>
    </div>
</div>

<!-- Retailers Table Card -->
<div class="dark-card">
    <div class="card-header-clean">
        <div class="card-title-clean">
            <i class="fa-solid fa-list text-primary-light"></i>
            <span>All Registered Retailers</span>
        </div>
        <div class="d-flex gap-2">
            <input type="text" id="retailerSearchInput" class="form-control-custom" placeholder="Search name, code, mobile..." style="width: 250px;">
        </div>
    </div>

    <div class="table-responsive">
        <table class="dark-table" id="retailersTable">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Retailer Name</th>
                    <th>Mobile</th>
                    <th>Address</th>
                    <th class="text-end">Credit Limit</th>
                    <th class="text-end">Current Due</th>
                    <th class="text-end">Advance Bal</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($retailers)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No retailers registered yet. Click "Add Retailer" to create one.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($retailers as $ret): ?>
                        <?php 
                            $isOverLimit = ((float)$ret['current_due'] > (float)$ret['credit_limit'] && (float)$ret['credit_limit'] > 0);
                        ?>
                        <tr class="retailer-row">
                            <td class="fw-bold text-info"><?php echo htmlspecialchars($ret['retailer_code']); ?></td>
                            <td>
                                <div class="fw-bold" style="color: var(--text-primary);"><?php echo htmlspecialchars($ret['name']); ?></div>
                                <?php if ($isOverLimit): ?>
                                    <span class="badge-custom badge-warning" style="font-size: 0.65rem;">
                                        <i class="fa-solid fa-triangle-exclamation"></i> Over Credit Limit
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($ret['mobile']); ?></td>
                            <td class="text-secondary small"><?php echo htmlspecialchars($ret['address'] ?: '—'); ?></td>
                            <td class="text-end"><?php echo formatBDT($ret['credit_limit']); ?></td>
                            <td class="text-end fw-bold <?php echo ((float)$ret['current_due'] > 0) ? 'text-danger' : 'text-success'; ?>">
                                <?php echo formatBDT($ret['current_due']); ?>
                            </td>
                            <td class="text-end text-success fw-bold"><?php echo formatBDT($ret['advance_balance']); ?></td>
                            <td class="text-center">
                                <span class="badge-custom <?php echo ($ret['status'] === 'active') ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo ucfirst($ret['status']); ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="?sync_id=<?php echo $ret['id']; ?>" class="btn btn-secondary-custom" title="Re-sync / Recalculate Balance" onclick="return confirm('এই রিটেইলারের ব্যালেন্স লেজারের সাথে রিক্যালকুলেট ও সমন্বয় করবেন?');">
                                        <i class="fa-solid fa-arrows-rotate text-success"></i>
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>/modules/retailers/ledger.php?id=<?php echo $ret['id']; ?>" class="btn btn-secondary-custom" title="View Statement / Ledger">
                                        <i class="fa-solid fa-book-open text-cyan"></i>
                                    </a>
                                    <button type="button" class="btn btn-secondary-custom" onclick="editRetailer(<?php echo $ret['id']; ?>)" title="Edit Retailer">
                                        <i class="fa-solid fa-pen-to-square text-primary-light"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add / Edit Retailer Modal -->
<div class="modal fade" id="retailerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content dark-modal">
            <div class="modal-header dark-modal-header">
                <h5 class="modal-title" style="color: var(--text-primary);" id="retailerModalTitle">Add New Retailer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="retailerForm">
                <input type="hidden" name="id" id="ret_id">
                <?php echo csrfField(); ?>

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="ret_name" class="form-label-custom">Retailer / Business Name <span class="required">*</span></label>
                        <input type="text" class="form-control form-control-custom" id="ret_name" name="name" required placeholder="e.g. M/S Bismillah Enterprise">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="ret_mobile" class="form-label-custom">Mobile Number <span class="required">*</span></label>
                            <input type="text" class="form-control form-control-custom" id="ret_mobile" name="mobile" required placeholder="017xxxxxxxx">
                        </div>
                        <div class="col-md-6">
                            <label for="ret_credit_limit" class="form-label-custom">Credit Limit (৳)</label>
                            <input type="number" step="0.01" class="form-control form-control-custom" id="ret_credit_limit" name="credit_limit" value="100000.00">
                        </div>
                    </div>

                    <div class="mb-3" id="openingBalancesRow">
                        <div class="row g-3">
                            <div class="col-md-6" id="openingDueCol">
                                <label for="ret_opening_balance" class="form-label-custom">Opening Due Balance (৳)</label>
                                <input type="number" step="0.01" min="0" class="form-control form-control-custom" id="ret_opening_balance" name="opening_balance" value="0.00">
                            </div>
                            <div class="col-md-6" id="advanceCol">
                                <label for="ret_advance_balance" class="form-label-custom">Opening Advance (৳)</label>
                                <input type="number" step="0.01" min="0" class="form-control form-control-custom" id="ret_advance_balance" name="advance_balance" value="0.00">
                            </div>
                        </div>
                        <div class="small text-warning mt-2" id="openingBalanceWarning" style="display:none;">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            Opening Due change korle Current Due ar Ledger automatically adjust hobe.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="ret_address" class="form-label-custom">Address / Location</label>
                        <textarea class="form-control form-control-custom" id="ret_address" name="address" rows="2" placeholder="e.g. সুন্দরপুর বাজার, চুনারুঘাট"></textarea>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="ret_status" class="form-label-custom">Status</label>
                            <select class="form-select form-select-custom" id="ret_status" name="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="ret_notes" class="form-label-custom">Notes</label>
                            <input type="text" class="form-control form-control-custom" id="ret_notes" name="notes" placeholder="Optional notes">
                        </div>
                    </div>
                </div>

                <div class="modal-footer dark-modal-footer">
                    <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom" id="saveRetailerBtn">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Retailer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let retailerModalInstance = null;

function getRetailerModal() {
    if (!retailerModalInstance) {
        retailerModalInstance = new bootstrap.Modal(document.getElementById('retailerModal'));
    }
    return retailerModalInstance;
}

function openRetailerModal() {
    document.getElementById('retailerForm').reset();
    document.getElementById('ret_id').value = '';
    document.getElementById('retailerModalTitle').innerText = 'Add New Retailer';

    // Add mode: both opening fields visible and enabled
    document.getElementById('openingBalancesRow').style.display = 'block';
    document.getElementById('advanceCol').style.display = '';
    document.getElementById('ret_advance_balance').disabled = false;
    document.getElementById('openingBalanceWarning').style.display = 'none';

    getRetailerModal().show();
}

async function editRetailer(id) {
    try {
        const res = await apiRequest(`${window.BASE_URL}/ajax/retailers/get.php?id=${id}`);
        if (res.success && res.data.retailer) {
            const r = res.data.retailer;
            document.getElementById('ret_id').value = r.id;
            document.getElementById('ret_name').value = r.name;
            document.getElementById('ret_mobile').value = r.mobile;
            document.getElementById('ret_address').value = r.address || '';
            document.getElementById('ret_credit_limit').value = r.credit_limit;
            document.getElementById('ret_status').value = r.status;
            document.getElementById('ret_notes').value = r.notes || '';

            // Edit mode: Opening Due editable, Opening Advance hidden
            // (advance_balance is the CURRENT advance, so it must not be overwritten here)
            document.getElementById('ret_opening_balance').value = r.opening_balance ?? 0;
            document.getElementById('openingBalancesRow').style.display = 'block';
            document.getElementById('advanceCol').style.display = 'none';
            document.getElementById('ret_advance_balance').disabled = true; // disabled fields are not submitted
            document.getElementById('openingBalanceWarning').style.display = 'block';

            document.getElementById('retailerModalTitle').innerText = 'Edit Retailer: ' + r.retailer_code;

            getRetailerModal().show();
        }
    } catch (e) {
        console.error(e);
    }
}

// Search Filter
document.getElementById('retailerSearchInput').addEventListener('input', function() {
    const val = this.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#retailersTable tbody tr.retailer-row');
    rows.forEach(r => {
        r.style.display = r.textContent.toLowerCase().includes(val) ? '' : 'none';
    });
});

// AJAX form submit
document.getElementById('retailerForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const form = e.target;
    const formData = new FormData(form);
    const saveBtn = document.getElementById('saveRetailerBtn');
    const originalBtnHtml = saveBtn.innerHTML;

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving...';

    try {
        const res = await fetch(`${window.BASE_URL}/ajax/retailers/save.php`, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const result = await res.json();

        if (result.success) {
            if (retailerModalInstance) retailerModalInstance.hide();
            setTimeout(() => window.location.reload(), 500);
        } else {
            alert(result.message || 'Failed to save retailer.');
        }
    } catch (err) {
        console.error('Retailer save failed:', err);
        alert('Something went wrong while saving. Check the browser console for details.');
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalBtnHtml;
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>