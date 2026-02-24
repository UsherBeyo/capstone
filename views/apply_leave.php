<?php
session_start();
require_once '../config/database.php';

if ($_SESSION['role'] != 'employee') {
    die("Access denied");
}

$db = (new Database())->connect();
$emp_id = $_SESSION['emp_id'] ?? null;
$balances = ['annual_balance'=>0,'sick_balance'=>0,'force_balance'=>0];
if ($emp_id) {
    $stmt = $db->prepare("SELECT annual_balance, sick_balance, force_balance FROM employees WHERE id = ?");
    $stmt->execute([$emp_id]);
    $balances = $stmt->fetch(PDO::FETCH_ASSOC) ?: $balances;
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
    <script>
        // helper to show balance for chosen leave type
        function updateBalanceInfo() {
            var typeElem = document.getElementById('leave_type');
            if(!typeElem) return;
            var type = typeElem.value;
            var info = document.getElementById('balance-info');
            var bal = {
                'Annual': <?= json_encode($balances['annual_balance']); ?>,
                'Sick': <?= json_encode($balances['sick_balance']); ?>,
                'Force': <?= json_encode($balances['force_balance']); ?>
            };
            var val = (typeof bal[type] !== 'undefined') ? bal[type] : 0;
            info.innerHTML = type + ' balance: ' + val + ' days';
        }

        window.addEventListener('load', function(){
            updateBalanceInfo();
            var select = document.getElementById('leave_type');
            if(select){
                select.addEventListener('change', function(){
                    updateBalanceInfo();
                });
            }
        });
    </script>
</head>
<body>

<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="content">
    <div class="card">
        <h2>Apply for Leave</h2>


        <form method="POST" action="../controllers/LeaveController.php">

            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">

            <label>Leave Type</label>
            <select name="leave_type" id="leave_type">
                <option value="Annual">Annual</option>
                <option value="Sick">Sick</option>
                <option value="Force">Force</option>
            </select>

            <div id="balance-info" style="margin-top:8px;font-weight:bold;">
                Annual: <?= $balances['annual_balance']; ?> days<br>
                Sick: <?= $balances['sick_balance']; ?> days<br>
                Force: <?= $balances['force_balance']; ?> days
            </div>

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