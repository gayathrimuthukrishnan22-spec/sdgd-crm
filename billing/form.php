<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
// permission check happens below once we know if this is add or edit

$invoiceId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$invoice = $invoiceId !== null ? find_invoice($invoiceId) : null;

if ($invoiceId !== null && $invoice === false) {
    flash_set('error', 'Invoice not found.');
    redirect('billing/index.php');
}

require_permission('billing', $invoiceId !== null ? 'edit' : 'add');

$isEdit = $invoice !== null;
$existingItems = $isEdit ? get_invoice_items($invoiceId) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $data = [
        'client_id' => (int) ($_POST['client_id'] ?? 0),
        'project_id' => ($_POST['project_id'] ?? '') !== '' ? (int) $_POST['project_id'] : null,
        'invoice_date' => $_POST['invoice_date'] ?? date('Y-m-d'),
        'due_date' => ($_POST['due_date'] ?? '') !== '' ? $_POST['due_date'] : null,
        'status' => $_POST['status'] ?? 'draft',
        'gst_override' => $_POST['gst_override'] ?? 'auto',
        'discount_amount' => ($_POST['discount_amount'] ?? '') !== '' ? (float) $_POST['discount_amount'] : 0,
        'notes' => sanitize_string($_POST['notes'] ?? '') ?: null,
        'terms' => sanitize_string($_POST['terms'] ?? '') ?: null,
    ];

    // Amount Paid is only editable when editing an existing invoice
    // (a brand-new invoice always starts at 0 paid).
    if ($isEdit && isset($_POST['amount_paid']) && $_POST['amount_paid'] !== '') {
        $data['amount_paid'] = (float) $_POST['amount_paid'];
    }

    $descriptions = $_POST['description'] ?? [];
    $hsnCodes = $_POST['hsn_sac'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unitPrices = $_POST['unit_price'] ?? [];

    $items = [];
    foreach ($descriptions as $i => $desc) {
        $desc = sanitize_string($desc);
        if ($desc === '') {
            continue;
        }
        $items[] = [
            'description' => $desc,
            'hsn_sac' => sanitize_string($hsnCodes[$i] ?? '') ?: null,
            'quantity' => (float) ($quantities[$i] ?? 1),
            'unit_price' => (float) ($unitPrices[$i] ?? 0),
        ];
    }

    $error = $data['client_id'] <= 0 ? 'Please select a client.' : null;
    if ($error === null && find_client($data['client_id']) === false) {
        $error = 'Selected client was not found.';
    }
    if ($error === null && empty($items)) {
        $error = 'Add at least one line item.';
    }
    if ($error === null && !in_array($data['status'], invoice_status_options(), true)) {
        $error = 'Invalid status.';
    }
    if ($error === null && !in_array($data['gst_override'], ['auto', 'gst', 'non_gst'], true)) {
        $error = 'Invalid GST type.';
    }

    if ($error !== null) {
        flash_set('error', $error);
        redirect('billing/form.php' . ($isEdit ? '?id=' . $invoiceId : ''));
    }

    $currentUserId = (int) current_user()['id'];

    if ($isEdit) {
        update_invoice($invoiceId, $data, $items, $currentUserId);
        flash_set('status', 'Invoice updated successfully.');
        redirect('billing/view.php?id=' . $invoiceId);
    }

    $newId = create_invoice($data, $items, $currentUserId);
    flash_set('status', 'Invoice created successfully.');
    redirect('billing/view.php?id=' . $newId);
}

$clients = list_active_clients_for_select();
$projects = db()->query(
    "SELECT id, title, client_id FROM projects WHERE deleted_at IS NULL ORDER BY title ASC"
)->fetchAll();

$pageTitle = $isEdit ? 'Edit Invoice' : 'New Invoice';
$activeMenu = 'billing';
$breadcrumbs = [
    ['label' => 'GST Billing', 'url' => url('billing/index.php')],
    ['label' => $isEdit ? 'Edit' : 'New', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="card">
    <div class="card-header bg-white"><h2 class="h6 mb-0"><?= e($pageTitle) ?></h2></div>
    <div class="card-body">
        <?php if (empty($clients) && !$isEdit): ?>
            <div class="alert alert-warning">
                You need at least one active client before creating an invoice.
                <a href="<?= e(url('clients/form.php')) ?>">Add a client first</a>.
            </div>
        <?php endif; ?>
        <form method="POST" action="<?= e(url('billing/form.php' . ($isEdit ? '?id=' . $invoiceId : ''))) ?>" novalidate id="invoiceForm">
            <?= csrf_field() ?>

            <?php if ($isEdit): ?>
                <?php $balanceDue = (float) $invoice['total_amount'] - (float) $invoice['amount_paid']; ?>
                <div class="border rounded bg-light-subtle p-3 d-flex flex-wrap gap-4 align-items-end mb-3" id="paidSummary">
                    <div>
                        <div class="small text-muted mb-0">Total Amount</div>
                        <div class="fw-semibold">₹<?= e(number_format((float) $invoice['total_amount'], 2)) ?></div>
                    </div>
                    <div>
                        <label class="small text-muted mb-0" for="amountPaidInput">Total Paid So Far (₹)</label>
                        <input type="number" step="0.01" min="0" max="<?= e(number_format((float) $invoice['total_amount'], 2, '.', '')) ?>" name="amount_paid" id="amountPaidInput"
                               class="form-control form-control-sm" style="width:140px;"
                               value="<?= e(number_format((float) $invoice['amount_paid'], 2, '.', '')) ?>">
                    </div>
                    <div>
                        <div class="small text-muted mb-0">Balance Due</div>
                        <div class="fw-semibold <?= $balanceDue > 0 ? 'text-danger' : 'text-success' ?>" id="balanceDueDisplay">₹<?= e(number_format($balanceDue, 2)) ?></div>
                    </div>
                    <div class="w-100 small text-muted">
                        This is the running total paid to date (currently ₹<?= e(number_format((float) $invoice['amount_paid'], 2)) ?>) — not the amount of a new payment.
                        To record today's payment against the ₹<?= e(number_format($balanceDue, 2)) ?> balance without doing the addition yourself, use the
                        <a href="<?= e(url('billing/view.php?id=' . $invoiceId)) ?>#printArea">invoice page's Add Payment panel</a> instead — it's pre-filled with the balance and adds
                        to this total automatically. Editing the field here directly overwrites the total instead (use one method or the other, not both, for the same payment).
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-3 mb-3">
                <div class="col-12 col-md-4">
                    <label class="form-label">Client *</label>
                    <select name="client_id" class="form-select" id="clientSelect" required>
                        <option value="">Select client…</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= e((string) $c['id']) ?>" data-gstin="<?= e($c['gstin'] ?? '') ?>" <?= (int) ($invoice['client_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= e($c['company_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">Project (optional)</label>
                    <select name="project_id" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= e((string) $p['id']) ?>" <?= (int) ($invoice['project_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
                                <?= e($p['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Invoice Date</label>
                    <input type="date" name="invoice_date" class="form-control" value="<?= e($invoice['invoice_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Due Date</label>
                    <input type="date" name="due_date" class="form-control" value="<?= e($invoice['due_date'] ?? '') ?>">
                </div>
            </div>

            <h3 class="h6">Line Items</h3>
            <div class="table-responsive">
                <table class="table table-sm align-middle" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="min-width:220px;">Description</th>
                            <th style="width:110px;">HSN/SAC</th>
                            <th style="width:100px;">Qty</th>
                            <th style="width:140px;">Unit Price (₹)</th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rows = $isEdit ? $existingItems : [['description' => '', 'hsn_sac' => '', 'quantity' => 1, 'unit_price' => '']];
                        foreach ($rows as $row):
                        ?>
                            <tr>
                                <td><input type="text" name="description[]" class="form-control form-control-sm" value="<?= e($row['description'] ?? '') ?>"></td>
                                <td><input type="text" name="hsn_sac[]" class="form-control form-control-sm" value="<?= e($row['hsn_sac'] ?? '') ?>"></td>
                                <td><input type="number" step="0.01" name="quantity[]" class="form-control form-control-sm" value="<?= e((string) ($row['quantity'] ?? 1)) ?>"></td>
                                <td><input type="number" step="0.01" name="unit_price[]" class="form-control form-control-sm" value="<?= e((string) ($row['unit_price'] ?? '')) ?>"></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-trash"></i></button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm mb-3" id="addRow"><i class="bi bi-plus-lg"></i> Add Line</button>

            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <label class="form-label">Discount (₹)</label>
                    <input type="number" step="0.01" name="discount_amount" class="form-control" value="<?= e((string) ($invoice['discount_amount'] ?? '0')) ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" id="statusSelect">
                        <?php foreach (invoice_status_options() as $s): ?>
                            <?php $disableCompleted = $s === 'completed' && $isEdit && $balanceDue > 0.0; ?>
                            <option value="<?= e($s) ?>"
                                    <?= ($invoice['status'] ?? 'draft') === $s ? 'selected' : '' ?>
                                    <?= $disableCompleted ? 'disabled' : '' ?>>
                                <?= e(ucwords(str_replace('_', ' ', $s))) ?><?= $disableCompleted ? ' (balance still due)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isEdit && $balanceDue > 0.0): ?>
                        <div class="form-text">Completed is only available once the full ₹<?= e(number_format((float) $invoice['total_amount'], 2)) ?> is paid.</div>
                    <?php endif; ?>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">GST Type</label>
                    <select name="gst_override" class="form-select" id="gstOverrideSelect">
                        <?php foreach (invoice_gst_override_options() as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($invoice['gst_override'] ?? 'auto') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"><?= e($invoice['notes'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Terms &amp; Conditions</label>
                    <textarea name="terms" class="form-control" rows="2"><?= e($invoice['terms'] ?? 'Payment due within 15 days of invoice date.') ?></textarea>
                </div>
            </div>

            <div class="alert alert-secondary small mt-3 mb-0" id="gstInfoBanner">
                By default GST is calculated automatically from the client's GSTIN, or you can force
                this invoice to GST or Non-GST using the GST Type dropdown above.
                <span id="gstClientStatus"></span>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Invoice' ?></button>
                <a href="<?= e(url('billing/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('addRow').addEventListener('click', function () {
    const tbody = document.querySelector('#itemsTable tbody');
    const row = tbody.rows[0].cloneNode(true);
    row.querySelectorAll('input').forEach(function (input) { input.value = input.type === 'number' && input.name === 'quantity[]' ? '1' : ''; });
    tbody.appendChild(row);
});
document.querySelector('#itemsTable tbody').addEventListener('click', function (e) {
    const btn = e.target.closest('.remove-row');
    if (!btn) return;
    const tbody = document.querySelector('#itemsTable tbody');
    if (tbody.rows.length > 1) btn.closest('tr').remove();
});

function updateGstStatus() {
    const select = document.getElementById('clientSelect');
    const statusEl = document.getElementById('gstClientStatus');
    const override = document.getElementById('gstOverrideSelect').value;

    if (override === 'gst') { statusEl.textContent = ' GST Type is set to Force GST — 18% GST will be added regardless of client GSTIN.'; return; }
    if (override === 'non_gst') { statusEl.textContent = ' GST Type is set to Force Non-GST — no GST will be added regardless of client GSTIN.'; return; }

    const opt = select.options[select.selectedIndex];
    if (!opt || !opt.value) { statusEl.textContent = ''; return; }
    const gstin = opt.getAttribute('data-gstin') || '';
    statusEl.textContent = gstin
        ? ' Selected client has GSTIN ' + gstin + ' — 18% GST will be added.'
        : ' Selected client has no GSTIN on file — GST will NOT be added.';
}
document.getElementById('clientSelect').addEventListener('change', updateGstStatus);
document.getElementById('gstOverrideSelect').addEventListener('change', updateGstStatus);
updateGstStatus();

<?php if ($isEdit): ?>
(function () {
    const paidInput = document.getElementById('amountPaidInput');
    const balanceEl = document.getElementById('balanceDueDisplay');
    const statusSelect = document.getElementById('statusSelect');
    const form = document.getElementById('invoiceForm');
    const totalAmount = <?= (float) $invoice['total_amount'] ?>;

    if (paidInput && balanceEl) {
        paidInput.addEventListener('input', function () {
            // Auto-clamp: typing more than the invoice total snaps the
            // field back to the total right away, instead of only being
            // capped silently after the form is submitted.
            if (parseFloat(paidInput.value) > totalAmount) {
                paidInput.value = totalAmount.toFixed(2);
            }
            const paid = parseFloat(paidInput.value) || 0;
            const balance = totalAmount - paid;
            balanceEl.textContent = '₹' + balance.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            balanceEl.classList.toggle('text-danger', balance > 0);
            balanceEl.classList.toggle('text-success', balance <= 0);

            // Completed only becomes selectable once the balance hits
            // zero — matches the server-side rule so the option isn't
            // stuck disabled after the user finishes paying it off here.
            const completedOption = statusSelect ? statusSelect.querySelector('option[value="completed"]') : null;
            if (completedOption) {
                completedOption.disabled = balance > 0;
                completedOption.textContent = balance > 0 ? 'Completed (balance still due)' : 'Completed';
                if (balance > 0 && statusSelect.value === 'completed') {
                    statusSelect.value = paid > 0 ? 'partially_paid' : 'sent';
                }
            }
        });
    }

})();
<?php endif; ?>
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
