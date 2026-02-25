<?php
// Run this script at the start of every month (via cron or manually)
// to update leave balances according to policy.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Leave.php';

$db = (new Database())->connect();
$leave = new Leave($db);

// before resetting, capture leftover force days to warn HR
$leftovers = $db->query("SELECT id, user_id, force_balance FROM employees WHERE force_balance > 0")->fetchAll(PDO::FETCH_ASSOC);
if (!empty($leftovers)) {
    echo "WARNING: the following employees had leftover force days which will now be reset to 5:\n";
    foreach ($leftovers as $row) {
        echo "employee_id=".$row['id'].' user_id='.$row['user_id'].' leftover='.$row['force_balance'].'\n';
    }
}

if ($leave->accrueMonthly()) {
    echo "Monthly accrual completed.\n";
} else {
    echo "Failed to perform accrual.\n";
}
?>
