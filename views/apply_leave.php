<?php
session_start();
require_once '../config/database.php';

if ($_SESSION['role'] != 'employee') {
    die("Access denied");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Apply Leave</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <script src="../assets/js/script.js"></script>
</head>
<body>

<div class="sidebar">
    <a href="dashboard.php">Back to Dashboard</a>
</div>

<div class="content">
    <div class="card">
        <h2>Apply for Leave</h2>

        <form method="POST" action="../controllers/LeaveController.php">

            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">

            <label>Leave Type</label>
            <select name="leave_type">
                <option value="Annual">Annual</option>
                <option value="Sick">Sick</option>
                <option value="Emergency">Emergency</option>
            </select>

            <label>Start Date</label>
            <input type="date" name="start_date" id="start_date" onchange="calculateDays()" required>

            <label>End Date</label>
            <input type="date" name="end_date" id="end_date" onchange="calculateDays()" required>

            <label>Total Days</label>
            <input type="text" id="total_days" readonly>

            <label>Reason</label>
            <textarea name="reason" required></textarea>

            <br><br>
            <button type="submit">Submit Leave</button>
        </form>
    </div>
</div>

</body>
</html>