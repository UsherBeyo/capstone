<?php
// sidebar.php - include this at the top of any view needing navigation
if (!isset($_SESSION)) session_start();
// prevent cached pages after logout
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
// require authentication
if (empty($_SESSION['user_id'])) {
    header('Location: ../views/login.php');
    exit();
}
$role = $_SESSION['role'] ?? '';
?>
<p class="role-badge"><?= strtoupper(htmlspecialchars($role)); ?></p>
<div class="sidebar">
    <h3>Leave System</h3>
    <a href="dashboard.php">Dashboard</a>
    <?php if(in_array($role,['employee','manager','hr'])): ?>
        <a href="calendar.php">Leave Calendar</a>
    <?php endif; ?>
    <?php if($role == 'employee'): ?>
        <a href="apply_leave.php">Apply Leave</a>
    <?php endif; ?>
    <?php if($role !== 'admin'): ?>
        <a href="employee_profile.php">My Profile</a>
    <?php endif; ?>
    <?php if($role == 'admin'): ?>
        <a href="manage_employees.php">Manage Employees</a>
    <?php endif; ?>
    <?php if(in_array($role,['admin','manager','hr'])): ?>
        <a href="holidays.php">Manage Holidays</a>
    <?php endif; ?>
    <?php if(in_array($role,['admin','hr'])): ?>
        <a href="reports.php">Reports</a>
    <?php endif; ?>
    <?php if($role == 'admin'): ?>
        <a href="manage_accruals.php">Manage Accruals</a>
        <a href="leave_requests.php">Leave Requests</a>
        <?php if(in_array(
            $_SESSION['role'], ['admin','hr']
        )): ?>
            <a href="manage_leave_types.php">Leave Types</a>
        <?php endif; ?>
    <?php endif; ?>
    <a href="../controllers/logout.php">Logout</a>
</div>