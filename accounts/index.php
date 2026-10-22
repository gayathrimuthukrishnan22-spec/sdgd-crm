<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('accounts');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify_or_die();
    require_permission('accounts', 'edit');

    $source = $_POST['source'] ?? '';
    $sourceId = (int) ($_POST['source_id'] ?? 0);

    match ($source) {
        'expense' => soft_delete_expense($sourceId),
        'project_income' => soft_delete_project_income($sourceId),
        'invoice_payment' => delete_invoice_payment($sourceId),
        default => null,
    };

    audit_log((int) current_user()['id'], 'accounts', 'delete', "Deleted {$source} ledger entry #{$sourceId}.");
    flash_set('status', 'Entry deleted.');
    redirect('accounts/index.php?start_date=' . urlencode((string) ($_POST['start_date'] ?? '')) . '&end_date=' . urlencode((string) ($_POST['end_date'] ?? '')));
}

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$income = total_income($startDate, $endDate);
$expenses = total_expenses($startDate, $endDate);
$net = $income - $expenses;
$outstanding = total_outstanding_receivables();
$entries = get_ledger_entries($startDate, $endDate);

$pageTitle = 'Accounts';
$activeMenu = 'accounts';
$breadcrumbs = [['label' => 'Accounts', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Accounts</h1>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= e(url('accounts/gst_report.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> GST Report</a>
        <?php if (is_admin_role(current_user())): ?>
            <a href="<?= e(url('accounts/project_payments.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-calendar-check"></i> Monthly Payments</a>
        <?php endif; ?>
        <a href="<?= e(url('accounts/expenses.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-ul"></i> Manage Expenses</a>
        <?php if (is_admin_role(current_user())): ?>
            <a href="<?= e(url('accounts/recurring_expenses.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat"></i> Auto Expenses</a>
        <?php endif; ?>
        <?php if (user_can(current_user(), 'accounts', 'add')): ?>
            <a href="<?= e(url('accounts/expense_form.php')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add Expense</a>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="<?= e(url('accounts/index.php')) ?>" class="row g-2">
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="start_date" class="form-control form-control-sm" value="<?= e($startDate) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="end_date" class="form-control form-control-sm" value="<?= e($endDate) ?>">
            </div>
            <div class="col-12 col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                <a href="<?= e(url('accounts/index.php')) ?>" class="btn btn-outline-secondary btn-sm">This Month</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-arrow-down-circle"></i></div>
                <div><div class="text-muted small">Income</div><div class="fs-5 fw-semibold">₹<?= e(number_format($income, 2)) ?></div></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-arrow-up-circle"></i></div>
                <div><div class="text-muted small">Expenses</div><div class="fs-5 fw-semibold">₹<?= e(number_format($expenses, 2)) ?></div></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-wallet2"></i></div>
                <div><div class="text-muted small">Net (Period)</div><div class="fs-5 fw-semibold <?= $net < 0 ? 'text-danger' : '' ?>">₹<?= e(number_format($net, 2)) ?></div></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-hourglass-split"></i></div>
                <div><div class="text-muted small">Outstanding Receivables</div><div class="fs-5 fw-semibold">₹<?= e(number_format($outstanding, 2)) ?></div></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Ledger</h2></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Bill</th><th class="text-end">Amount</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No transactions in this period.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($entries as $entry): ?>
                        <?php
                            $amt = (float) $entry['amount'];
                            $isExpense = $entry['type'] === 'expense';
                            $isCorrection = $entry['type'] === 'correction';
                            $badgeClass = $isExpense ? 'text-bg-danger' : ($isCorrection ? 'text-bg-secondary' : 'text-bg-success');
                            $badgeLabel = $isExpense ? 'Expense' : ($isCorrection ? 'Correction' : 'Income');
                            $amountClass = $isExpense || $amt < 0 ? 'text-danger' : 'text-success';
                            $sign = $amt < 0 ? '−' : '+';
                        ?>
                        <tr>
                            <td><?= e($entry['date']) ?></td>
                            <td><span class="badge <?= e($badgeClass) ?>"><?= e($badgeLabel) ?></span></td>
                            <td><?= e($entry['description']) ?></td>
                            <td>
                                <?php if (!empty($entry['invoice_id'])): ?>
                                    <a href="<?= e(url('billing/view.php?id=' . $entry['invoice_id'])) ?>"><?= e($entry['invoice_number']) ?></a>
                                <?php elseif (!empty($entry['receipt_number'])): ?>
                                    <?= e($entry['receipt_number']) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="text-end <?= e($amountClass) ?>">
                                <?= e($sign) ?>₹<?= e(number_format(abs($amt), 2)) ?>
                            </td>
                            <td class="text-end">
                                <?php if (user_can(current_user(), 'accounts', 'edit')): ?>
                                    <form method="POST" action="<?= e(url('accounts/index.php')) ?>" class="js-confirm-delete d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="source" value="<?= e($entry['source']) ?>">
                                        <input type="hidden" name="source_id" value="<?= e((string) $entry['source_id']) ?>">
                                        <input type="hidden" name="start_date" value="<?= e($startDate) ?>">
                                        <input type="hidden" name="end_date" value="<?= e($endDate) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
