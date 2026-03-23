<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/database.php';
require_once '../helpers/DateHelper.php';
require_once '../models/Leave.php';
require_once '../helpers/Flash.php';

if ($_SESSION['role'] !== 'admin') {
    die("Access denied");
}

$db = (new Database())->connect();
$leaveModel = new Leave($db);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle manual single-employee accrual
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_accrual'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF validation failed.");
    }

    $employee_id = intval($_POST['employee_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $month = trim($_POST['month'] ?? date('Y-m'));

    if ($employee_id <= 0) {
        flash_redirect('manage_accruals.php', 'error', 'Please select an employee');
    }

    if ($amount <= 0) {
        flash_redirect('manage_accruals.php', 'error', 'Accrual amount must be greater than zero');
    }

    $ok = $leaveModel->accrueSingleEmployee(
        $employee_id,
        $amount,
        $month,
        date('Y-m-d'),
        'Manual accrual recorded'
    );

    if ($ok) {
        flash_redirect('manage_accruals.php', 'success', 'Manual accrual recorded successfully');
    } else {
        flash_redirect('manage_accruals.php', 'error', 'Failed to record manual accrual');
    }
}

// Handle bulk accrual for all employees
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_bulk_accrual'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF validation failed.");
    }

    $amount = floatval($_POST['bulk_amount'] ?? 0);
    $month = trim($_POST['bulk_month'] ?? date('Y-m'));

    if ($amount <= 0) {
        flash_redirect('manage_accruals.php', 'error', 'Bulk accrual amount must be greater than zero');
    }

    $result = $leaveModel->accrueAllEmployees(
        $amount,
        $month,
        date('Y-m-d'),
        'Bulk accrual recorded'
    );

    if (!empty($result['success'])) {
        $count = intval($result['count'] ?? 0);
        flash_redirect('manage_accruals.php', 'success', "Bulk accrual completed for {$count} employee(s).");
    } else {
        flash_redirect('manage_accruals.php', 'error', $result['message'] ?? 'Failed to perform bulk accrual.');
    }
}

// Get employees for dropdown
$employees = $db->query("
    SELECT id, first_name, last_name, annual_balance, sick_balance
    FROM employees
    ORDER BY first_name, last_name
")->fetchAll(PDO::FETCH_ASSOC);

// Get accrual history
$accruals = [];
try {
    $accruals = $db->query("
        SELECT 
            a.id,
            a.employee_id,
            a.amount,
            a.date_accrued AS created_at,
            a.month_reference,
            e.first_name,
            e.last_name
        FROM accrual_history a
        JOIN employees e ON a.employee_id = e.id
        ORDER BY a.date_accrued DESC, a.id DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {
    try {
        $accruals = $db->query("
            SELECT 
                a.id,
                a.employee_id,
                a.amount,
                a.created_at,
                NULL AS month_reference,
                e.first_name,
                e.last_name
            FROM accruals a
            JOIN employees e ON a.employee_id = e.id
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ex2) {
        $accruals = [];
    }
}

$totalEmployees = (int)$db->query("SELECT COUNT(*) FROM employees")->fetchColumn();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Accruals</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="app-main">
    <h2>Manage Accruals</h2>

    <div class="ui-card" style="margin-bottom:20px;">
        <h3>Bulk Accrual for All Employees</h3>
        <p style="font-size:13px;opacity:0.9;margin-bottom:16px;">
            This will add the selected accrual amount to both <strong>Vacational</strong> and <strong>Sick</strong> balances
            for <strong>all employees</strong>. This can still be used even if it is not yet the end of the month.
            <br><br>
            <strong>Note:</strong> Force Leave is not affected here.
        </p>

        <div class="form-centered">
            <form method="POST" id="bulkAccrualForm">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="record_bulk_accrual" value="1">

                <label>Employees Affected</label>
                <input type="text" value="<?= $totalEmployees; ?> employee(s)" readonly>

                <label>Amount to Add (days)</label>
                <input type="number" step="0.001" name="bulk_amount" id="bulk_amount" value="1.250" required>

                <label>For Month</label>
                <input type="month" name="bulk_month" id="bulk_month" value="<?= date('Y-m'); ?>" required>

                <button type="submit">Add Accrual to All Employees</button>
            </form>
        </div>
    </div>

    <div class="card-container" style="display:flex;gap:16px;flex-wrap:wrap;justify-content:center;">
        <div class="ui-card" style="flex:1;min-width:300px;max-width:500px;">
            <h3>Record Manual Accrual</h3>
            <p style="font-size:13px;opacity:0.9;">Use this to record manual accruals for past periods or special cases.</p>

            <div class="form-centered">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="record_accrual" value="1">

                    <label>Employee</label>
                    <select name="employee_id" required>
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($employees as $e): ?>
                            <option value="<?= $e['id']; ?>">
                                <?= htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?>
                                (Vac: <?= number_format((float)$e['annual_balance'], 3); ?> | Sick: <?= number_format((float)$e['sick_balance'], 3); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label>Amount (days)</label>
                    <input type="number" step="0.001" name="amount" value="1.250" required>

                    <label>For Month</label>
                    <input type="month" name="month" value="<?= date('Y-m'); ?>" required>

                    <button type="submit">Record Accrual</button>
                </form>
            </div>
        </div>

        <div class="ui-card" style="flex:1;min-width:300px;max-width:700px;">
            <h3>Accrual History (Last 50)</h3>
            <table style="font-size:13px;">
                <tr>
                    <th>Employee</th>
                    <th>Amount</th>
                    <th>Month Ref</th>
                    <th>Date</th>
                </tr>
                <?php foreach ($accruals as $a): ?>
                <tr>
                    <td><?= htmlspecialchars($a['first_name'] . ' ' . $a['last_name']); ?></td>
                    <td><?= number_format((float)$a['amount'], 3); ?> days</td>
                    <td><?= htmlspecialchars(!empty($a['month_reference']) ? app_format_month_ref($a['month_reference']) : '—'); ?></td>
                    <td><?= !empty($a['created_at']) ? htmlspecialchars(app_format_date($a['created_at'])) : ''; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>

<script>
document.getElementById('bulkAccrualForm').addEventListener('submit', function(e) {
    var amount = document.getElementById('bulk_amount').value || '1.250';
    var month = document.getElementById('bulk_month').value || '';

    var step1 = confirm(
        'Are you sure you want to add ' + amount + ' day(s) to BOTH Vacational and Sick balances of ALL employees?'
    );
    if (!step1) {
        e.preventDefault();
        return;
    }

    var step2 = confirm(
        'This will affect all employees and write accrual history logs for month ' + month + '. Continue?'
    );
    if (!step2) {
        e.preventDefault();
        return;
    }

    var step3 = confirm(
        'Final confirmation: this can be done even if it is NOT yet the end of the month. Force Leave will NOT be changed. Do you want to proceed?'
    );
    if (!step3) {
        e.preventDefault();
    }
});
</script>

</body>
</html>
