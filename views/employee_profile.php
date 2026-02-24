<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/database.php';

$db = (new Database())->connect();

$id = isset($_GET['id']) ? intval($_GET['id']) : ($_SESSION['emp_id'] ?? 0);
if (!$id) { die("Employee not specified"); }

$stmt = $db->prepare("SELECT e.*, u.email FROM employees e JOIN users u ON e.user_id = u.id WHERE e.id = ?");
$stmt->execute([$id]);
$e = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$e) { die("Employee not found"); }

// permission: admin/hr/manager or the employee themselves
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin','manager','hr']) && ($_SESSION['emp_id'] ?? 0) != $id) {
    die("Access denied");
}

// export leave history CSV
if (isset($_GET['export']) && ($_SESSION['role'] === 'admin' || $_SESSION['role']==='hr')) {
    $stmt = $db->prepare("SELECT leave_type, start_date, end_date, total_days, status, created_at as 'submitted_date', reason, snapshot_annual_balance, snapshot_sick_balance, snapshot_force_balance FROM leave_requests WHERE employee_id = ? ORDER BY start_date");
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // output as simple Excel (HTML) so clients can adjust column widths
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="leave_history_'.$id.'.xls"');
    echo "<table border=1>\n";
    // header row with some width hints
    echo "<tr>";
    $headers = $rows[0] ? array_keys($rows[0]) : ['leave_type','start_date','end_date','total_days','status','submitted_date','reason','snapshot_annual_balance','snapshot_sick_balance','snapshot_force_balance'];
    foreach($headers as $h) {
        echo "<th style='min-width:120px;'>".htmlspecialchars($h)."</th>";
    }
    echo "</tr>\n";
    foreach($rows as $r) {
        echo "<tr>";
        foreach($r as $key => $cell) {
            if ($key === 'total_days') {
                $cell = intval($cell);
            }
            echo "<td>".htmlspecialchars($cell)."</td>";
        }
        echo "</tr>\n";
    }
    echo "</table>";
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// fetch history for display
$stmt = $db->prepare("SELECT * FROM leave_requests WHERE employee_id = ? ORDER BY start_date DESC");
$stmt->execute([$id]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// fetch budget history
$budgetHistory = [];
$stmtBudget = $db->prepare("SELECT * FROM budget_history WHERE employee_id = ? ORDER BY created_at DESC LIMIT 30");
$stmtBudget->execute([$id]);
$budgetHistory = $stmtBudget->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Employee Profile</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        .profile-header { display:flex; gap:20px; align-items:center; }
        .profile-pic { width:96px; height:96px; border-radius:50%; object-fit:cover; }
        .small-form input, .small-form select { width: 100%; padding:8px; margin-bottom:8px; border-radius:6px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<div class="content">
    <div class="card">
        <div class="profile-header">
            <div>
                <?php if(!empty($e['profile_pic'])): ?>
                    <img src="<?= htmlspecialchars($e['profile_pic']); ?>" class="profile-pic">
                <?php else: ?>
                    <div style="width:96px;height:96px;border-radius:50%;background:rgba(255,255,255,0.06);display:flex;align-items:center;justify-content:center;">👤</div>
                <?php endif; ?>
            </div>
            <div>
                <h2><?= htmlspecialchars($e['first_name'].' '.$e['last_name']); ?></h2>
                <p><?= htmlspecialchars($e['email']); ?></p>
                <p>Department: <?= htmlspecialchars($e['department']); ?></p>
                <p>Annual: <?= $e['annual_balance'] ?? 0; ?> days — Sick: <?= $e['sick_balance'] ?? 0; ?> — Force: <?= $e['force_balance'] ?? 0; ?></p>
                <p>
                    <?php if(($_SESSION['emp_id'] ?? 0) == $id || in_array($_SESSION['role'], ['admin','hr','manager'])): ?>
                        <a href="edit_employee.php?id=<?= $e['id']; ?>">Edit profile</a>
                    <?php endif; ?>
                    <?php if(in_array($_SESSION['role'], ['admin','hr'])): ?>
                        &nbsp;| <a href="employee_profile.php?id=<?= $e['id']; ?>&export=1">Export history</a>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <?php if(in_array($_SESSION['role'], ['admin','hr'])): ?>
    <div class="card" style="margin-top:16px;">
        <h3>Admin actions</h3>
        <div style="display:flex;gap:16px;">
            <div style="flex:1;">
                <form method="POST" action="../controllers/AdminController.php" class="small-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="update_employee" value="1">
                    <input type="hidden" name="employee_id" value="<?= $e['id']; ?>">
                    <label>Annual Balance</label>
                    <input type="number" step="0.01" name="annual_balance" value="<?= $e['annual_balance'] ?? 0; ?>">
                    <label>Sick Balance</label>
                    <input type="number" step="0.01" name="sick_balance" value="<?= $e['sick_balance'] ?? 0; ?>">
                    <label>Force Balance</label>
                    <input type="number" name="force_balance" value="<?= $e['force_balance'] ?? 0; ?>">
                    <div style="text-align:right;">
                        <button type="submit">Update balances</button>
                    </div>
                </form>
            </div>
            <div style="flex:1;">
                <form method="POST" action="../controllers/AdminController.php" class="small-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="add_history" value="1">
                    <input type="hidden" name="employee_id" value="<?= $e['id']; ?>">
                    <label>Leave Type</label>
                    <select name="leave_type">
                        <option>Annual</option>
                        <option>Sick</option>
                        <option>Force</option>
                    </select>
                    <label>Start Date</label>
                    <input type="date" name="start_date" required>
                    <label>End Date</label>
                    <input type="date" name="end_date" required>
                    <label>Total Days</label>
                    <input type="number" step="0.01" name="total_days" required>
                    <label>Comments</label>
                    <input type="text" name="reason">
                    <hr>
                    <p style="font-size:12px;opacity:0.8;">(optional) supply the leave balances that were available at the time of this historical entry.</p>
                    <label>Annual balance at time</label>
                    <input type="number" step="0.01" name="snapshot_annual_balance" value="">
                    <label>Sick balance at time</label>
                    <input type="number" step="0.01" name="snapshot_sick_balance" value="">
                    <label>Force balance at time</label>
                    <input type="number" name="snapshot_force_balance" value="">
                    <div style="text-align:right;">
                        <button type="submit">Add history entry</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card" style="margin-top:16px;">
        <h3>Leave History</h3>
        <table style="font-size:12px;">
            <tr><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Submitted</th><th>Annual Bal</th><th>Sick Bal</th><th>Force Bal</th><th>Comments</th></tr>
            <?php foreach($history as $h): ?>
            <tr>
                <td><?= htmlspecialchars($h['leave_type'] ?? ''); ?></td>
                <td><?= htmlspecialchars(($h['start_date'] ?? '').' to '.($h['end_date'] ?? '')); ?></td>
                <td><?= isset($h['total_days']) ? intval($h['total_days']) : ''; ?></td>
                <td><?= htmlspecialchars($h['status'] ?? ''); ?></td>
                <td><?= !empty($h['created_at']) ? date('M d, Y', strtotime($h['created_at'])) : ''; ?></td>
                <td><?= $h['snapshot_annual_balance'] ?? '—'; ?></td>
                <td><?= $h['snapshot_sick_balance'] ?? '—'; ?></td>
                <td><?= $h['snapshot_force_balance'] ?? '—'; ?></td>
                <td><?= htmlspecialchars($h['manager_comments'] ?? $h['reason'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="card" style="margin-top:16px;">
        <h3>Budget History</h3>
        <?php if(empty($budgetHistory)): ?>
            <p>No budget change history available.</p>
        <?php else: ?>
        <table style="font-size:13px;">
            <tr><th>Leave Type</th><th>Action</th><th>Old Balance</th><th>New Balance</th><th>Date</th><th>Notes</th></tr>
            <?php foreach($budgetHistory as $bh): ?>
            <tr>
                <td><?= htmlspecialchars($bh['leave_type'] ?? ''); ?></td>
                <td><?= htmlspecialchars($bh['action'] ?? ''); ?></td>
                <td><?= $bh['old_balance'] ?? ''; ?></td>
                <td><?= $bh['new_balance'] ?? ''; ?></td>
                <td><?= !empty($bh['created_at']) ? date('M d, Y H:i', strtotime($bh['created_at'])) : ''; ?></td>
                <td><?= htmlspecialchars($bh['notes'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
