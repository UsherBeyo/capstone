<?php
session_start();
require_once '../config/database.php';

if ($_SESSION['role'] !== 'admin') {
    die("Access denied");
}
$db = (new Database())->connect();

$requests = $db->query("SELECT lr.*, e.first_name, e.last_name, u.email
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    JOIN users u ON e.user_id = u.id
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
    <table border="1" width="100%">
        <tr><th>Employee</th><th>Email</th><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Comments</th><th>Action</th></tr>
        <?php foreach($requests as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['first_name'].' '.$r['last_name']); ?></td>
            <td><?= htmlspecialchars($r['email']); ?></td>
            <td><?= htmlspecialchars($r['leave_type']); ?></td>
            <td><?= $r['start_date'].' to '.$r['end_date']; ?></td>
            <td><?= $r['total_days']; ?></td>
            <td><?= ucfirst($r['status']); ?></td>
            <td><?= htmlspecialchars($r['manager_comments'] ?? ''); ?></td>
            <td>
                <form method="POST" action="../controllers/LeaveController.php" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="leave_id" value="<?= $r['id']; ?>">
                    <select name="action">
                        <option value="approve">Approve</option>
                        <option value="reject">Reject</option>
                    </select>
                    <input type="text" name="comments" placeholder="Comments">
                    <button type="submit">Go</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
</body>
</html>