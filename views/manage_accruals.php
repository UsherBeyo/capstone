<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/database.php';
require_once '../models/Leave.php';

if ($_SESSION['role'] !== 'admin') {
    die("Access denied");
}

$db = (new Database())->connect();
$leaveModel = new Leave($db);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle manual accrual recording
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_accrual'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF validation failed.");
    }

    $employee_id = intval($_POST['employee_id']);
    $amount = floatval($_POST['amount']);
    $month = $_POST['month'];
    $today = date('Y-m-d H:i:s');

    // update balances (annual and sick) first
    $updateStmt = $db->prepare("UPDATE employees SET annual_balance = annual_balance + ?, sick_balance = sick_balance + ? WHERE id = ?");
    $updateStmt->execute([$amount, $amount, $employee_id]);

    // record history
    $histStmt = $db->prepare("INSERT INTO accrual_history (employee_id, amount, date_accrued, month_reference) VALUES (?, ?, ?, ?)");
    if ($histStmt->execute([$employee_id, $amount, $today, $month])) {
        // Get old/new balance for logging
        $balStmt = $db->prepare("SELECT annual_balance FROM employees WHERE id = ?");
        $balStmt->execute([$employee_id]);
        $newBal = floatval($balStmt->fetchColumn());
        $oldBal = $newBal - $amount;

        // Log to budget history for both types
        $leaveModel->logBudgetChange($employee_id, 'Annual', $oldBal, $newBal, 'accrual', null, 'Manual accrual recorded for ' . $month);
        $leaveModel->logBudgetChange($employee_id, 'Sick', $oldBal, $newBal, 'accrual', null, 'Manual accrual recorded for ' . $month);

        header("Location: manage_accruals.php?success=1");
        exit();
    }
}

// Get employees for dropdown (only from employees table)
$employees = $db->query("SELECT id, first_name, last_name, annual_balance FROM employees ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

// Get accruals history (with fallback to old table for backwards compatibility)
$accruals = [];
try {
    $accruals = $db->query("SELECT a.id, a.employee_id, a.amount, a.date_accrued AS created_at, e.first_name, e.last_name FROM accrual_history a JOIN employees e ON a.employee_id = e.id ORDER BY a.date_accrued DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {
    // try old table if present
    try {
        $accruals = $db->query("SELECT a.id, a.employee_id, a.amount, a.created_at, e.first_name, e.last_name FROM accruals a JOIN employees e ON a.employee_id = e.id ORDER BY a.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ex2) {
        // nothing available
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Accruals</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="content">
    <h2>Manage Leave Accruals</h2>

    <?php if(isset($_GET['success'])): ?>
        <div class="card" style="background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:12px;margin-bottom:16px;">
            Accrual recorded successfully!
        </div>
    <?php endif; ?>

    <div class="card">
        <h3>Record Manual Accrual</h3>
        <p style="font-size:13px;opacity:0.9;">Use this to record manual accruals for past periods or special cases.  (amount will be added to both annual and sick balances)</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="record_accrual" value="1">

            <label>Employee</label>
            <select name="employee_id" required>
                <option value="">-- Select Employee --</option>
                <?php foreach ($employees as $e): ?>
                    <option value="<?= $e['id']; ?>"><?= htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?> (Current: <?= $e['annual_balance']; ?> days)</option>
                <?php endforeach; ?>
            </select>

            <label>Amount (days)</label>
            <input type="number" step="0.25" name="amount" value="1.25" required>

            <label>For Month</label>
            <input type="month" name="month" value="<?= date('Y-m'); ?>" required>

            <button type="submit">Record Accrual</button>
        </form>
    </div>

    <div class="card" style="margin-top:20px;">
        <h3>Accrual History (Last 50)</h3>
        <table style="font-size:13px;">
            <tr><th>Employee</th><th>Amount</th><th>Date</th></tr>
            <?php foreach ($accruals as $a): ?>
            <tr>
                <td><?= htmlspecialchars($a['first_name'] . ' ' . $a['last_name']); ?></td>
                <td><?= $a['amount']; ?> days</td>
                <td><?= date('M d, Y', strtotime($a['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

</div>

</body>
</html>
