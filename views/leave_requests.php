<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/database.php';

if ($_SESSION['role'] !== 'admin') {
    die("Access denied");
}
$db = (new Database())->connect();

// split the requests into pending/approved/rejected
// use LEFT JOIN on users so that a missing user_id on an employee doesn't hide the request
$pending = $db->query("SELECT lr.*, e.first_name, e.last_name, u.email, COALESCE(lt.name, lr.leave_type) AS leave_type_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE lr.status = 'pending'
    ORDER BY lr.start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$approved = $db->query("SELECT lr.*, e.first_name, e.last_name, u.email, COALESCE(lt.name, lr.leave_type) AS leave_type_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE lr.status = 'approved'
    ORDER BY lr.start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$rejected = $db->query("SELECT lr.*, e.first_name, e.last_name, u.email, COALESCE(lt.name, lr.leave_type) AS leave_type_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE lr.status = 'rejected'
    ORDER BY lr.start_date DESC")->fetchAll(PDO::FETCH_ASSOC);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>All Leave Requests</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="content">
    <h2>All Leave Requests</h2>

    <div class="card">
        <h3>Pending</h3>
        <?php if(empty($pending)): ?>
            <p>No pending requests.</p>
        <?php else: ?>
        <div class="table-container">
        <table border="1" width="100%">
        <tr><th>Employee</th><th>Email</th><th>Type</th><th>Dates</th><th>Days</th><th>Reason</th><th>Action</th></tr>
        <?php foreach($pending as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['first_name'].' '.$r['last_name']); ?></td>
            <td><?= htmlspecialchars($r['email']); ?></td>
            <td><?= htmlspecialchars($r['leave_type_name']); ?></td>
            <td><?= $r['start_date'].' to '.$r['end_date']; ?></td>
            <td><?= number_format($r['total_days'],3); ?></td>
            <td><?= htmlspecialchars($r['reason'] ?? ''); ?></td>
            <td>
                <div class="action-forms">
                    <form method="POST" action="../controllers/LeaveController.php">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="leave_id" value="<?= $r['id']; ?>">
                        <button type="submit" name="action" value="approve">Approve</button>
                    </form>
                    <form method="POST" action="../controllers/LeaveController.php" onsubmit="return confirm('Reject this request?');">
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
    </div> <!-- end card -->

    <div class="card">
        <h3 style="margin-top:24px;">Approved</h3>
    <?php if(empty($approved)): ?>
        <p>No approved requests.</p>
    <?php else: ?>
    <div class="table-container">
    <table border="1" width="100%">
        <tr><th>Employee</th><th>Email</th><th>Type</th><th>Dates</th><th>Days</th><th>Comments</th></tr>
        <?php foreach($approved as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['first_name'].' '.$r['last_name']); ?></td>
            <td><?= htmlspecialchars($r['email']); ?></td>
            <td><?= htmlspecialchars($r['leave_type_name']); ?></td>
            <td><?= $r['start_date'].' to '.$r['end_date']; ?></td>
            <td><?= intval($r['total_days']); ?></td>
            <td><?= htmlspecialchars($r['manager_comments'] ?? ''); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
    </div> <!-- end card -->

    <div class="card">
        <h3 style="margin-top:24px;">Rejected</h3>
    <?php if(empty($rejected)): ?>
        <p>No rejected requests.</p>
    <?php else: ?>
    <div class="table-container">
    <table border="1" width="100%">
        <tr><th>Employee</th><th>Email</th><th>Type</th><th>Dates</th><th>Days</th><th>Comments</th></tr>
        <?php foreach($rejected as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['first_name'].' '.$r['last_name']); ?></td>
            <td><?= htmlspecialchars($r['email']); ?></td>
            <td><?= htmlspecialchars($r['leave_type_name']); ?></td>
            <td><?= $r['start_date'].' to '.$r['end_date']; ?></td>
            <td><?= number_format($r['total_days'],3); ?></td>
            <td><?= htmlspecialchars($r['manager_comments'] ?? ''); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
    </div> <!-- end card -->
</div>

<script>
// intercept approve/reject forms and send via fetch
Array.from(document.querySelectorAll('form')).forEach(function(form){
    if(form.querySelector('button[name="action"][value="approve"]')) {
        form.addEventListener('submit', function(e){
            e.preventDefault();
            var data = new FormData(form);
            fetch(form.action, { method: 'POST', body: data })
                .then(res => res.text())
                .then(() => {
                    // simple strategy: reload row by removing it
                    var tr = form.closest('tr');
                    if (tr) tr.remove();
                });
            return false;
        });
    }
});
</script>
</body>
</html>​