<?php
session_start();
require_once '../config/database.php';

if (!in_array($_SESSION['role'], ['admin', 'manager', 'hr'])) {
    die("Access denied");
}

$db = (new Database())->connect();

// Get report type
$reportType = $_GET['type'] ?? 'summary';
$departmentFilter = $_GET['dept'] ?? '';

// Export to CSV if requested
if (isset($_GET['export']) && $_GET['export'] === '1') {
    $rows = [];
    $headers = [];

    if ($reportType === 'balance') {
        $query = "SELECT e.id, e.first_name, e.last_name, e.department, e.annual_balance, e.sick_balance, e.force_balance 
                  FROM employees e";
        if ($departmentFilter) {
            $query .= " WHERE e.department = '" . $db->quote($departmentFilter) . "'";
        }
        $query .= " ORDER BY e.department, e.first_name";
        
        $stmt = $db->query($query);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $headers = ['ID', 'First Name', 'Last Name', 'Department', 'Annual Balance', 'Sick Balance', 'Force Balance'];
    } elseif ($reportType === 'usage') {
        $query = "SELECT e.department, lr.leave_type, COUNT(*) as count, SUM(lr.total_days) as total_days 
                  FROM leave_requests lr 
                  JOIN employees e ON lr.employee_id = e.id 
                  WHERE lr.status = 'approved'";
        if ($departmentFilter) {
            $query .= " AND e.department = '" . $db->quote($departmentFilter) . "'";
        }
        $query .= " GROUP BY e.department, lr.leave_type ORDER BY e.department, lr.leave_type";
        
        $stmt = $db->query($query);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $headers = ['Department', 'Leave Type', 'Count', 'Total Days'];
    }

    if (!empty($rows)) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="leave_report_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, array_values($row));
        }
        exit();
    }
}

// Get departments for filter
$deptStmt = $db->query("SELECT DISTINCT department FROM employees ORDER BY department");
$departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);

// Get report data
if ($reportType === 'balance') {
    $query = "SELECT e.id, e.first_name, e.last_name, e.department, e.annual_balance, e.sick_balance, e.force_balance 
              FROM employees e";
    if ($departmentFilter) {
        $query .= " WHERE e.department = '" . $db->quote($departmentFilter) . "'";
    }
    $query .= " ORDER BY e.department, e.first_name";
    $stmt = $db->query($query);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $reportTitle = "Leave Balance Report";
} elseif ($reportType === 'usage') {
    $query = "SELECT e.department, lr.leave_type, COUNT(*) as count, SUM(lr.total_days) as total_days 
              FROM leave_requests lr 
              JOIN employees e ON lr.employee_id = e.id 
              WHERE lr.status = 'approved'";
    if ($departmentFilter) {
        $query .= " AND e.department = '" . $db->quote($departmentFilter) . "'";
    }
    $query .= " GROUP BY e.department, lr.leave_type ORDER BY e.department, lr.leave_type";
    $stmt = $db->query($query);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $reportTitle = "Leave Usage Report";
} else {
    // Summary report
    $totalEmployees = $db->query("SELECT COUNT(*) FROM employees")->fetchColumn();
    $totalPending = $db->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
    $totalApproved = $db->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'approved'")->fetchColumn();
    $avgAnnualBalance = $db->query("SELECT AVG(annual_balance) FROM employees")->fetchColumn();
    $reportTitle = "Leave System Summary";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Reports - Leave System</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="content">
    <h2><?= htmlspecialchars($reportTitle); ?></h2>

    <div class="card" style="margin-bottom:20px;">
        <h3>Report Filter</h3>
        <form method="GET" style="display:flex;gap:10px;align-items:center;">
            <div>
                <label>Report Type:</label>
                <select name="type">
                    <option value="summary" <?= ($reportType === 'summary' ? 'selected' : ''); ?>>Summary</option>
                    <option value="balance" <?= ($reportType === 'balance' ? 'selected' : ''); ?>>Leave Balance</option>
                    <option value="usage" <?= ($reportType === 'usage' ? 'selected' : ''); ?>>Leave Usage</option>
                </select>
            </div>
            <div>
                <label>Department:</label>
                <select name="dept">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= htmlspecialchars($d['department']); ?>" <?= ($departmentFilter === $d['department'] ? 'selected' : ''); ?>>
                            <?= htmlspecialchars($d['department']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">Apply Filter</button>
            <a href="?type=<?= $reportType; ?>&dept=<?= urlencode($departmentFilter); ?>&export=1" class="btn">Export CSV</a>
        </form>
    </div>

    <?php if ($reportType === 'summary'): ?>
        <div class="card">
            <h3>System Summary</h3>
            <table>
                <tr><th>Metric</th><th>Value</th></tr>
                <tr><td>Total Employees</td><td><?= $totalEmployees; ?></td></tr>
                <tr><td>Pending Requests</td><td><?= $totalPending; ?></td></tr>
                <tr><td>Approved Requests</td><td><?= $totalApproved; ?></td></tr>
                <tr><td>Average Annual Balance</td><td><?= round($avgAnnualBalance, 2); ?> days</td></tr>
            </table>
        </div>
    <?php elseif ($reportType === 'balance'): ?>
        <div class="card">
            <table>
                <tr>
                    <th>Name</th>
                    <th>Department</th>
                    <th>Annual Balance</th>
                    <th>Sick Balance</th>
                    <th>Force Balance</th>
                </tr>
                <?php foreach ($reportData as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                    <td><?= htmlspecialchars($row['department']); ?></td>
                    <td><?= round($row['annual_balance'], 2); ?></td>
                    <td><?= round($row['sick_balance'], 2); ?></td>
                    <td><?= $row['force_balance']; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php elseif ($reportType === 'usage'): ?>
        <div class="card">
            <table>
                <tr>
                    <th>Department</th>
                    <th>Leave Type</th>
                    <th>Request Count</th>
                    <th>Total Days</th>
                </tr>
                <?php foreach ($reportData as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['department']); ?></td>
                    <td><?= htmlspecialchars($row['leave_type']); ?></td>
                    <td><?= $row['count']; ?></td>
                    <td><?= round($row['total_days'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

</div>

</body>
</html>
