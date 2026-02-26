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

// fetch leave types for dropdown
$typesStmt = $db->query("SELECT * FROM leave_types ORDER BY name");
$leaveTypes = $typesStmt->fetchAll(PDO::FETCH_ASSOC);

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
            var selectedId = typeElem.value;
            var info = document.getElementById('balance-info');
            var balanceMap = {
                <?php foreach ($leaveTypes as $lt): 
                    $col = strtolower(str_replace(' ', '_', $lt['name'])) . '_balance';
                    // fallback if column doesn't exist
                    echo $lt['id'] . ": " . (isset($balances[$col]) ? $balances[$col] : 0) . ",\n";
                endforeach; ?>
            };
            var val = balanceMap[selectedId] !== undefined ? balanceMap[selectedId] : 0;
            var name = typeElem.options[typeElem.selectedIndex].text;
            info.innerHTML = name + ' balance: ' + val + ' days';
        }

        window.addEventListener('load', function(){
            updateBalanceInfo();
            var select = document.getElementById('leave_type');
            if(select){
                select.addEventListener('change', function(){
                    updateBalanceInfo();
                    calculateDays();
                });
            }
        });

        function checkBalanceWarning(days) {
            var balanceInfo = document.getElementById('balance-info');
            var text = balanceInfo.textContent || balanceInfo.innerText;
            var parts = text.split(':');
            if (parts.length >= 2) {
                var bal = parseFloat(parts[1]);
                if (!isNaN(bal) && days > bal) {
                    balanceInfo.style.color = 'red';
                    balanceInfo.innerHTML += ' <span style="font-weight:bold;">(insufficient)</span>';
                } else {
                    balanceInfo.style.color = '';
                }
            }
        }

    </script>
</head>
<body>

<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="content">
    <div class="card" style="margin:60px auto;max-width:720px;">
        <h2 style="text-align:center;">Apply for Leave</h2>

        <form method="POST" action="../controllers/LeaveController.php" style="display:flex;flex-direction:column;align-items:center;gap:12px;">

            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">

            <div style="width:100%;max-width:420px;">
                <label>Leave Type</label>
                <select name="leave_type_id" id="leave_type" style="width:100%;padding:8px 10px;box-sizing:border-box;background:#1f1f1f;color:#fff;border-radius:6px;border:1px solid rgba(255,255,255,0.08);">
                    <?php foreach ($leaveTypes as $lt): ?>
                        <option value="<?= $lt['id']; ?>"><?= htmlspecialchars($lt['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="balance-info" style="margin-top:8px;font-weight:bold;">&nbsp;</div>
            </div>

            <div style="width:100%;max-width:420px;display:flex;gap:12px;">
                <div style="flex:1;">
                    <label>Start Date</label>
                    <input type="date" name="start_date" id="start_date" onchange="calculateDays()" required style="width:100%;padding:8px 10px;box-sizing:border-box;">
                </div>
                <div style="flex:1;">
                    <label>End Date</label>
                    <input type="date" name="end_date" id="end_date" onchange="calculateDays()" required style="width:100%;padding:8px 10px;box-sizing:border-box;">
                </div>
            </div>

            <div style="width:100%;max-width:420px;">
                <label>Total Days:</label>
                <input type="text" id="total_days" readonly style="width:120px;padding:8px 10px;box-sizing:border-box;">
            </div>

            <div style="width:100%;max-width:420px;">
                <label>Reason:</label>
                <textarea name="reason" required rows="5" style="width:100%;padding:8px 10px;box-sizing:border-box;min-height:120px;resize:vertical;"></textarea>
            </div>

            <div style="width:100%;max-width:420px;text-align:center;margin-top:6px;">
                <button type="submit" style="padding:12px 24px;font-size:16px;">Submit Leave</button>
            </div>

        </form>
    </div>
</div>

</body>
</html>