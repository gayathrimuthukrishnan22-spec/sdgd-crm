<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('accounts');

$availableYears = financial_years_with_data();
$financialYear = $_GET['fy'] ?? financial_year_for(date('Y-m-d'));
if (!in_array($financialYear, $availableYears, true)) {
    $financialYear = financial_year_for(date('Y-m-d'));
}

['start' => $startDate, 'end' => $endDate] = financial_year_bounds($financialYear);

$gstInvoices = gst_invoices_for_period($startDate, $endDate);
$gstTotals = gst_totals_for_period($startDate, $endDate);

// Rows-per-page selector for the invoice-wise breakdown table below.
// The full $gstInvoices list is kept as-is (used for totals and for the
// print/PDF view, which must always show every invoice regardless of
// which page is open on screen).
$gstPerPageOptions = [10, 25, 50, 100];
$gstPerPage = (int) ($_GET['per_page'] ?? 25);
if (!in_array($gstPerPage, $gstPerPageOptions, true)) {
    $gstPerPage = 25;
}
$gstPagination = paginate(count($gstInvoices), $gstPerPage);
$pagedGstInvoices = array_slice($gstInvoices, $gstPagination['offset'], $gstPagination['perPage']);
$gstRangeStart = count($gstInvoices) > 0 ? $gstPagination['offset'] + 1 : 0;
$gstRangeEnd = min($gstPagination['offset'] + $gstPagination['perPage'], count($gstInvoices));
$income = total_income($startDate, $endDate);
$expenses = total_expenses($startDate, $endDate);
$net = $income - $expenses;
$expenseCategories = expenses_by_category($startDate, $endDate);

$pageTitle = 'GST Report — FY ' . $financialYear;
$activeMenu = 'accounts';
$breadcrumbs = [
    ['label' => 'Accounts', 'url' => url('accounts/index.php')],
    ['label' => 'GST Report', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <h1 class="h4 mb-0">GST Report</h1>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <form method="GET" action="<?= e(url('accounts/gst_report.php')) ?>" class="d-flex align-items-center gap-2">
            <label for="fySelect" class="form-label small mb-0">Financial Year</label>
            <select id="fySelect" name="fy" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($availableYears as $fy): ?>
                    <option value="<?= e($fy) ?>" <?= $fy === $financialYear ? 'selected' : '' ?>>FY <?= e($fy) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <button type="button" class="btn btn-primary btn-sm" id="printBtn"><i class="bi bi-printer"></i> Print / Save as PDF</button>
        <a href="<?= e(url('accounts/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Back</a>
    </div>
</div>

<div id="printArea">
    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
                <div>
                    <h5 class="mb-1"><?= e(company_name()) ?></h5>
                    <?php if (company_gstin()): ?><div class="small text-muted">GSTIN: <?= e(company_gstin()) ?></div><?php endif; ?>
                    <?php if (company_mobile()): ?><div class="small text-muted">Mobile: <?= e(company_mobile()) ?></div><?php endif; ?>
                </div>
                <div class="text-md-end">
                    <div class="fw-semibold">Income &amp; Expense / GST Report</div>
                    <div class="text-muted small">Financial Year <?= e($financialYear) ?> (<?= e(date('d M Y', strtotime($startDate))) ?> – <?= e(date('d M Y', strtotime($endDate))) ?>)</div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="border rounded p-2 text-center">
                        <div class="text-muted small">Income Collected</div>
                        <div class="fs-5 fw-semibold">₹<?= e(number_format($income, 2)) ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-2 text-center">
                        <div class="text-muted small">Expenses</div>
                        <div class="fs-5 fw-semibold">₹<?= e(number_format($expenses, 2)) ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-2 text-center">
                        <div class="text-muted small">Net</div>
                        <div class="fs-5 fw-semibold <?= $net < 0 ? 'text-danger' : '' ?>">₹<?= e(number_format($net, 2)) ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-2 text-center">
                        <div class="text-muted small">GST Collected</div>
                        <div class="fs-5 fw-semibold">₹<?= e(number_format($gstTotals['cgst_amount'] + $gstTotals['sgst_amount'] + $gstTotals['igst_amount'], 2)) ?></div>
                    </div>
                </div>
            </div>

            <h2 class="h6 mb-2">GST Summary (for filing)</h2>
            <div class="table-responsive mb-4">
                <table class="table table-bordered table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Invoices</th>
                            <th class="text-end">Taxable Value</th>
                            <th class="text-end">CGST</th>
                            <th class="text-end">SGST</th>
                            <th class="text-end">IGST</th>
                            <th class="text-end">Total Invoice Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= e((string) $gstTotals['invoice_count']) ?></td>
                            <td class="text-end">₹<?= e(number_format($gstTotals['taxable_amount'], 2)) ?></td>
                            <td class="text-end">₹<?= e(number_format($gstTotals['cgst_amount'], 2)) ?></td>
                            <td class="text-end">₹<?= e(number_format($gstTotals['sgst_amount'], 2)) ?></td>
                            <td class="text-end">₹<?= e(number_format($gstTotals['igst_amount'], 2)) ?></td>
                            <td class="text-end fw-semibold">₹<?= e(number_format($gstTotals['total_amount'], 2)) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h2 class="h6 mb-0">Invoice-wise GST Breakdown</h2>
                <div class="d-flex align-items-center gap-2 no-print">
                    <label for="gstPerPage" class="form-label small mb-0 text-nowrap">Rows per page</label>
                    <select id="gstPerPage" class="form-select form-select-sm" style="width: auto;">
                        <?php foreach ($gstPerPageOptions as $opt): ?>
                            <option value="<?= e((string) $opt) ?>" <?= $opt === $gstPagination['perPage'] ? 'selected' : '' ?>><?= e((string) $opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Screen view: paginated -->
            <div class="table-responsive mb-2 gst-invoice-table-screen">
                <table class="table table-striped table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>GST No.</th>
                            <th>Date</th>
                            <th>Client</th>
                            <th class="text-end">Taxable</th>
                            <th class="text-end">CGST</th>
                            <th class="text-end">SGST</th>
                            <th class="text-end">IGST</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pagedGstInvoices)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">No invoices issued in FY <?= e($financialYear) ?>.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($pagedGstInvoices as $inv): ?>
                            <tr>
                                <td><?= e($inv['invoice_number']) ?></td>
                                <td><?= e($inv['client_gstin'] ?? '—') ?></td>
                                <td><?= e(date('d-M-Y', strtotime($inv['invoice_date']))) ?></td>
                                <td><?= e($inv['client_name'] ?? '—') ?></td>
                                <td class="text-end">₹<?= e(number_format((float) $inv['taxable_amount'], 2)) ?></td>
                                <td class="text-end">₹<?= e(number_format((float) $inv['cgst_amount'], 2)) ?></td>
                                <td class="text-end">₹<?= e(number_format((float) $inv['sgst_amount'], 2)) ?></td>
                                <td class="text-end">₹<?= e(number_format((float) $inv['igst_amount'], 2)) ?></td>
                                <td class="text-end">₹<?= e(number_format((float) $inv['total_amount'], 2)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($gstInvoices)): ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 no-print">
                    <div class="text-muted small">Showing <?= e((string) $gstRangeStart) ?>–<?= e((string) $gstRangeEnd) ?> of <?= e((string) count($gstInvoices)) ?> invoices</div>
                    <?= render_pagination($gstPagination['page'], $gstPagination['totalPages']) ?>
                </div>
            <?php else: ?>
                <div class="mb-4"></div>
            <?php endif; ?>

            <!-- Print / PDF view: always the full, unpaginated list -->
            <div class="table-responsive mb-4 gst-invoice-table-print">
                <table class="table table-striped table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>GST No.</th>
                            <th>Date</th>
                            <th>Client</th>
                            <th class="text-end">Taxable</th>
                            <th class="text-end">CGST</th>
                            <th class="text-end">SGST</th>
                            <th class="text-end">IGST</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($gstInvoices)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">No invoices issued in FY <?= e($financialYear) ?>.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($gstInvoices as $inv): ?>
                            <tr>
                                <td><?= e($inv['invoice_number']) ?></td>
                                <td><?= e($inv['client_gstin'] ?? '—') ?></td>
                                <td><?= e(date('d-M-Y', strtotime($inv['invoice_date']))) ?></td>
                                <td><?= e($inv['client_name'] ?? '—') ?></td>
                                <td class="text-end">₹<?= e(number_format((float) $inv['taxable_amount'], 2)) ?></td>
                                <td class="text-end">₹<?= e(number_format((float) $inv['cgst_amount'], 2)) ?></td>
                                <td class="text-end">₹<?= e(number_format((float) $inv['sgst_amount'], 2)) ?></td>
                                <td class="text-end">₹<?= e(number_format((float) $inv['igst_amount'], 2)) ?></td>
                                <td class="text-end">₹<?= e(number_format((float) $inv['total_amount'], 2)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h2 class="h6 mb-2">Expenses by Category</h2>
            <div class="table-responsive">
                <table class="table table-striped table-sm mb-0">
                    <thead><tr><th>Category</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                        <?php if (empty($expenseCategories)): ?>
                            <tr><td colspan="2" class="text-center text-muted py-4">No expenses recorded in FY <?= e($financialYear) ?>.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($expenseCategories as $row): ?>
                            <tr>
                                <td><?= e($row['category']) ?></td>
                                <td class="text-end">₹<?= e(number_format($row['total'], 2)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-semibold">
                            <td>Total Expenses</td>
                            <td class="text-end">₹<?= e(number_format($expenses, 2)) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.gst-invoice-table-print { display: none; }
@media print {
    .no-print, .app-sidebar, .app-navbar, nav, .btn { display: none !important; }
    .app-content { margin: 0 !important; padding: 0 !important; }
    /* Always print the full, unpaginated invoice list — not just the current page */
    .gst-invoice-table-screen { display: none !important; }
    .gst-invoice-table-print { display: block !important; }
}
</style>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('printBtn')?.addEventListener('click', function () { window.print(); });

document.getElementById('gstPerPage')?.addEventListener('change', function () {
    var params = new URLSearchParams(window.location.search);
    params.set('per_page', this.value);
    params.delete('page'); // jump back to page 1 whenever the page size changes
    window.location.search = params.toString();
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
