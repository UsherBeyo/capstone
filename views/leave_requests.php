<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/database.php';

if (!in_array($_SESSION['role'], ['admin','manager','department_head','hr','personnel'], true)) {
    die("Access denied");
}
$db = (new Database())->connect();
$role = $_SESSION['role'];
$userId = (int)($_SESSION['user_id'] ?? 0);

$whereDeptHead = "lr.workflow_status = 'pending_department_head' AND lr.status = 'pending'";
$wherePersonnel = "lr.workflow_status = 'pending_personnel' AND lr.status = 'pending'";

if (in_array($role, ['manager','department_head'], true)) {
    $whereDeptHead .= " AND lr.department_head_user_id = " . $userId;
}
if (in_array($role, ['personnel','hr'], true)) {
    // personnel sees only their stage
} elseif ($role !== 'admin') {
    $wherePersonnel .= " AND 1 = 0";
}

$pendingDeptHead = $db->query("SELECT lr.*, e.first_name, e.middle_name, e.last_name, u.email, COALESCE(lt.name, lr.leave_type) AS leave_type_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE {$whereDeptHead}
    ORDER BY lr.start_date DESC")->fetchAll(PDO::FETCH_ASSOC);

$pendingPersonnel = $db->query("SELECT lr.*, e.first_name, e.middle_name, e.last_name, u.email, COALESCE(lt.name, lr.leave_type) AS leave_type_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE {$wherePersonnel}
    ORDER BY lr.start_date DESC")->fetchAll(PDO::FETCH_ASSOC);

$finalized = $db->query("SELECT lr.*, e.first_name, e.middle_name, e.last_name, u.email, COALESCE(lt.name, lr.leave_type) AS leave_type_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE lr.workflow_status = 'finalized' OR lr.status = 'approved'
    ORDER BY lr.start_date DESC")->fetchAll(PDO::FETCH_ASSOC);

$returnedOrRejected = $db->query("SELECT lr.*, e.first_name, e.middle_name, e.last_name, u.email, COALESCE(lt.name, lr.leave_type) AS leave_type_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE lr.workflow_status IN ('rejected_department_head','returned_by_personnel') OR lr.status = 'rejected'
    ORDER BY lr.start_date DESC")->fetchAll(PDO::FETCH_ASSOC);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Leave Requests Workflow</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="content">
    <h2>Leave Requests Workflow</h2>

    <div class="card">
        <h3>Pending Department Head Approval</h3>
        <?php if(empty($pendingDeptHead)): ?>
            <p>No requests pending for Department Head approval.</p>
        <?php else: ?>
        <div class="table-container">
        <table width="100%">
            <tr>
                <th>Employee</th><th>Email</th><th>Type</th><th>Dates</th><th>Days</th><th>Reason</th><th>Action</th>
            </tr>
            <?php foreach($pendingDeptHead as $r): ?>
            <tr>
                <td><?= htmlspecialchars(trim($r['first_name'].' '.($r['middle_name'] ?? '').' '.$r['last_name'])); ?></td>
                <td><?= htmlspecialchars($r['email']); ?></td>
                <td><?= htmlspecialchars($r['leave_type_name']); ?></td>
                <td><?= htmlspecialchars($r['start_date'].' to '.$r['end_date']); ?></td>
                <td><?= number_format((float)$r['total_days'],3); ?></td>
                <td><?= htmlspecialchars($r['reason'] ?? ''); ?></td>
                <td>
                    <div class="action-forms">
                        <form method="POST" action="../controllers/LeaveController.php">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="leave_id" value="<?= $r['id']; ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit">Approve & Forward</button>
                        </form>
                        <form method="POST" action="../controllers/LeaveController.php">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="leave_id" value="<?= $r['id']; ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="text" name="comments" placeholder="Reason" required>
                            <button type="submit">Reject</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Pending Personnel Review</h3>
        <?php if(empty($pendingPersonnel)): ?>
            <p>No requests pending for personnel review.</p>
        <?php else: ?>
        <div class="table-container">
        <table width="100%">
            <tr>
                <th>Employee</th><th>Email</th><th>Type</th><th>Dates</th><th>Days</th><th>Dept Head Comment</th><th>Action</th>
            </tr>
            <?php foreach($pendingPersonnel as $r): ?>
            <tr>
                <td><?= htmlspecialchars(trim($r['first_name'].' '.($r['middle_name'] ?? '').' '.$r['last_name'])); ?></td>
                <td><?= htmlspecialchars($r['email']); ?></td>
                <td><?= htmlspecialchars($r['leave_type_name']); ?></td>
                <td><?= htmlspecialchars($r['start_date'].' to '.$r['end_date']); ?></td>
                <td><?= number_format((float)$r['total_days'],3); ?></td>
                <td><?= htmlspecialchars($r['department_head_comments'] ?? ''); ?></td>
                <td>
                    <div class="action-forms">
                        <form method="POST" action="../controllers/LeaveController.php">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="leave_id" value="<?= $r['id']; ?>">
                            <input type="hidden" name="action" value="approve">
                            <input type="text" name="comments" placeholder="Optional note">
                            <button type="submit">Final Approve</button>
                        </form>
                        <form method="POST" action="../controllers/LeaveController.php">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="leave_id" value="<?= $r['id']; ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="text" name="comments" placeholder="Reason" required>
                            <button type="submit">Return / Reject</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Finalized / Approved</h3>
        <?php if(empty($finalized)): ?>
            <p>No finalized requests.</p>
        <?php else: ?>
        <div class="table-container">
        <table width="100%">
            <tr><th>Employee</th><th>Email</th><th>Type</th><th>Dates</th><th>Days</th><th>Workflow</th><th>Print Status</th><?php if(in_array($role, ['personnel','hr','admin'], true)): ?><th>Action</th><?php endif; ?></tr>
            <?php foreach($finalized as $r): ?>
            <tr>
                <td><?= htmlspecialchars(trim($r['first_name'].' '.($r['middle_name'] ?? '').' '.$r['last_name'])); ?></td>
                <td><?= htmlspecialchars($r['email']); ?></td>
                <td><?= htmlspecialchars($r['leave_type_name']); ?></td>
                <td><?= htmlspecialchars($r['start_date'].' to '.$r['end_date']); ?></td>
                <td><?= number_format((float)$r['total_days'],3); ?></td>
                <td><?= htmlspecialchars($r['workflow_status'] ?? 'finalized'); ?></td>
                <td><?= htmlspecialchars($r['print_status'] ?? '—'); ?></td>
                <?php if(in_array($role, ['personnel','hr','admin'], true)): ?>
                <td><a href="print_leave_form.php?id=<?= $r['id']; ?>" target="_blank">Print Form</a></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Rejected / Returned</h3>
        <?php if(empty($returnedOrRejected)): ?>
            <p>No rejected or returned requests.</p>
        <?php else: ?>
        <div class="table-container">
        <table width="100%">
            <tr><th>Employee</th><th>Email</th><th>Type</th><th>Dates</th><th>Status</th><th>Workflow</th><th>Comments</th></tr>
            <?php foreach($returnedOrRejected as $r): ?>
            <tr>
                <td><?= htmlspecialchars(trim($r['first_name'].' '.($r['middle_name'] ?? '').' '.$r['last_name'])); ?></td>
                <td><?= htmlspecialchars($r['email']); ?></td>
                <td><?= htmlspecialchars($r['leave_type_name']); ?></td>
                <td><?= htmlspecialchars($r['start_date'].' to '.$r['end_date']); ?></td>
                <td><?= htmlspecialchars($r['status']); ?></td>
                <td><?= htmlspecialchars($r['workflow_status'] ?? '—'); ?></td>
                <td><?= htmlspecialchars($r['personnel_comments'] ?? $r['department_head_comments'] ?? $r['manager_comments'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>