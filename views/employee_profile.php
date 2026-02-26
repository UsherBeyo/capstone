<?php
if (session_status() === PHP_SESSION_NONE) {
    if (session_status() === PHP_SESSION_NONE) session_start();
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

// export leave card - merged leave history & budget history with accurate balances
if (isset($_GET['export']) && $_GET['export'] === 'leave_card' && (
        $_SESSION['role'] === 'admin' ||
        $_SESSION['role'] === 'hr' ||
        ($_SESSION['emp_id'] ?? 0) == $id
    )) {
    $empId = $id;
    $rows = [];

    // gather leave requests; use budget_history to determine balances after deduction
    $leaveStmt = $db->prepare(
        "SELECT lr.created_at, COALESCE(lt.name, lr.leave_type) AS leave_type, lr.status, lr.total_days
         FROM leave_requests lr
         LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
         WHERE lr.employee_id = ?
         ORDER BY lr.created_at ASC"
    );
    $leaveStmt->execute([$empId]);
    foreach ($leaveStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $vacDed = 0;
        $sickDed = 0;
        if (strtolower($r['leave_type']) !== 'sick' && $r['status'] === 'approved') {
            $vacDed = floatval($r['total_days']);
        } elseif (strtolower($r['leave_type']) === 'sick' && $r['status'] === 'approved') {
            $sickDed = floatval($r['total_days']);
        }
        // lookup balance after this leave in budget_history
        $vacBal = '';
        $sickBal = '';
        if ($vacDed || !$vacDed) {
            $balStmt = $db->prepare("SELECT new_balance FROM budget_history WHERE employee_id=? AND leave_type IN ('Annual','Vacational','Vacation') AND created_at >= ? ORDER BY created_at ASC LIMIT 1");
            $balStmt->execute([$empId, $r['created_at']]);
            $vacBal = floatval($balStmt->fetchColumn() ?: 0);
        }
        if ($sickDed || !$sickDed) {
            $balStmt2 = $db->prepare("SELECT new_balance FROM budget_history WHERE employee_id=? AND leave_type='Sick' AND created_at >= ? ORDER BY created_at ASC LIMIT 1");
            $balStmt2->execute([$empId, $r['created_at']]);
            $sickBal = floatval($balStmt2->fetchColumn() ?: 0);
        }
        $rows[] = [
            'date' => substr($r['created_at'], 0, 10),
            'particulars' => $r['leave_type'] . ' Leave',
            'vac_earned' => 0,
            'vac_deducted' => $vacDed,
            'vac_balance' => $vacBal,
            'sick_earned' => 0,
            'sick_deducted' => $sickDed,
            'sick_balance' => $sickBal,
            'status' => ucfirst($r['status'])
        ];
    }

    // gather budget history entries
    $budgetStmt = $db->prepare(
        "SELECT created_at, leave_type, action, old_balance, new_balance
         FROM budget_history
         WHERE employee_id = ?
         ORDER BY created_at ASC"
    );
    $budgetStmt->execute([$empId]);
    foreach ($budgetStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $vacDed = 0;
        $sickDed = 0;
        $vacEarn = 0;
        $sickEarn = 0;

        $part = ucfirst($r['action']) . ' ' . $r['leave_type'];
        if (in_array(strtolower($r['leave_type']), ['annual','vacational','vacation'])) {
            $vacEarn = max(0, floatval($r['new_balance']) - floatval($r['old_balance']));
            $vacDed  = max(0, floatval($r['old_balance']) - floatval($r['new_balance']));
            $vacBal  = floatval($r['new_balance']);
            $sickBal = '';
        } elseif (strtolower($r['leave_type']) === 'sick') {
            $sickEarn = max(0, floatval($r['new_balance']) - floatval($r['old_balance']));
            $sickDed  = max(0, floatval($r['old_balance']) - floatval($r['new_balance']));
            $sickBal  = floatval($r['new_balance']);
            $vacBal   = '';
        } else {
            // other types, just show balances generically
            $vacBal = '';
            $sickBal = '';
        }

        $rows[] = [
            'date' => substr($r['created_at'], 0, 10),
            'particulars' => $part,
            'vac_earned' => $vacEarn,
            'vac_deducted' => $vacDed,
            'vac_balance' => isset($vacBal) ? $vacBal : '',
            'sick_earned' => $sickEarn,
            'sick_deducted' => $sickDed,
            'sick_balance' => isset($sickBal) ? $sickBal : '',
            'status' => ucfirst($r['action'])
        ];
    }

    // sort everything by date
    usort($rows, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });

    // output as Excel (HTML)
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="leave_card_'.$id.'_'.date('Y-m-d').'.xls"');
    echo "<table border=1>\n";
    echo "<tr><td colspan='9' style='font-weight:bold;background-color:#d3d3d3;'><strong>Employee Information</strong></td></tr>\n";
    echo "<tr><td><strong>Employee ID</strong></td><td>".htmlspecialchars($e['id'])."</td><td><strong>Name</strong></td><td>".htmlspecialchars($e['first_name'].' '.$e['last_name'])."</td><td><strong>Position</strong></td><td>".htmlspecialchars($e['position'] ?? '')."</td><td><strong>Department</strong></td><td>".htmlspecialchars($e['department'])."</td></tr>\n";
    echo "<tr><td><strong>Status</strong></td><td>".htmlspecialchars($e['status'] ?? '')."</td><td><strong>Civil Status</strong></td><td>".htmlspecialchars($e['civil_status'] ?? '')."</td><td><strong>Entrance to Duty</strong></td><td>".htmlspecialchars($e['entrance_to_duty'] ?? '')."</td><td><strong>Unit</strong></td><td>".htmlspecialchars($e['unit'] ?? '')."</td></tr>\n";
    echo "<tr><td colspan='9'>&nbsp;</td></tr>\n";
    echo "<tr><td colspan='9' style='font-weight:bold;background-color:#d3d3d3;'><strong>LEAVE CARD TRANSACTIONS</strong></td></tr>\n";
    echo "<tr style='background-color:#e0e0e0;'>";
    echo "<th>Date</th><th>Particulars</th><th>Vac Earned</th><th>Vac Deducted</th><th>Vac Balance</th><th>Sick Earned</th><th>Sick Deducted</th><th>Sick Balance</th><th>Status</th>";
    echo "</tr>\n";
    foreach ($rows as $row) {
        echo "<tr>";
        echo "<td>".htmlspecialchars($row['date'])."</td>";
        echo "<td>".htmlspecialchars($row['particulars'])."</td>";
        echo "<td>".($row['vac_earned'] != 0 ? number_format($row['vac_earned'],3) : '')."</td>";
        echo "<td>".($row['vac_deducted'] != 0 ? number_format($row['vac_deducted'],3) : '')."</td>";
        echo "<td>".(!
            isset($row['vac_balance']) || $row['vac_balance'] === '' ? '' : number_format($row['vac_balance'],3))."</td>";
        echo "<td>".($row['sick_earned'] != 0 ? number_format($row['sick_earned'],3) : '')."</td>";
        echo "<td>".($row['sick_deducted'] != 0 ? number_format($row['sick_deducted'],3) : '')."</td>";
        echo "<td>".(!
            isset($row['sick_balance']) || $row['sick_balance'] === '' ? '' : number_format($row['sick_balance'],3))."</td>";
        echo "<td>".htmlspecialchars($row['status'])."</td>";
        echo "</tr>\n";
    }
    echo "</table>";
    exit();
}

// export leave history CSV
if (isset($_GET['export']) && ($_SESSION['role'] === 'admin' || $_SESSION['role']==='hr' || ($_SESSION['emp_id'] ?? 0) == $id)) {
    $stmt = $db->prepare("SELECT COALESCE(lt.name, lr.leave_type) AS leave_type_name, lr.start_date, lr.end_date, lr.total_days, lr.status, lr.created_at as 'submitted_date', lr.reason, lr.snapshot_annual_balance, lr.snapshot_sick_balance, lr.snapshot_force_balance FROM leave_requests lr LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id WHERE lr.employee_id = ? ORDER BY lr.start_date");
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // output as simple Excel (HTML) so clients can adjust column widths
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="leave_history_'.$id.'.xls"');
    echo "<table border=1>\n";
    // Add employee information header
    echo "<tr><td colspan='10' style='font-weight:bold;background-color:#e0e0e0;'><strong>Employee Information</strong></td></tr>\n";
    echo "<tr><td><strong>Employee ID</strong></td><td>".htmlspecialchars($e['id'])."</td><td><strong>Name</strong></td><td>".htmlspecialchars($e['first_name'].' '.$e['last_name'])."</td><td><strong>Email</strong></td><td>".htmlspecialchars($e['email'])."</td><td><strong>Department</strong></td><td>".htmlspecialchars($e['department'])."</td></tr>\n";
    echo "<tr><td><strong>Position</strong></td><td>".htmlspecialchars($e['position'] ?? '')."</td><td><strong>Status</strong></td><td>".htmlspecialchars($e['status'] ?? '')."</td><td><strong>Civil Status</strong></td><td>".htmlspecialchars($e['civil_status'] ?? '')."</td><td><strong>Entrance</strong></td><td>".htmlspecialchars($e['entrance_to_duty'] ?? '')."</td></tr>\n";
    echo "<tr><td colspan='10'>&nbsp;</td></tr>\n";
    echo "<tr><td colspan='10' style='font-weight:bold;background-color:#e0e0e0;'><strong>Leave History</strong></td></tr>\n";
    // header row with some width hints
    echo "<tr>";
    $headers = $rows[0] ? array_keys($rows[0]) : ['leave_type_name','start_date','end_date','total_days','status','submitted_date','reason','snapshot_annual_balance','snapshot_sick_balance','snapshot_force_balance'];
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
$stmt = $db->prepare("SELECT lr.*, COALESCE(lt.name, lr.leave_type) AS leave_type_name FROM leave_requests lr LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id WHERE lr.employee_id = ? ORDER BY lr.start_date DESC");
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
<div class="content" style="padding-top:80px;">
    <div class="card">
        <div class="profile-header">
            <div>
                <?php if(!empty($e['profile_pic'])): ?>
                    <img src="<?= htmlspecialchars($e['profile_pic']); ?>" class="profile-pic" style="cursor:pointer;" onclick="openImageModal('<?= htmlspecialchars($e['profile_pic']); ?>', '<?= htmlspecialchars($e['first_name'].' '.$e['last_name']); ?>')">
                <?php else: ?>
                    <div style="width:96px;height:96px;border-radius:50%;background:rgba(255,255,255,0.06);display:flex;align-items:center;justify-content:center;">👤</div>
                <?php endif; ?>
            </div>
            <div>
                <h2><?= htmlspecialchars(trim(($e['first_name'].' '.$e['last_name']) ?: $e['name'])); ?></h2>
                <p><?= htmlspecialchars($e['email']); ?></p>
                <p>Department: <?= htmlspecialchars($e['department']); ?></p>
                <p>Position: <?= htmlspecialchars($e['position'] ?? ''); ?></p>
                <?php if(!empty($e['status'])): ?><p>Status: <?= htmlspecialchars($e['status']); ?></p><?php endif; ?>
                <?php if(!empty($e['civil_status'])): ?><p>Civil Status: <?= htmlspecialchars($e['civil_status']); ?></p><?php endif; ?>
                <?php if(!empty($e['entrance_to_duty'])): ?><p>Entrance to Duty: <?= htmlspecialchars($e['entrance_to_duty']); ?></p><?php endif; ?>
                <?php if(!empty($e['unit'])): ?><p>Unit: <?= htmlspecialchars($e['unit']); ?></p><?php endif; ?>
                <?php if(!empty($e['gsis_policy_no'])): ?><p>GSIS Policy No.: <?= htmlspecialchars($e['gsis_policy_no']); ?></p><?php endif; ?>
                <?php if(!empty($e['national_reference_card_no'])): ?><p>National Reference Card No.: <?= htmlspecialchars($e['national_reference_card_no']); ?></p><?php endif; ?>
                <p>Vacational: <?= number_format($e['annual_balance'] ?? 0,3); ?> days — Sick: <?= number_format($e['sick_balance'] ?? 0,3); ?> — Force: <?= $e['force_balance'] ?? 0; ?></p>
                <p>
                    <?php if(($_SESSION['emp_id'] ?? 0) == $id || in_array($_SESSION['role'], ['admin','hr','manager'])): ?>
                        <a href="edit_employee.php?id=<?= $e['id']; ?>" class="light-btn">Edit profile</a>
                    <?php endif; ?>
                    <?php if(($_SESSION['emp_id'] ?? 0) == $id): ?>
                        &nbsp;| <a href="#" class="light-btn" onclick="openPasswordModal(); return false;">Change Password</a>
                    <?php endif; ?>
                    <?php if(($_SESSION['emp_id'] ?? 0) == $id || in_array($_SESSION['role'], ['admin','hr'])): ?>
                        &nbsp;| <a href="employee_profile.php?id=<?= $e['id']; ?>&export=1" class="light-btn">Export history</a>
                        &nbsp;| <a href="employee_profile.php?id=<?= $e['id']; ?>&export=leave_card" class="light-btn">Export leave card</a>
                    <?php endif; ?>
                    <?php if(($_SESSION['emp_id'] ?? 0) == $id): ?>
                        &nbsp;| <a href="reports.php?type=leave_card&employee_id=<?= $e['id']; ?>" class="light-btn">View Leave Card</a>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <?php if(in_array($_SESSION['role'], ['admin','hr'])): ?>
    <div class="card" style="margin-top:40px;">
        <h3>Admin actions</h3>
        <div style="display:flex;gap:16px;">
            <div style="flex:1;">
                <form method="POST" action="../controllers/AdminController.php" class="small-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="update_employee" value="1">
                    <input type="hidden" name="employee_id" value="<?= $e['id']; ?>">
                    <label>Vacational Balance</label>
                    <input type="number" step="0.001" name="annual_balance" value="<?= number_format($e['annual_balance'] ?? 0,3); ?>">
                    <label>Sick Balance</label>
                    <input type="number" step="0.001" name="sick_balance" value="<?= number_format($e['sick_balance'] ?? 0,3); ?>">
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
                    <?php
                        $ltStmt = $db->query("SELECT * FROM leave_types ORDER BY name");
                        $allTypes = $ltStmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <select name="leave_type_id">
                        <?php foreach($allTypes as $lt): ?>
                            <option value="<?= $lt['id']; ?>"><?= htmlspecialchars($lt['name']); ?></option>
                        <?php endforeach; ?>
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
                    <label>Vacational balance at time</label>
                    <input type="number" step="0.001" name="snapshot_annual_balance" value="">
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

    <?php if((($_SESSION['emp_id'] ?? 0) == $id) || in_array($_SESSION['role'], ['admin','hr'])): ?>
    <div class="card" style="margin-top:40px;">
        <h3>Record Undertime</h3>
        <form method="POST" action="../controllers/AdminController.php" class="small-form">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="record_undertime" value="1">
            <input type="hidden" name="employee_id" value="<?= $e['id']; ?>">
            <label>Date</label>
            <input type="date" name="date" required>
            <label>Minutes</label>
            <input type="number" step="0.01" name="minutes" required>
            <label><input type="checkbox" name="with_pay" value="1"> With pay</label>
            <div style="text-align:right;">
                <button type="submit">Apply Deduction</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="card" style="margin-top:40px;">
        <h3>Leave History</h3>
        <table style="font-size:12px;">
            <tr><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Submitted</th><th>Vacational Bal</th><th>Sick Bal</th><th>Force Bal</th><th>Comments</th></tr>
            <?php foreach($history as $h): ?>
            <tr>
                <td><?= htmlspecialchars($h['leave_type_name'] ?? $h['leave_type'] ?? ''); ?></td>
                <td><?= htmlspecialchars(($h['start_date'] ?? '').' to '.($h['end_date'] ?? '')); ?></td>
                <td><?= isset($h['total_days']) ? number_format($h['total_days'],3) : ''; ?></td>
                <td><?= htmlspecialchars($h['status'] ?? ''); ?></td>
                <td><?= !empty($h['created_at']) ? date('M d, Y', strtotime($h['created_at'])) : ''; ?></td>
                <td><?= isset($h['snapshot_annual_balance']) ? number_format($h['snapshot_annual_balance'],3) : '—'; ?></td>
                <td><?= isset($h['snapshot_sick_balance']) ? number_format($h['snapshot_sick_balance'],3) : '—'; ?></td>                <td><?= $h['snapshot_force_balance'] ?? '—'; ?></td>
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
                <td><?= isset($bh['old_balance']) ? number_format($bh['old_balance'],3) : ''; ?></td>
                <td><?= isset($bh['new_balance']) ? number_format($bh['new_balance'],3) : ''; ?></td>
                <td><?= !empty($bh['created_at']) ? date('M d, Y H:i', strtotime($bh['created_at'])) : ''; ?></td>
                <td><?= htmlspecialchars($bh['notes'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

</div>

<div id="imageModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:2000;justify-content:center;align-items:center;flex-direction:column;">
    <span style="color:white;font-size:20px;margin-bottom:20px;" id="modalImageName"></span>
    <img id="modalImage" style="max-width:80%;max-height:80%;border-radius:8px;">
    <button onclick="closeImageModal()" style="margin-top:20px;padding:10px 20px;background:#007bff;color:white;border:none;border-radius:4px;cursor:pointer;">Close</button>
</div>

<div id="passwordModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:2000;justify-content:center;align-items:center;">
    <div style="background:white;padding:30px;border-radius:8px;width:90%;max-width:400px;">
        <h3>Change Password</h3>
        <form method="POST" action="../controllers/UserController.php">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? ''; ?>">
            <input type="hidden" name="action" value="change_password">
            <label>Current Password</label>
            <input type="password" name="current" required style="width:100%;padding:8px;margin-bottom:15px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;">
            <label>New Password</label>
            <input type="password" name="new" required minlength="6" style="width:100%;padding:8px;margin-bottom:15px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;">
            <button type="submit" style="background:#007bff;color:white;padding:10px 20px;border:none;border-radius:4px;cursor:pointer;">Update Password</button>
            <button type="button" onclick="closePasswordModal()" style="margin-left:10px;background:#6c757d;color:white;padding:10px 20px;border:none;border-radius:4px;cursor:pointer;">Cancel</button>
        </form>
    </div>
</div>

<script>
function openImageModal(src, name) {
    document.getElementById('modalImage').src = src;
    document.getElementById('modalImageName').textContent = name;
    document.getElementById('imageModal').style.display = 'flex';
}

function closeImageModal() {
    document.getElementById('imageModal').style.display = 'none';
}

function openPasswordModal() {
    document.getElementById('passwordModal').style.display = 'flex';
}

function closePasswordModal() {
    document.getElementById('passwordModal').style.display = 'none';
}

document.getElementById('imageModal').addEventListener('click', function(e) {
    if(e.target === this) closeImageModal();
});

document.getElementById('passwordModal').addEventListener('click', function(e) {
    if(e.target === this) closePasswordModal();
});
</script>

</body>
</html>
