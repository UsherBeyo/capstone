<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = (new Database())->connect();
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div class="sidebar">
    <h3>Leave System</h3>
    <p><?= strtoupper($role); ?></p>

    <a href="dashboard.php">Dashboard</a><br><br>

    <?php if($role == 'employee'): ?>
        <a href="apply_leave.php">Apply Leave</a><br><br>
    <?php endif; ?>

    <?php if($role == 'admin'): ?>
        <a href="manage_employees.php">Manage Employees</a><br><br>
    <?php endif; ?>

    <a href="../controllers/logout.php">Logout</a>
</div>

<div class="content">
    <h2>Welcome <?= $_SESSION['email']; ?></h2>

    <?php if($role == 'employee'): ?>

        <?php
        $stmt = $db->prepare("SELECT leave_balance FROM employees WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $balance = $stmt->fetchColumn();
        ?>

        <div class="card">
            <h3>Leave Balance</h3>
            <p><?= $balance ?> Days</p>
        </div>

    <?php elseif($role == 'manager'): ?>

        <?php
        $stmt = $db->prepare("
            SELECT lr.*, e.first_name, e.last_name
            FROM leave_requests lr
            JOIN employees e ON lr.employee_id = e.id
            WHERE lr.status = 'pending'
        ");
        $stmt->execute();
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <div class="card">
            <h3>Pending Leave Requests</h3>

            <table border="1" width="100%">
                <tr>
                    <th>Employee</th>
                    <th>Dates</th>
                    <th>Days</th>
                    <th>Action</th>
                </tr>

                <?php foreach($requests as $r): ?>
                <tr>
                    <td><?= $r['first_name']." ".$r['last_name']; ?></td>
                    <td><?= $r['start_date']." to ".$r['end_date']; ?></td>
                    <td><?= $r['total_days']; ?></td>
                    <td>
                        <form method="POST" action="../controllers/LeaveController.php">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="leave_id" value="<?= $r['id']; ?>">
                            <button type="submit">Approve</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>

            </table>
        </div>

    <?php elseif($role == 'admin'): ?>

        <?php
        $count = $db->query("SELECT COUNT(*) FROM employees")->fetchColumn();
        ?>

        <div class="card">
            <h3>Total Employees</h3>
            <p><?= $count ?></p>
        </div>

    <?php endif; ?>

</div>

</body>
</html> 