<?php
session_start();
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
    $stmt = $db->prepare("SELECT leave_type, start_date, end_date, total_days, status, created_at as 'submitted_date', reason FROM leave_requests WHERE employee_id = ? ORDER BY start_date");
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="leave_history_'.$id.'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out,array_keys($rows[0] ?? ['leave_type','start_date','end_date','total_days','status','submitted_date','reason']));
    foreach($rows as $r) fputcsv($out,$r);
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
                <p><a href="edit_employee.php?id=<?= $e['id']; ?>">Edit profile</a> |
                   <a href="employee_profile.php?id=<?= $e['id']; ?>&export=1">Export history</a></p>
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
        <table>
            <tr><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Submitted</th><th>Comments</th></tr>
            <?php foreach($history as $h): ?>
            <tr>
                <td><?= htmlspecialchars($h['leave_type'] ?? ''); ?></td>
                <td><?= htmlspecialchars(($h['start_date'] ?? '').' to '.($h['end_date'] ?? '')); ?></td>
                <td><?= $h['total_days'] ?? ''; ?></td>
                <td><?= htmlspecialchars($h['status'] ?? ''); ?></td>
                <td><?= !empty($h['created_at']) ? date('M d, Y', strtotime($h['created_at'])) : ''; ?></td>
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
<?php
session_start();
require_once '../config/database.php';

// allow admin/hr and the employee to view their profile
$role = $_SESSION['role'] ?? '';
$db = (new Database())->connect();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    header('Location: manage_employees.php');
    exit();
}

$stmt = $db->prepare("SELECT e.*, u.email FROM employees e JOIN users u ON e.user_id = u.id WHERE e.id = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
    die("Employee not found");
}

// export leave history CSV
if (isset($_GET['export']) && $_GET['export'] === 'history') {
    $hstmt = $db->prepare("SELECT leave_type, start_date, end_date, total_days, status, manager_comments FROM leave_requests WHERE employee_id = ? ORDER BY start_date");
    $hstmt->execute([$id]);
    $rows = $hstmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="leave_history_' . $id . '.csv"');
    $out = fopen('php://output','w');
    if (count($rows) > 0) {
        fputcsv($out, array_keys($rows[0]));
        foreach($rows as $r) fputcsv($out, $r);
    } else {
        fputcsv($out, ['No records']);
    }
    exit();
}

$hstmt = $db->prepare("SELECT * FROM leave_requests WHERE employee_id = ? ORDER BY start_date DESC");
$hstmt->execute([$id]);
$history = $hstmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Employee Profile</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="content">
    <div class="card">
        <a href="manage_employees.php">&larr; Back</a>
        <h2><?= htmlspecialchars($emp['first_name'].' '.$emp['last_name']); ?></h2>
        <?php if(!empty($emp['profile_pic'])): ?>
            <img src="<?= htmlspecialchars($emp['profile_pic']); ?>" alt="Profile" style="width:120px;height:120px;border-radius:8px;object-fit:cover;">
        <?php endif; ?>

        <p><strong>Email:</strong> <?= htmlspecialchars($emp['email']); ?></p>
        <p><strong>Department:</strong> <?= htmlspecialchars($emp['department']); ?></p>
        <p><strong>Annual balance:</strong> <?= isset($emp['annual_balance']) ? $emp['annual_balance'] : 0; ?> days</p>
        <p><strong>Sick balance:</strong> <?= isset($emp['sick_balance']) ? $emp['sick_balance'] : 0; ?> days</p>
        <p><strong>Force balance:</strong> <?= isset($emp['force_balance']) ? $emp['force_balance'] : 0; ?> days</p>

        <div style="margin-top:12px;">
            <a href="edit_employee.php?id=<?= $emp['id']; ?>">Edit Profile</a>
            &nbsp;|&nbsp;
            <a href="employee_profile.php?id=<?= $emp['id']; ?>&export=history">Export Leave History</a>
        </div>
    </div>

    <div class="card" style="margin-top:20px;">
        <h3>Leave History</h3>
        <table>
            <tr><th>Type</th><th>Start</th><th>End</th><th>Days</th><th>Status</th><th>Comments</th></tr>
            <?php foreach($history as $h): ?>
            <tr>
                <td><?= htmlspecialchars($h['leave_type']); ?></td>
                <td><?= htmlspecialchars($h['start_date']); ?></td>
                <td><?= htmlspecialchars($h['end_date']); ?></td>
                <td><?= htmlspecialchars($h['total_days']); ?></td>
                <td><?= htmlspecialchars(ucfirst($h['status'])); ?></td>
                <td><?= htmlspecialchars($h['manager_comments'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
</body>
</html>
