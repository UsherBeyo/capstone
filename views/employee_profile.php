<?php
if (session_status() === PHP_SESSION_NONE) {
    if (session_status() === PHP_SESSION_NONE) session_start();
}
require_once '../config/database.php';

$db = (new Database())->connect();

function trunc3($v): string {
    if ($v === null || $v === '') return '';
    $n = (float)$v;
    $t = floor($n * 1000) / 1000;         // TRUNCATE (not round)
    return number_format($t, 3, '.', ''); // always 3 decimals
}

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

// detect trans_date column if budget_history exists (needed for budget rows export)
$hasTransDate = false;
try {
    $db->query("SELECT trans_date FROM budget_history LIMIT 1");
    $hasTransDate = true;
} catch (Throwable $t) {
    $hasTransDate = false;
}

// export leave card - merged leave history & budget history with accurate balances
// export leave card - complete transaction history (leave_requests + budget_history)
if (isset($_GET['export']) && $_GET['export'] === 'leave_card' && (
        $_SESSION['role'] === 'admin' ||
        $_SESSION['role'] === 'hr' ||
        $_SESSION['role'] === 'manager' ||
        ($_SESSION['emp_id'] ?? 0) == $id
    )) {

    $empId = $id;
    $rows = [];

    /**
     * ONLY Leave Requests (no budget_history merging)
     */
    $leaveStmt = $db->prepare(
        "SELECT 
            lr.start_date,
            lr.created_at,
            COALESCE(lt.name, lr.leave_type) AS leave_type,
            lr.status,
            lr.total_days,
            lr.snapshot_annual_balance,
            lr.snapshot_sick_balance,
            lr.snapshot_force_balance
         FROM leave_requests lr
         LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
         WHERE lr.employee_id = ?
         ORDER BY lr.start_date ASC, lr.created_at ASC"
    );
    $leaveStmt->execute([$empId]);

    foreach ($leaveStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $leaveType = trim((string)$r['leave_type']);
        $statusRaw = strtolower(trim((string)$r['status']));

        $typeLower = strtolower($leaveType);
        $isAccrual = (strpos($typeLower, 'accrual') !== false);
        
        // Skip accrual/undertime entries from leave_requests; they'll be exported from budget_history instead
        if (strtolower($leaveType) === 'undertime') {
            continue;
        }   
        
        $isSick = (strtolower($leaveType) === 'sick');
        $days = floatval($r['total_days']);

        // Deduction only if approved
        $vacDed = 0.0; $sickDed = 0.0;
        $vacEarn = 0.0; $sickEarn = 0.0;

        if ($isAccrual) {
            $vacEarn = $days;
            $sickEarn = $days;
            $statusRaw = 'earning';
        } else {
            // your existing approved-deduction logic
        }

        // Use snapshot values EXACTLY as stored (no lookups, no calculations)
        $vacBal = ($r['snapshot_annual_balance'] !== null && $r['snapshot_annual_balance'] !== '')
            ? floatval($r['snapshot_annual_balance']) : '';
        $sickBal = ($r['snapshot_sick_balance'] !== null && $r['snapshot_sick_balance'] !== '')
            ? floatval($r['snapshot_sick_balance']) : '';

        $partLabel = strtolower($leaveType);
        if (strpos($partLabel, 'accrual') !== false) {
            $particulars = $leaveType;
        } else {
            $particulars = $leaveType . ' Leave';
        }

        $rows[] = [
            'date' => $r['start_date'] ?: substr((string)$r['created_at'], 0, 10),
            'particulars' => $particulars,
            'vac_earned' => $vacEarn,
            'vac_deducted' => $vacDed,
            'vac_balance' => $vacBal,
            'sick_earned' => $sickEarn,
            'sick_deducted' => $sickDed,
            'sick_balance' => $sickBal,
            'status' => ucfirst($statusRaw)
        ];
    }

    // 2) Budget history rows (earnings, adjustments, undertime, deductions)
    if ($hasTransDate) {
        $budgetStmt = $db->prepare(
            "SELECT id, created_at, trans_date, leave_type, action, old_balance, new_balance
             FROM budget_history
                WHERE employee_id = ?
                AND (leave_request_id IS NULL OR leave_request_id = 0)
             ORDER BY COALESCE(trans_date, DATE(created_at)) ASC, created_at ASC, id ASC"
        );
    } else {
        $budgetStmt = $db->prepare(
            "SELECT id, created_at, leave_type, action, old_balance, new_balance
             FROM budget_history
                WHERE employee_id = ?
                AND (leave_request_id IS NULL OR leave_request_id = 0)
             ORDER BY created_at ASC, id ASC"
        );
    }
    $budgetStmt->execute([$empId]);

    foreach ($budgetStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $leaveType = trim((string)$r['leave_type']);
        $action = strtolower(trim((string)$r['action']));

        $vacDed = 0; $sickDed = 0;
        $vacEarn = 0; $sickEarn = 0;
        $vacBal = ''; $sickBal = '';

        // Date column
        $dateCol = '';
        if ($hasTransDate && !empty($r['trans_date'])) {
            $dateCol = (string)$r['trans_date'];
        } else {
            $dateCol = substr((string)$r['created_at'], 0, 10);
        }

        // Particulars formatting
        if ($action === 'undertime_paid' || $action === 'undertime_unpaid') {
            $particulars = $action . ' ' . $leaveType;
        } else {
            $particulars = ucfirst($action) . ' ' . $leaveType;
        }

        $deltaEarn = max(0, floatval($r['new_balance']) - floatval($r['old_balance']));
        $deltaDed  = max(0, floatval($r['old_balance']) - floatval($r['new_balance']));

        if (in_array($action, ['accrual', 'earning'], true)) {
            // earning/accrual entries should show the earned amount and resulting balance
            $vacEarn = $deltaEarn;
            $sickEarn = $deltaEarn;
            // determine which bucket the balance applies to based on leave type text
            if (strpos(strtolower($leaveType), 'sick') !== false) {
                $sickBal = floatval($r['new_balance']);
            } else {
                $vacBal = floatval($r['new_balance']);
            }
        } else {
            if (in_array(strtolower($leaveType), ['annual','vacational','vacation'], true)) {
                $vacEarn = $deltaEarn;
                $vacDed  = $deltaDed;
            } elseif (strtolower($leaveType) === 'sick') {
                $sickEarn = $deltaEarn;
                $sickDed  = $deltaDed;
            }
        }

        if (!in_array($action, ['accrual', 'earning'], true)) {
            if (in_array(strtolower($leaveType), ['annual','vacational','vacation'], true)) {
                $vacBal = floatval($r['new_balance']);
            } elseif (strtolower($leaveType) === 'sick') {
                $sickBal = floatval($r['new_balance']);
            }
        }

        $rows[] = [
            'date' => $dateCol,
            'particulars' => $particulars,
            'vac_earned' => $vacEarn,
            'vac_deducted' => $vacDed,
            'vac_balance' => $vacBal,
            'sick_earned' => $sickEarn,
            'sick_deducted' => $sickDed,
            'sick_balance' => $sickBal,
            'status' => ucfirst($action)
        ];
    }

    // Output as Excel (HTML)
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="leave_card_'.$id.'_'.date('Y-m-d').'.xls"');

    echo "<table border=1>\n";
    echo "<tr><td colspan='9' style='font-weight:bold;background-color:#d3d3d3;'><strong>Employee Information</strong></td></tr>\n";
    echo "<tr><td><strong>Employee ID</strong></td><td>".htmlspecialchars($e['id'])."</td><td><strong>Name</strong></td><td>".htmlspecialchars(trim(($e['first_name'].' '.$e['last_name']) ?: $e['name']))."</td><td><strong>Position</strong></td><td>".htmlspecialchars($e['position'] ?? '')."</td><td><strong>Department</strong></td><td>".htmlspecialchars($e['department'])."</td></tr>\n";
    echo "<tr><td><strong>Status</strong></td><td>".htmlspecialchars($e['status'] ?? '')."</td><td><strong>Civil Status</strong></td><td>".htmlspecialchars($e['civil_status'] ?? '')."</td><td><strong>Entrance to Duty</strong></td><td>".htmlspecialchars($e['entrance_to_duty'] ?? '0000-00-00')."</td><td><strong>Unit</strong></td><td>".htmlspecialchars($e['unit'] ?? '')."</td></tr>\n";
    echo "<tr><td colspan='9'>&nbsp;</td></tr>\n";

    echo "<tr><td colspan='9' style='font-weight:bold;background-color:#d3d3d3;'><strong>LEAVE CARD TRANSACTIONS</strong></td></tr>\n";
    echo "<tr style='background-color:#e0e0e0;'>";
    echo "<th>Date</th><th>Particulars</th><th>Vac Earned</th><th>Vac Deducted</th><th>Vac Balance</th><th>Sick Earned</th><th>Sick Deducted</th><th>Sick Balance</th><th>Status</th>";
    echo "</tr>\n";

    foreach ($rows as $row) {
        echo "<tr>";
        echo "<td>".htmlspecialchars($row['date'])."</td>";
        echo "<td>".htmlspecialchars($row['particulars'])."</td>";
        echo "<td>".($row['vac_earned'] != 0 ? trunc3($row['vac_earned']) : '')."</td>";
        echo "<td>".($row['vac_deducted'] != 0 ? trunc3($row['vac_deducted']) : '')."</td>";
        echo "<td>" . ($row['vac_balance'] === '' ? '' : trunc3($row['vac_balance'])) . "</td>";
        echo "<td>" . ($row['sick_earned'] != 0 ? trunc3($row['sick_earned']) : '') . "</td>";
        echo "<td>".($row['sick_deducted'] != 0 ? trunc3($row['sick_deducted']) : '')."</td>";
        echo "<td>" . ($row['sick_balance'] === '' ? '' : trunc3($row['sick_balance'])) . "</td>";
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
                $cell = trunc3($cell);
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

// fetch all leave types for admin modals
$allTypes = [];
$stmtTypes = $db->query("SELECT * FROM leave_types ORDER BY name");
if ($stmtTypes) {
    $allTypes = $stmtTypes->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Employee Profile</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        .profile-header { display:flex; gap:16px; align-items:center; }
        .profile-pic { width:96px; height:96px; border-radius:50%; object-fit:cover; }
        .small-form input, .small-form select { width: 100%; padding:8px; margin-bottom:8px; border-radius:6px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<div class="content">
    
    <!-- 1. Employee Header Card -->
    <div class="card">
        <div style="display:flex;gap:24px;align-items:flex-start;">
            <div>
                <?php if(!empty($e['profile_pic'])): ?>
                    <img src="<?= htmlspecialchars($e['profile_pic']); ?>" alt="Profile" style="width:80px;height:80px;border-radius:50%;object-fit:cover;cursor:pointer;border:2px solid var(--border);" onclick="openImageModal('<?= htmlspecialchars($e['profile_pic']); ?>', '<?= htmlspecialchars($e['first_name'].' '.$e['last_name']); ?>')">
                <?php else: ?>
                    <div style="width:80px;height:80px;border-radius:50%;background:var(--bg);display:flex;align-items:center;justify-content:center;font-size:32px;border:2px solid var(--border);">👤</div>
                <?php endif; ?>
            </div>
            <div style="flex:1;">
                <h2 style="margin:0 0 8px 0;"><?= htmlspecialchars(trim(($e['first_name'].' '.$e['last_name']) ?: $e['name'])); ?></h2>
                <p style="margin:0 0 4px 0;font-size:14px;color:#6b7280;"><?= htmlspecialchars($e['email']); ?></p>
                <p style="margin:0 0 12px 0;font-size:14px;">Department: <strong><?= htmlspecialchars($e['department']); ?></strong></p>
                <p style="margin:0 0 12px 0;font-size:14px;">Position: <strong><?= htmlspecialchars($e['position'] ?? '—'); ?></strong></p>
                <p style="margin:0 0 12px 0;font-size:14px;">Entrance to Duty: <strong><?= htmlspecialchars($e['entrance_to_duty'] ?? '0000-00-00'); ?></strong></p>
                <div style="display:flex;gap:8px;padding:12px;background:#f8fafc;border-radius:8px;font-size:14px;">
                    <span>Vacational: <strong><?= trunc3($e['annual_balance'] ?? 0); ?> days</strong></span>
                    <span style="color:#d1d5db;">|</span>
                    <span>Sick: <strong><?= trunc3($e['sick_balance'] ?? 0); ?></strong></span>
                    <span style="color:#d1d5db;">|</span>
                    <span>Force: <strong><?= trunc3($e['force_balance'] ?? 0); ?></strong></span>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. Action Links Row -->
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin:24px 0;">
        <?php if(($_SESSION['emp_id'] ?? 0) == $id || in_array($_SESSION['role'], ['admin','hr','manager'])): ?>
            <a href="edit_employee.php?id=<?= $e['id']; ?>" class="action-btn">Edit profile</a>
        <?php endif; ?>
        <?php if(($_SESSION['emp_id'] ?? 0) == $id): ?>
            <a href="#" onclick="openPasswordModal(); return false;" class="action-btn">Change Password</a>
        <?php endif; ?>
        <?php if(($_SESSION['emp_id'] ?? 0) == $id || in_array($_SESSION['role'], ['admin','hr'])): ?>
            <a href="employee_profile.php?id=<?= $e['id']; ?>&export=1" class="action-btn">Export history</a>
            <a href="employee_profile.php?id=<?= $e['id']; ?>&export=leave_card" class="action-btn">Export leave card</a>
        <?php endif; ?>
        <?php if(($_SESSION['emp_id'] ?? 0) == $id || in_array($_SESSION['role'], ['admin','hr','manager'])): ?>
            <a href="reports.php?type=leave_card&employee_id=<?= $e['id']; ?>" class="action-btn">View Leave Card</a>
        <?php endif; ?>
    </div>



    <!-- 4. Admin Actions (if admin/hr) -->
    <?php if(in_array($_SESSION['role'], ['admin','hr'])): ?>
    <div class="card" style="margin-top:24px;">
        <h3>Admin Actions</h3>
        <div style="display:flex;gap:16px;flex-wrap:wrap;">
            <button id="btnUpdateBalances" class="action-btn">Update Balances</button>
            <button id="btnAddHistory" class="action-btn">Add Leave History Entry</button>
            <button id="btnRecordUndertime" class="action-btn">Record Undertime</button>
        </div>
    </div>
    <?php endif; ?>
    <script>
        ['btnUpdateBalances','btnAddHistory','btnRecordUndertime'].forEach(function(id){
            var el = document.getElementById(id);
            if(el){
                el.addEventListener('click', function(){
                    var target = 'modal' + id.replace('btn','');
                    openModal(target);
                });
            }
        });
    </script>

    <!-- 5. Leave History Table -->
    <div class="card" style="margin-top:24px;">
        <h3>Leave History</h3>
        <?php if(empty($history)): ?>
            <p>No leave history available.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;font-size:13px;">
                <tr style="background:#f8fafc;border-bottom:2px solid var(--border);">
                    <th style="padding:12px;text-align:left;font-weight:600;">Type</th>
                    <th style="padding:12px;text-align:left;font-weight:600;">Dates</th>
                    <th style="padding:12px;text-align:left;font-weight:600;">Days</th>
                    <th style="padding:12px;text-align:left;font-weight:600;">Status</th>
                    <th style="padding:12px;text-align:left;font-weight:600;">Submitted</th>
                    <th style="padding:12px;text-align:left;font-weight:600;">Vacational Bal</th>
                    <th style="padding:12px;text-align:left;font-weight:600;">Sick Bal</th>
                    <th style="padding:12px;text-align:left;font-weight:600;">Force Bal</th>
                    <th style="padding:12px;text-align:left;font-weight:600;">Comments</th>
                </tr>
                <?php foreach($history as $h): ?>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:10px 12px;"><?= htmlspecialchars($h['leave_type_name'] ?? $h['leave_type'] ?? ''); ?></td>
                    <td style="padding:10px 12px;"><?= htmlspecialchars(($h['start_date'] ?? '').' to '.($h['end_date'] ?? '')); ?></td>
                    <td style="padding:10px 12px;"><?= isset($h['total_days']) ? trunc3($h['total_days']) : ''; ?></td>
                    <td style="padding:10px 12px;"><?= htmlspecialchars($h['status'] ?? ''); ?></td>
                    <td style="padding:10px 12px;"><?= !empty($h['created_at']) ? date('M d, Y', strtotime($h['created_at'])) : ''; ?></td>
                    <td style="padding:10px 12px;"><?= isset($h['snapshot_annual_balance']) ? trunc3($h['snapshot_annual_balance']) : '—'; ?></td>
                    <td style="padding:10px 12px;"><?= isset($h['snapshot_sick_balance']) ? trunc3($h['snapshot_sick_balance']) : '—'; ?></td>
                    <td style="padding:10px 12px;"><?= isset($h['snapshot_force_balance']) ? trunc3($h['snapshot_force_balance']) : '—'; ?></td>
                    <td style="padding:10px 12px;"><?= htmlspecialchars($h['manager_comments'] ?? $h['reason'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- 6. Budget History Table -->
    <div class="card" style="margin-top:24px;">
        <h3>Budget History</h3>
        <?php if(empty($budgetHistory)): ?>
            <p>No budget change history available.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;font-size:13px;">
                <tr style="background:#f8fafc;border-bottom:2px solid var(--border);">
                    <th style="padding:12px;text-align:left;font-weight:600;">Leave Type</th>
                    <th style="padding:12px;text-align:left;font-weight:600;">Action</th>
                    <th style="padding:12px;text-align:left;font-weight:600;">Old Balance</th>
                    <th style="padding:12px;text-align:left;font-weight:600;">New Balance</th>
                    <th style="padding:12px;text-align:left;font-weight:600;">Date</th>
                    <th style="padding:12px;text-align:left;font-weight:600;">Notes</th>
                </tr>
                <?php foreach($budgetHistory as $bh): ?>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:10px 12px;"><?= htmlspecialchars($bh['leave_type'] ?? ''); ?></td>
                    <td style="padding:10px 12px;"><?= htmlspecialchars($bh['action'] ?? ''); ?></td>
                    <td style="padding:10px 12px;"><?= isset($bh['old_balance']) ? trunc3($bh['old_balance']) : ''; ?></td>
                    <td style="padding:10px 12px;"><?= isset($bh['new_balance']) ? trunc3($bh['new_balance']) : ''; ?></td>
                    <td style="padding:10px 12px;"><?= !empty($bh['created_at']) ? date('M d, Y H:i', strtotime($bh['created_at'])) : ''; ?></td>
                    <td style="padding:10px 12px;"><?= htmlspecialchars($bh['notes'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>
<!-- admin modals -->
<div id="passwordModal" class="modal">
  <div class="modal-content small">
    <h3>Change Password</h3>
    <form method="POST" action="../controllers/UserController.php">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
      <input type="hidden" name="action" value="change_password">
      <label>Current Password</label>
      <input type="password" name="current" required>
      <label>New Password</label>
      <input type="password" name="new" required minlength="6">
      <div style="text-align:right;margin-top:12px;">
           <button type="submit">Update</button>
           <button type="button" onclick="closeModal('passwordModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<div id="modalUpdateBalances" class="modal">
  <div class="modal-content small">
    <h3>Update Balances</h3>
    <form method="POST" action="../controllers/AdminController.php">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
      <input type="hidden" name="update_employee" value="1">
      <input type="hidden" name="employee_id" value="<?= $e['id']; ?>">
      <label>Vacational Balance</label>
      <input type="number" step="0.001" name="annual_balance" value="<?= trunc3($e['annual_balance'] ?? 0); ?>">
      <label>Sick Balance</label>
      <input type="number" step="0.001" name="sick_balance" value="<?= trunc3($e['sick_balance'] ?? 0); ?>">
      <label>Force Balance</label>
      <input type="number" name="force_balance" value="<?= trunc3($e['force_balance'] ?? 0); ?>">
      <div style="text-align:right;">
          <button type="submit">Update balances</button>
          <button type="button" onclick="closeModal('modalUpdateBalances')">Cancel</button>
      </div>
    </form>
  </div>
</div>
<div id="modalAddHistory" class="modal">
  <div class="modal-content">
    <h3>Add Leave History Entry</h3>
    <form method="POST" action="../controllers/AdminController.php">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
      <input type="hidden" name="add_history" value="1">
      <input type="hidden" name="employee_id" value="<?= $e['id']; ?>">
      <label>Leave Type</label>
      <select id="historyType" name="leave_type_id" style="width:100%;padding:8px 12px;margin-bottom:12px;border:1px solid var(--border);border-radius:6px;background:#fff;color:#111827;font-size:14px;cursor:pointer;">
        <option value="0">Vacational Accrual Earned</option>
        <option value="-1">Undertime</option>
        <?php foreach($allTypes as $lt): ?>
          <option value="<?= $lt['id']; ?>"><?= htmlspecialchars($lt['name']); ?></option>
        <?php endforeach; ?>
      </select>
      <label>Earning (1.25 days, optional)</label>
      <input type="number" step="0.001" name="earning_amount" value="">
      <label>Start Date</label>
      <input type="date" name="start_date" required>
      <label>End Date</label>
      <input type="date" name="end_date" required>
      <label>Total Days</label>
      <input id="totalDays" type="number" step="0.001" name="total_days" required>
      <label>Comments</label>
      <input type="text" name="reason">
      <!-- UNDERTIME FIELDS (only show when type = -1) -->
<div id="undertimeFields" style="display:none;margin-top:12px;">
  <strong>Undertime Details</strong>
  <div style="display:flex;gap:10px;margin-top:8px;">
    <div style="flex:1;">
      <label>Hours</label>
      <input id="utHours" type="number" step="1" name="undertime_hours" value="0" min="0">
    </div>
    <div style="flex:1;">
      <label>Minutes</label>
      <input id="utMins" type="number" step="1" name="undertime_minutes" value="0" min="0" max="59">
    </div>
  </div>
  <label style="margin-top:8px;display:block;">
    <input type="checkbox" name="undertime_with_pay" value="1"> With pay
  </label>
  <p style="font-size:12px;opacity:0.8;margin-top:6px;">
    Deduction uses chart: 480 mins = 1.000 day (8 hours = 1 day).
  </p>
</div>
      <hr>
      <p style="font-size:12px;opacity:0.8;">(optional) supply the leave balances that were available at the time of this historical entry.</p>
      <label>Vacational balance at time</label>
      <input type="number" step="0.001" name="snapshot_annual_balance" value="">
      <label>Sick balance at time</label>
      <input type="number" step="0.001" name="snapshot_sick_balance" value="">
      <label>Force balance at time</label>
      <input type="number" step="0.001" name="snapshot_force_balance" value="">
      <div style="text-align:right;">
        <button type="submit">Add history entry</button>
        <button type="button" onclick="closeModal('modalAddHistory')">Cancel</button>
      </div>
    </form>
  </div>
</div>
<div id="modalRecordUndertime" class="modal">
  <div class="modal-content small">
    <h3>Record Undertime</h3>
    <form method="POST" action="../controllers/AdminController.php" class="small-form">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
      <input type="hidden" name="record_undertime" value="1">
      <input type="hidden" name="employee_id" value="<?= $e['id']; ?>">
      <label>Date</label>
      <input type="date" name="date" required>
      <div style="display:flex;gap:10px;">
        <div style="flex:1;">
          <label>Hours</label>
          <input type="number" step="1" name="hours" value="0" min="0">
        </div>
        <div style="flex:1;">
          <label>Minutes</label>
          <input type="number" step="1" name="undertime_minutes" value="0" min="0" max="59">
        </div>
      </div>
      <label><input type="checkbox" name="with_pay" value="1"> With pay</label>
      <div style="text-align:right;">
        <button type="submit">Apply Deduction</button>
        <button type="button" onclick="closeModal('modalRecordUndertime')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function closeImageModal() {
    closeModal('imageModal');
}

function openPasswordModal() {
    openModal('passwordModal');
}

function closePasswordModal() {
    closeModal('passwordModal');
}

function openModal(id) {
    var m = document.getElementById(id);
    if(m) m.classList.add('open');
}

function closeModal(id) {
    var m = document.getElementById(id);
    if(m) m.classList.remove('open');
}

// allow clicking outside to close
['imageModal','passwordModal','modalUpdateBalances','modalAddHistory','modalRecordUndertime'].forEach(function(id){
    var el = document.getElementById(id);
    if(el){
        el.addEventListener('click', function(e){
            if(e.target === this) closeModal(id);
        });
    }
});

// dynamic form logic for history entry
(function(){
    var typeSelect = document.getElementById('historyType');
    var totalDays = document.getElementById('totalDays');
    var earnField = document.querySelector('input[name="earning_amount"]');
    var utHours = document.getElementById('utHours');
    var utMins = document.getElementById('utMins');

    function updateRequirements(){
        var typeVal = typeSelect ? typeSelect.value : '';

        var isAccrual = typeVal === '0';
        var isUT = typeVal === '-1';

        var undertimeFields = document.getElementById('undertimeFields');

        // default UI
        if (undertimeFields) undertimeFields.style.display = 'none';

        // earning field control
        if (earnField) {
            earnField.required = false;
            earnField.disabled = true;
            earnField.value = earnField.value || '';
        }

        // total days control
        totalDays.required = false;
        totalDays.disabled = true;

        if (isAccrual) {
            // accrual: earning required, no total_days
            if (earnField) { earnField.disabled = false; earnField.required = true; }
            totalDays.value = '';
        } else if (isUT) {
            // undertime: show UT fields, no total_days, no earning
            if (undertimeFields) undertimeFields.style.display = 'block';
            totalDays.value = '';
        } else {
            // normal leave
            totalDays.disabled = false;
            totalDays.required = true;
        }
        }

    [typeSelect, earnField, utHours, utMins].forEach(function(el){
        if(el){
            el.addEventListener('change', updateRequirements);
            el.addEventListener('input', updateRequirements);
        }
    });
    // initial run to set proper state
    updateRequirements();
})();
</script>

</body>
</html>
