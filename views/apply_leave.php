<?php
if (session_status() === PHP_SESSION_NONE) session_start();
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
                    // Map leave type names to the correct balance columns
                    $typeName = strtolower($lt['name']);
                    $col = 'annual_balance'; // default
                    if ($typeName === 'sick') $col = 'sick_balance';
                    elseif ($typeName === 'force') $col = 'force_balance';
                    elseif ($typeName === 'vacational' || $typeName === 'vacation' || $typeName === 'annual') $col = 'annual_balance';
                    $value = isset($balances[$col]) ? $balances[$col] : 0;
                    echo $lt['id'] . ": " . $value . ",\n";
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
    <div class="card" style="margin:0 auto;max-width:620px;">
        <h2 style="text-align:center;margin-bottom:24px;">Apply for Leave</h2>

        <form method="POST" action="../controllers/LeaveController.php" style="display:flex;flex-direction:column;align-items:stretch;gap:0;">

            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">

            <div class="form-group">
                <label for="leave_type">Leave Type</label>
                <select name="leave_type_id" id="leave_type" style="width:100%;padding:10px 12px;box-sizing:border-box;background:#ffffff;color:#111827;border-radius:10px;border:1px solid var(--border);font-size:14px;">
                    <?php foreach ($leaveTypes as $lt): ?>
                        <option value="<?= $lt['id']; ?>"><?= htmlspecialchars($lt['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="balance-info" style="margin-top:8px;font-size:14px;color:#374151;">&nbsp;</div>
            </div>

            <div style="display:flex;gap:16px;margin-bottom:16px;">
                <div class="form-group" style="flex:1;margin-bottom:0;">
                    <label for="start_date">Start Date</label>
                    <input type="date" name="start_date" id="start_date" onchange="calculateDays()" required style="width:100%;padding:10px 12px;box-sizing:border-box;background:#ffffff;color:#111827;border-radius:10px;border:1px solid var(--border);">
                </div>
                <div class="form-group" style="flex:1;margin-bottom:0;">
                    <label for="end_date">End Date</label>
                    <input type="date" name="end_date" id="end_date" onchange="calculateDays()" required style="width:100%;padding:10px 12px;box-sizing:border-box;background:#ffffff;color:#111827;border-radius:10px;border:1px solid var(--border);">
                </div>
            </div>

            <div class="form-group">
                <label for="total_days">Total Days</label>
                <input type="text" id="total_days" readonly style="width:100%;padding:10px 12px;box-sizing:border-box;background:#f8fafc;color:#111827;border-radius:10px;border:1px solid var(--border);cursor:not-allowed;">
            </div>

            <div class="form-group">
                <label for="reason">Reason for Leave</label>
                <textarea name="reason" id="reason" required rows="5" style="width:100%;padding:10px 12px;box-sizing:border-box;background:#ffffff;color:#111827;border-radius:10px;border:1px solid var(--border);font-family:inherit;font-size:14px;resize:vertical;"></textarea>
            </div>

            <div style="text-align:center;margin-top:16px;">
                <button type="submit" style="padding:12px 32px;font-size:16px;">Submit Leave Request</button>
            </div>

        </form>
    </div>
</div>

</body>
</html>