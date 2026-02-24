<?php
// sidebar.php - include this at the top of any view needing navigation
if (!isset($_SESSION)) session_start();
$role = $_SESSION['role'] ?? '';
?>
<div class="sidebar">
    <h3>Leave System</h3>
    <p><?= strtoupper(htmlspecialchars($role)); ?></p>
    <a href="dashboard.php">Dashboard</a><br><br>
    <a href="change_password.php">Change Password</a><br><br>
    <?php if(in_array($role,['employee','manager','hr','admin'])): ?>
        <a href="calendar.php">Leave Calendar</a><br><br>
    <?php endif; ?>
    <?php if($role == 'employee'): ?>
        <a href="apply_leave.php">Apply Leave</a><br><br>
    <?php endif; ?>
    <?php if($role == 'admin'): ?>
        <a href="manage_employees.php">Manage Employees</a><br><br>
    <?php endif; ?>
    <?php if(in_array($role,['admin','manager','hr'])): ?>
        <a href="holidays.php">Manage Holidays</a><br><br>
    <?php endif; ?>
    <?php if($role == 'admin'): ?>
        <a href="leave_requests.php">Leave Requests</a><br><br>
    <?php endif; ?>
    <a href="../controllers/logout.php">Logout</a>
</div>