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

// Export handling (csv/excel/pdf)
if (isset($_GET['export']) && $_GET['export'] === '1') {
    $format = $_GET['format'] ?? 'csv';
    $rows = [];
    $headers = [];

    if ($reportType === 'balance') {
        $query = "SELECT e.id, e.first_name, e.last_name, e.department, e.annual_balance, e.sick_balance, e.force_balance 
                  FROM employees e";
        if ($departmentFilter) {
            $stmt = $db->prepare("SELECT e.id, e.first_name, e.last_name, e.department, e.annual_balance, e.sick_balance, e.force_balance FROM employees e WHERE e.department = ? ORDER BY e.first_name");
            $stmt->execute([$departmentFilter]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $query .= " ORDER BY e.department, e.first_name";
            $rows = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
        }
        $headers = ['ID', 'First Name', 'Last Name', 'Department', 'Annual Balance', 'Sick Balance', 'Force Balance'];
    } elseif ($reportType === 'usage') {
        $query = "SELECT e.department, COALESCE(lt.name, lr.leave_type) as leave_type, COUNT(*) as request_count, SUM(lr.total_days) as total_days 
                  FROM leave_requests lr 
                  JOIN employees e ON lr.employee_id = e.id 
                  LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id 
                  WHERE lr.status = 'approved'";
        if ($departmentFilter) {
            $stmt = $db->prepare("SELECT e.department, COALESCE(lt.name, lr.leave_type) as leave_type, COUNT(*) as request_count, SUM(lr.total_days) as total_days FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id WHERE lr.status = 'approved' AND e.department = ? GROUP BY e.department, leave_type ORDER BY e.department, leave_type");
            $stmt->execute([$departmentFilter]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $query .= " GROUP BY e.department, leave_type ORDER BY e.department, leave_type";
            $rows = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
        }
        $headers = ['Department', 'Leave Type', 'Request Count', 'Total Days'];
    }

    // deliver according to requested format
    if ($format === 'excel' && class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        // basic PhpSpreadsheet export
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $col = 1;
        foreach ($headers as $h) {
            $sheet->setCellValueByColumnAndRow($col++, 1, $h);
        }
        $rownum = 2;
        foreach ($rows as $row) {
            $col = 1;
            foreach ($row as $val) {
                $sheet->setCellValueByColumnAndRow($col++, $rownum, $val);
            }
            $rownum++;
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="leave_report_' . date('Y-m-d') . '.xlsx"');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit();
    } elseif ($format === 'pdf' && class_exists('TCPDF')) {
        // simple TCPDF usage - requires library included separately
        $pdf = new TCPDF();
        $pdf->AddPage();
        $html = '<h2>' . htmlspecialchars($reportTitle) . '</h2><table border="1" cellpadding="4">';
        $html .= '<tr>';
        foreach ($headers as $h) {
            $html .= '<th>' . htmlspecialchars($h) . '</th>';
        }
        $html .= '</tr>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $val) {
                $html .= '<td>' . htmlspecialchars($val) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
        $pdf->writeHTML($html);
        $pdf->Output('leave_report_' . date('Y-m-d') . '.pdf', 'D');
        exit();
    } else {
        // default to csv
        header('Content-Type: text/csv; charset=utf-8');
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
        $query .= " WHERE e.department = ?";
        $stmt = $db->prepare($query . " ORDER BY e.department, e.first_name");
        $stmt->execute([$departmentFilter]);
    } else {
        $query .= " ORDER BY e.department, e.first_name";
        $stmt = $db->prepare($query);
        $stmt->execute();
    }
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $reportTitle = "Leave Balance Report";
} elseif ($reportType === 'usage') {
    $query = "SELECT e.department, COALESCE(lt.name, lr.leave_type) as leave_type, COUNT(*) as count, SUM(lr.total_days) as total_days 
              FROM leave_requests lr 
              JOIN employees e ON lr.employee_id = e.id 
              LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id 
              WHERE lr.status = 'approved'";
    if ($departmentFilter) {
        $query .= " AND e.department = ?";
        $stmt = $db->prepare($query . " GROUP BY e.department, leave_type ORDER BY e.department, leave_type");
        $stmt->execute([$departmentFilter]);
    } else {
        $query .= " GROUP BY e.department, leave_type ORDER BY e.department, leave_type";
        $stmt = $db->prepare($query);
        $stmt->execute();
    }
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
            <a href="?type=<?= $reportType; ?>&dept=<?= urlencode($departmentFilter); ?>&export=1" class="btn-export">Export CSV</a>
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
