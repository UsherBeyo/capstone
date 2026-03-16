<?php
if (session_status() === PHP_SESSION_NONE) {
    if (session_status() === PHP_SESSION_NONE) session_start();
}
require_once '../config/database.php';

$db = (new Database())->connect();

function normalizeLeaveTypeKey(string $name): string {
    $key = strtolower(trim($name));
    $key = preg_replace('/\s+/', ' ', $key);
    $key = str_replace([' / ', ' /', '/ '], '/', $key);

    $aliases = [
        'vacation' => 'vacation leave',
        'vacational' => 'vacation leave',
        'annual' => 'vacation leave',

        'sick' => 'sick leave',

        'mandatory/force leave' => 'mandatory/forced leave',
        'mandatory force leave' => 'mandatory/forced leave',
        'mandatory/forced leave' => 'mandatory/forced leave',
        'force' => 'mandatory/forced leave',
        'force leave' => 'mandatory/forced leave',
        'forced' => 'mandatory/forced leave',
        'forced leave' => 'mandatory/forced leave',
        'mandatory leave' => 'mandatory/forced leave',
        'mandatory' => 'mandatory/forced leave',
    ];

    return $aliases[$key] ?? $key;
}

function isSickLeaveType(string $name): bool {
    return normalizeLeaveTypeKey($name) === 'sick leave';
}

function isForceLeaveType(string $name): bool {
    return normalizeLeaveTypeKey($name) === 'mandatory/forced leave';
}

function isAccrualLeaveType(string $name): bool {
    return strpos(strtolower(trim($name)), 'accrual') !== false;
}

function parseBudgetHistoryMeta(?string $notes): array {
    $meta = [];
    $notes = (string)$notes;

    if (preg_match_all('/([A-Z_]+)=([0-9.]+)/', $notes, $m, PREG_SET_ORDER)) {
        foreach ($m as $pair) {
            $meta[$pair[1]] = $pair[2];
        }
    }

    return $meta;
}

function safeExportFilename(string $name, string $fallback = 'Leave Card'): string {
    $name = preg_replace('/[\\\\\/:*?"<>|]+/', '', $name);
    $name = preg_replace('/\s+/', ' ', trim((string)$name));
    return $name !== '' ? $name : $fallback;
}

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

                $isAccrual = isAccrualLeaveType($leaveType);

        // Skip undertime rows stored in leave_requests; undertime is represented from budget_history
        if (strtolower(trim($leaveType)) === 'undertime') {
            continue;
        }

        $isSick = isSickLeaveType($leaveType);
        $isForce = isForceLeaveType($leaveType);
        $days = floatval($r['total_days']);

        $vacDed = 0.0; $sickDed = 0.0;
        $vacEarn = 0.0; $sickEarn = 0.0;

        if ($isAccrual) {
            if ($isSick) {
                $sickEarn = $days;
            } else {
                $vacEarn = $days;
            }
            $statusRaw = 'earning';
        } else {
            if ($statusRaw === 'approved') {
                if ($isSick) {
                    $sickDed = $days;
                } elseif (!$isForce) {
                    $vacDed = $days;
                }
            }
        }

        $vacBal = ($r['snapshot_annual_balance'] !== null && $r['snapshot_annual_balance'] !== '')
            ? floatval($r['snapshot_annual_balance']) : '';
        $sickBal = ($r['snapshot_sick_balance'] !== null && $r['snapshot_sick_balance'] !== '')
            ? floatval($r['snapshot_sick_balance']) : '';

        $particulars = $leaveType;
        if (!$isAccrual && stripos($particulars, 'leave') === false) {
            $particulars .= ' Leave';
        }
        if ($isForce) {
            $particulars .= ' (Force balance)';
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
            "SELECT id, created_at, trans_date, leave_type, action, old_balance, new_balance, notes
             FROM budget_history
                WHERE employee_id = ?
                AND (leave_request_id IS NULL OR leave_request_id = 0)
             ORDER BY COALESCE(trans_date, DATE(created_at)) ASC, created_at ASC, id ASC"
        );
    } else {
        $budgetStmt = $db->prepare(
            "SELECT id, created_at, leave_type, action, old_balance, new_balance, notes
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
        $notes = (string)($r['notes'] ?? '');
        $meta = parseBudgetHistoryMeta($notes);

        $vacDed = 0.0; $sickDed = 0.0;
        $vacEarn = 0.0; $sickEarn = 0.0;

        $dateCol = '';
        if ($hasTransDate && !empty($r['trans_date'])) $dateCol = (string)$r['trans_date'];
        else $dateCol = substr((string)$r['created_at'], 0, 10);

        $vacBal = '';
        $sickBal = '';

        if ($action === 'undertime_paid' || $action === 'undertime_unpaid') {
            $deltaDed = max(0, floatval($r['old_balance']) - floatval($r['new_balance']));

            $vacDed = isset($meta['UT_DEDUCT']) ? (float)$meta['UT_DEDUCT'] : $deltaDed;

            if (isset($meta['VAC_NEW'])) {
                $vacBal = (float)$meta['VAC_NEW'];
            } elseif (isset($meta['VAC'])) {
                $vacBal = (float)$meta['VAC'];
            } elseif ($r['new_balance'] !== null && $r['new_balance'] !== '') {
                $vacBal = floatval($r['new_balance']);
            }

            if (isset($meta['SICK'])) {
                $sickBal = (float)$meta['SICK'];
            }

            $particulars = 'Undertime ' . ($action === 'undertime_paid' ? '(With pay)' : '(Without pay)');
        } else {
            $deltaEarn = max(0, floatval($r['new_balance']) - floatval($r['old_balance']));
            $deltaDed  = max(0, floatval($r['old_balance']) - floatval($r['new_balance']));

            $isSick = isSickLeaveType($leaveType);
            $isForce = isForceLeaveType($leaveType);

            if (in_array($action, ['accrual', 'earning'], true)) {
                if ($isSick) {
                    $sickEarn = $deltaEarn;
                    $sickBal = floatval($r['new_balance']);
                } elseif (!$isForce) {
                    $vacEarn = $deltaEarn;
                    $vacBal = floatval($r['new_balance']);
                }
            } else {
                if ($isSick) {
                    $sickEarn = $deltaEarn;
                    $sickDed  = $deltaDed;
                    $sickBal  = floatval($r['new_balance']);
                } elseif (!$isForce) {
                    $vacEarn = $deltaEarn;
                    $vacDed  = $deltaDed;
                    $vacBal  = floatval($r['new_balance']);
                }
            }

            $particulars = ucfirst($action) . ' ' . $leaveType;
            if ($isForce) {
                $particulars .= ' (Force balance)';
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
    // ✅ Ensure chronological order by date (and stable tie-breaker)
    usort($rows, function($a, $b) {
        $da = strtotime($a['date'] ?? '') ?: 0;
        $dbb = strtotime($b['date'] ?? '') ?: 0;
        if ($da !== $dbb) return $da <=> $dbb;
        return strcmp((string)($a['particulars'] ?? ''), (string)($b['particulars'] ?? ''));
    });
    // Output as Excel (HTML)
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        $employeeFullName = trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? ''));
    if ($employeeFullName === '') {
        $employeeFullName = 'Employee ' . $id;
    }

    $leaveCardFilename = safeExportFilename('Leave Card - ' . $employeeFullName);
    header('Content-Disposition: attachment; filename="' . $leaveCardFilename . '.xls"');

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
    <title><?= htmlspecialchars(
        $pageTitle ?? 'Employee Profile'
    ); ?></title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        .profile-header { display:flex; gap:16px; align-items:center; }
        .profile-pic { width:96px; height:96px; border-radius:50%; object-fit:cover; }
        .small-form input, .small-form select { width: 100%; padding:8px; margin-bottom:8px; border-radius:6px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<div class="app-main">
    <?php
    $title = 'Employee Profile';
    $subtitle = htmlspecialchars(trim(($e['first_name'].' '.$e['last_name']) ?: $e['name']));
    $actions = [];
    if(($_SESSION['emp_id'] ?? 0) == $id || in_array($_SESSION['role'], ['admin','hr','manager'])) {
        $actions[] = '<a href="edit_employee.php?id='.$e['id'].'" class="btn btn-secondary">Edit profile</a>';
    }
    if(($_SESSION['emp_id'] ?? 0) == $id) {
        $actions[] = '<a href="#" onclick="openPasswordModal(); return false;" class="btn btn-secondary">Change Password</a>';
    }
    if(($_SESSION['emp_id'] ?? 0) == $id || in_array($_SESSION['role'], ['admin','hr'])) {
        $actions[] = '<a href="employee_profile.php?id='.$e['id'].'&export=1" class="btn btn-ghost">Export history</a>';
        $actions[] = '<a href="employee_profile.php?id='.$e['id'].'&export=leave_card" class="btn btn-ghost">Export leave card</a>';
    }
    if(($_SESSION['emp_id'] ?? 0) == $id || in_array($_SESSION['role'], ['admin','hr','manager'])) {
        $actions[] = '<a href="reports.php?type=leave_card&employee_id='.$e['id'].'" class="btn btn-ghost">View Leave Card</a>';
    }
    include __DIR__ . '/partials/ui/page-header.php';
    ?>

    <!-- 1. Employee Header Card -->
    <div class="ui-card employee-header-card">
        <div class="two-column" style="align-items:flex-start;">
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
            </div>
        </div>
    </div>


    <!-- leave balances cards -->
    <div class="leave-balance-section">
        <h3>Leave Balances</h3>
        <div class="leave-balance-cards">
            <?php
                $balances = [
                    'Vacation' => floatval($e['annual_balance'] ?? 0),
                    'Sick' => floatval($e['sick_balance'] ?? 0),
                    'Force' => floatval($e['force_balance'] ?? 0)
                ];
                foreach($balances as $label => $val):
                    $used = 0; // if additional logic exists, compute used, here placeholder
                    $total = $val; // assuming total equals current balance
                    $pct = $total > 0 ? min(100, ($used/$total)*100) : 0;
            ?>
            <div class="leave-balance-card">
                <div class="label"><?= $label; ?></div>
                <div class="value"><?= number_format($total,3); ?> days</div>
                <div class="progress-bar"><div class="progress-bar-inner" style="width:<?= $pct; ?>%;"></div></div>
                <div class="stats">
                    <span><?= number_format($used,3); ?></span>
                    <span><?= number_format($total - $used,3); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>



    <!-- 4. Admin Actions (if admin/hr) -->
    <?php if(in_array($_SESSION['role'], ['admin','hr'])): ?>
    <div class="ui-card" style="margin-top:24px;">
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
    <div class="ui-card" style="margin-top:24px;">
        <h3>Leave History</h3>
        <?php if(empty($history)): ?>
            <p>No leave history available.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="ui-table">
                <thead>
                <tr>
                    <th>Type</th>
                    <th>Dates</th>
                    <th>Days</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Vacational Bal</th>
                    <th>Sick Bal</th>
                    <th>Force Bal</th>
                    <th>Comments</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach($history as $h): ?>
                <tr>
                    <td><?= htmlspecialchars($h['leave_type_name'] ?? $h['leave_type'] ?? ''); ?></td>
                    <td><?= htmlspecialchars(($h['start_date'] ?? '').' to '.($h['end_date'] ?? '')); ?></td>
                    <td><?= isset($h['total_days']) ? trunc3($h['total_days']) : ''; ?></td>
                    <td><?= htmlspecialchars($h['status'] ?? ''); ?></td>
                    <td><?= !empty($h['created_at']) ? date('M d, Y', strtotime($h['created_at'])) : ''; ?></td>
                    <td><?= isset($h['snapshot_annual_balance']) ? trunc3($h['snapshot_annual_balance']) : '—'; ?></td>
                    <td><?= isset($h['snapshot_sick_balance']) ? trunc3($h['snapshot_sick_balance']) : '—'; ?></td>
                    <td><?= isset($h['snapshot_force_balance']) ? trunc3($h['snapshot_force_balance']) : '—'; ?></td>
                    <td><?= htmlspecialchars($h['manager_comments'] ?? $h['reason'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- 6. Budget History Table -->
    <div class="ui-card" style="margin-top:24px;">
        <h3>Budget History</h3>
        <?php if(empty($budgetHistory)): ?>
            <p>No budget change history available.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="ui-table">
                <thead>
                <tr>
                    <th>Leave Type</th>
                    <th>Action</th>
                    <th>Old Balance</th>
                    <th>New Balance</th>
                    <th>Date</th>
                    <th>Notes</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach($budgetHistory as $bh): ?>
                <tr>
                    <td><?= htmlspecialchars($bh['leave_type'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($bh['action'] ?? ''); ?></td>
                    <td><?= isset($bh['old_balance']) ? trunc3($bh['old_balance']) : ''; ?></td>
                    <td><?= isset($bh['new_balance']) ? trunc3($bh['new_balance']) : ''; ?></td>
                    <td><?= !empty($bh['created_at']) ? date('M d, Y H:i', strtotime($bh['created_at'])) : ''; ?></td>
                    <td><?= htmlspecialchars($bh['notes'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
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
// dynamic form logic for history entry
(function(){
    var typeSelect = document.getElementById('historyType');
    var totalDays = document.getElementById('totalDays');
    var earnField = document.querySelector('input[name="earning_amount"]');
    var undertimeFields = document.getElementById('undertimeFields');

    function updateRequirements(){
        var typeVal = typeSelect ? String(typeSelect.value) : '';

        var isAccrual = typeVal === '0';
        var isUT = typeVal === '-1';

        // Hide UT by default
        if (undertimeFields) undertimeFields.style.display = 'none';

        // Earning field defaults
        if (earnField) {
            earnField.required = false;
            earnField.disabled = true;
        }

        // Total days defaults
        if (totalDays) {
            totalDays.required = false;
            totalDays.disabled = true;
        }

        if (isAccrual) {
            // accrual: earning required, no total_days
            if (earnField) { earnField.disabled = false; earnField.required = true; }
            if (totalDays) totalDays.value = '';
        } else if (isUT) {
            // undertime: show UT fields, no total_days, no earning
            if (undertimeFields) undertimeFields.style.display = 'block';
            if (totalDays) totalDays.value = '';
        } else {
            // normal leave: total_days required
            if (totalDays) { totalDays.disabled = false; totalDays.required = true; }
        }
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', updateRequirements);
    }

    // Initial run
    updateRequirements();
})();
</script>

</body>
</html>
