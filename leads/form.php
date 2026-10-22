<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
// permission check happens below once we know if this is add or edit

$leadId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$lead = $leadId !== null ? find_lead($leadId) : null;

if ($leadId !== null && $lead === false) {
    flash_set('error', 'Lead not found.');
    redirect('leads/index.php');
}

require_permission('leads', $leadId !== null ? 'edit' : 'add');

$isEdit = $lead !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $data = [
        'client_name' => sanitize_string($_POST['client_name'] ?? ''),
        'company' => sanitize_string($_POST['company'] ?? '') ?: null,
        'mobile' => sanitize_string($_POST['mobile'] ?? ''),
        'whatsapp' => sanitize_string($_POST['whatsapp'] ?? '') ?: null,
        'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
        'address' => sanitize_string($_POST['address'] ?? '') ?: null,
        'gst_number' => strtoupper(sanitize_string($_POST['gst_number'] ?? '')) ?: null,
        'pan_number' => strtoupper(sanitize_string($_POST['pan_number'] ?? '')) ?: null,
        'budget' => ($_POST['budget'] ?? '') !== '' ? (float) $_POST['budget'] : null,
        'project_type' => sanitize_string($_POST['project_type'] ?? '') ?: null,
        'priority' => $_POST['priority'] ?? 'medium',
        'source' => $_POST['source'] ?? 'other',
        'status' => $_POST['status'] ?? 'new',
    ];

    $error = validate_required($data['client_name'], 'Client name')
        ?? validate_required($data['mobile'], 'Mobile number');

    if ($error === null && $data['email'] !== null && $data['email'] !== '') {
        $error = validate_email($data['email']);
    } elseif ($data['email'] === '') {
        $data['email'] = null;
    }

    if ($error === null && !in_array($data['priority'], ['low', 'medium', 'high'], true)) {
        $error = 'Invalid priority.';
    }
    if ($error === null && !in_array($data['status'], ['new','contacted','qualified','proposal','negotiation','won','lost','missed','cancelled'], true)) {
        $error = 'Invalid status.';
    }

    if ($error !== null) {
        flash_set('error', $error);
        redirect('leads/form.php' . ($isEdit ? '?id=' . $leadId : ''));
    }

    $currentUserId = (int) current_user()['id'];

    if ($isEdit) {
        update_lead($leadId, $data);
        log_lead_activity($leadId, $currentUserId, 'note', 'Lead details updated.');
        flash_set('status', 'Lead updated successfully.');
        redirect('leads/view.php?id=' . $leadId);
    }

    $newId = create_lead($data, $currentUserId);
    log_lead_activity($newId, $currentUserId, 'note', 'Lead created.');
    flash_set('status', 'Lead created successfully.');
    redirect('leads/view.php?id=' . $newId);
}

$pageTitle = $isEdit ? 'Edit Lead' : 'Add Lead';
$activeMenu = 'leads';
$breadcrumbs = [
    ['label' => 'Leads', 'url' => url('leads/index.php')],
    ['label' => $isEdit ? 'Edit' : 'Add', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="card">
    <div class="card-header bg-white"><h2 class="h6 mb-0"><?= e($pageTitle) ?></h2></div>
    <div class="card-body">
        <form method="POST" action="<?= e(url('leads/form.php' . ($isEdit ? '?id=' . $leadId : ''))) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label">Client Name *</label>
                    <input type="text" name="client_name" class="form-control" required
                           value="<?= e($lead['client_name'] ?? '') ?>">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Company</label>
                    <input type="text" name="company" class="form-control" value="<?= e($lead['company'] ?? '') ?>">
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">Mobile *</label>
                    <input type="tel" name="mobile" class="form-control" required value="<?= e($lead['mobile'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">WhatsApp</label>
                    <input type="tel" name="whatsapp" class="form-control" value="<?= e($lead['whatsapp'] ?? '') ?>">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= e($lead['email'] ?? '') ?>">
                </div>

                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2"><?= e($lead['address'] ?? '') ?></textarea>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">GST Number</label>
                    <input type="text" name="gst_number" class="form-control text-uppercase" value="<?= e($lead['gst_number'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">PAN Number</label>
                    <input type="text" name="pan_number" class="form-control text-uppercase" value="<?= e($lead['pan_number'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Budget (₹)</label>
                    <input type="number" step="0.01" name="budget" class="form-control" value="<?= e((string) ($lead['budget'] ?? '')) ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Project Type</label>
                    <input type="text" name="project_type" class="form-control" value="<?= e($lead['project_type'] ?? '') ?>">
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">Priority</label>
                    <select name="priority" class="form-select">
                        <?php foreach (['low','medium','high'] as $p): ?>
                            <option value="<?= e($p) ?>" <?= ($lead['priority'] ?? 'medium') === $p ? 'selected' : '' ?>><?= e(ucfirst($p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Source</label>
                    <select name="source" class="form-select">
                        <?php foreach (['website','referral','social_media','cold_call','walk_in','advertisement','other'] as $s): ?>
                            <option value="<?= e($s) ?>" <?= ($lead['source'] ?? 'other') === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" id="leadStatus" class="form-select" data-original-status="<?= e($lead['status'] ?? 'new') ?>">
                        <?php foreach (['new','contacted','qualified','proposal','negotiation','won','lost','missed','cancelled'] as $s): ?>
                            <option value="<?= e($s) ?>" <?= ($lead['status'] ?? 'new') === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Lead' ?></button>
                <a href="<?= e(url('leads/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
// Marking a lead Won auto-creates a Client record from its details (see
// convert_won_lead_to_client() in includes/leads.php) — confirm before
// that fires, since it's a one-way trigger the first time status flips
// to Won.
(function () {
    var form = document.querySelector('form[action*="leads/form.php"]');
    var statusSelect = document.getElementById('leadStatus');
    if (!form || !statusSelect) {
        return;
    }
    form.addEventListener('submit', function (e) {
        var originalStatus = statusSelect.getAttribute('data-original-status');
        if (statusSelect.value === 'won' && originalStatus !== 'won') {
            var ok = confirm('Mark this lead as Won? This will automatically create a Client record from its details.');
            if (!ok) {
                e.preventDefault();
            }
        }
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
