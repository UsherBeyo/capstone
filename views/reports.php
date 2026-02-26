<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/database.php';

if (!in_array($_SESSION['role'], ['admin', 'manager', 'hr'])) {
    // allow employees to run leave_card for themselves
    if (!(
        $_SESSION['role'] === 'employee' &&
        ($_GET['type'] ?? '') === 'leave_card' &&
        intval($_GET['employee_id'] ?? 0) === ($_SESSION['emp_id'] ?? 0)
    )) {
        die("Access denied");
    }
}

$db = (new Database())->connect();

// if employee browsing, fetch their own record
$currentEmp = null;
if (!empty($_SESSION['emp_id'])) {
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE id = ?");
    $stmt->execute([$_SESSION['emp_id']]);
    $currentEmp = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get report type
$reportType = $_GET['type'] ?? 'summary';
$departmentFilter = $_GET['dept'] ?? '';
$employeeFilter = intval($_GET['employee_id'] ?? 0);
// if an employee is browsing and requested leave_card, default to self
if ($_SESSION['role'] === 'employee' && $reportType === 'leave_card' && !$employeeFilter) {
    $employeeFilter = $_SESSION['emp_id'] ?? 0;
}

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
    } elseif ($reportType === 'leave_card' && $employeeFilter) {
        // fetch monthly accruals and undertime for a specific employee
        $empId = $employeeFilter;
        // build set of months from accruals and undertime logs
        $months = [];
        $stmt = $db->prepare("SELECT DISTINCT month_reference FROM accrual_history WHERE employee_id = ? ORDER BY month_reference");
        $stmt->execute([$empId]);
        foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $m) {
            $months[$m] = true;
        }
        $stmt = $db->prepare("SELECT DISTINCT DATE_FORMAT(created_at,'%Y-%m') FROM leave_balance_logs WHERE employee_id = ?");
        $stmt->execute([$empId]);
        foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $m) {
            $months[$m] = true;
        }
        ksort($months);
        $rows = [];
        foreach(array_keys($months) as $m) {
            // earned is accrual amount (annual & sick)
            $stmt = $db->prepare("SELECT SUM(amount) FROM accrual_history WHERE employee_id=? AND month_reference=?");
            $stmt->execute([$empId, $m]);
            $earned = floatval($stmt->fetchColumn() ?: 0);
            // undertime paid
            $stmt = $db->prepare("SELECT SUM(ABS(change_amount)) FROM leave_balance_logs WHERE employee_id=? AND reason='undertime_paid' AND DATE_FORMAT(created_at,'%Y-%m')=?");
            $stmt->execute([$empId, $m]);
            $utPaid = floatval($stmt->fetchColumn() ?: 0);
            // undertime unpaid
            $stmt = $db->prepare("SELECT SUM(ABS(change_amount)) FROM leave_balance_logs WHERE employee_id=? AND reason='undertime_unpaid' AND DATE_FORMAT(created_at,'%Y-%m')=?");
            $stmt->execute([$empId, $m]);
            $utUnpaid = floatval($stmt->fetchColumn() ?: 0);
            // calculate end-of-month balance for annual and sick
            $firstDayNext = date('Y-m-d', strtotime($m . '-01 +1 month'));
            $balStmt = $db->prepare("SELECT new_balance FROM budget_history WHERE employee_id=? AND leave_type='Annual' AND created_at < ? ORDER BY created_at DESC LIMIT 1");
            $balStmt->execute([$empId, $firstDayNext]);
            $vacBal = floatval($balStmt->fetchColumn() ?: 0);
            $balStmt2 = $db->prepare("SELECT new_balance FROM budget_history WHERE employee_id=? AND leave_type='Sick' AND created_at < ? ORDER BY created_at DESC LIMIT 1");
            $balStmt2->execute([$empId, $firstDayNext]);
            $sickBal = floatval($balStmt2->fetchColumn() ?: 0);
            $rows[] = [
                'month' => $m,
                'earned' => $earned,
                'ut_paid' => $utPaid,
                'ut_unpaid' => $utUnpaid,
                'vac_bal' => $vacBal,
                'sick_bal' => $sickBal
            ];
        }
        // headers for leave card
        $headers = ['Period','Particulars','Vac Earned','Vac Undertime Paid','Vac Balance','Vac Undertime Unpaid','Sick Earned','Sick Undertime Paid','Sick Balance','Sick Undertime Unpaid','Remarks'];
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
            if ($reportType === 'leave_card') {
                // row contains month,earned,ut_paid,ut_unpaid,vac_bal,sick_bal
                $sheet->setCellValueByColumnAndRow($col++, $rownum, $row['month']);
                $sheet->setCellValueByColumnAndRow($col++, $rownum, '');
                $sheet->setCellValueByColumnAndRow($col++, $rownum, $row['earned']);
                $sheet->setCellValueByColumnAndRow($col++, $rownum, $row['ut_paid']);
                $sheet->setCellValueByColumnAndRow($col++, $rownum, $row['vac_bal']);
                $sheet->setCellValueByColumnAndRow($col++, $rownum, $row['ut_unpaid']);
                // duplicate earned for sick
                $sheet->setCellValueByColumnAndRow($col++, $rownum, $row['earned']);
                $sheet->setCellValueByColumnAndRow($col++, $rownum, $row['ut_paid']);
                $sheet->setCellValueByColumnAndRow($col++, $rownum, $row['sick_bal']);
                $sheet->setCellValueByColumnAndRow($col++, $rownum, $row['ut_unpaid']);
                $sheet->setCellValueByColumnAndRow($col++, $rownum, '');
            } else {
                foreach ($row as $val) {
                    $sheet->setCellValueByColumnAndRow($col++, $rownum, $val);
                }
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
            if ($reportType === 'leave_card') {
                fputcsv($out, [
                    $row['month'],
                    '',
                    $row['earned'],
                    $row['ut_paid'],
                    '',
                    $row['ut_unpaid'],
                    $row['earned'],
                    $row['ut_paid'],
                    '',
                    $row['ut_unpaid'],
                    ''
                ]);
            } else {
                fputcsv($out, array_values($row));
            }
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
} elseif ($reportType === 'leave_card' && $employeeFilter) {
    $empId = $employeeFilter;
    // similar logic as export section
    $months = [];
    $stmt = $db->prepare("SELECT DISTINCT month_reference FROM accrual_history WHERE employee_id = ? ORDER BY month_reference");
    $stmt->execute([$empId]);
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $months[$m] = true;
    }
    $stmt = $db->prepare("SELECT DISTINCT DATE_FORMAT(created_at,'%Y-%m') FROM leave_balance_logs WHERE employee_id = ?");
    $stmt->execute([$empId]);
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $months[$m] = true;
    }
    ksort($months);
    $reportData = [];
    foreach(array_keys($months) as $m) {
        $stmt = $db->prepare("SELECT SUM(amount) FROM accrual_history WHERE employee_id=? AND month_reference=?");
        $stmt->execute([$empId, $m]);
        $earned = floatval($stmt->fetchColumn() ?: 0);
        $stmt = $db->prepare("SELECT SUM(ABS(change_amount)) FROM leave_balance_logs WHERE employee_id=? AND reason='undertime_paid' AND DATE_FORMAT(created_at,'%Y-%m')=?");
        $stmt->execute([$empId, $m]);
        $utPaid = floatval($stmt->fetchColumn() ?: 0);
        $stmt = $db->prepare("SELECT SUM(ABS(change_amount)) FROM leave_balance_logs WHERE employee_id=? AND reason='undertime_unpaid' AND DATE_FORMAT(created_at,'%Y-%m')=?");
        $stmt->execute([$empId, $m]);
        $utUnpaid = floatval($stmt->fetchColumn() ?: 0);
        // calculate end-of-month balances too
        $firstDayNext = date('Y-m-d', strtotime($m . '-01 +1 month'));
        $balStmt = $db->prepare("SELECT new_balance FROM budget_history WHERE employee_id=? AND leave_type='Annual' AND created_at < ? ORDER BY created_at DESC LIMIT 1");
        $balStmt->execute([$empId, $firstDayNext]);
        $vacBal = floatval($balStmt->fetchColumn() ?: 0);
        $balStmt2 = $db->prepare("SELECT new_balance FROM budget_history where employee_id=? AND leave_type='Sick' AND created_at < ? ORDER BY created_at DESC LIMIT 1");
        $balStmt2->execute([$empId, $firstDayNext]);
        $sickBal = floatval($balStmt2->fetchColumn() ?: 0);
        $reportData[] = ['month'=>$m, 'earned'=>$earned, 'ut_paid'=>$utPaid, 'ut_unpaid'=>$utUnpaid, 'vac_bal'=>$vacBal, 'sick_bal'=>$sickBal];
    }
    $reportTitle = "Leave Card";
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
                    <option value="leave_card" <?= ($reportType === 'leave_card' ? 'selected' : ''); ?>>Leave Card</option>
                </select>
            </div>
            <?php if($reportType !== 'leave_card'): ?>
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
            <?php else: ?>
            <div>
                <label>Employee:</label>
                <?php if($_SESSION['role'] === 'employee'): ?>
                    <input type="hidden" name="employee_id" value="<?= ($_SESSION['emp_id'] ?? ''); ?>">
                    <span><?= htmlspecialchars((isset($empRecord) ? ($empRecord['first_name'].' '.$empRecord['last_name']) : '')); ?></span>
                <?php else: ?>
                    <select name="employee_id">
                        <option value="">-- select --</option>
                        <?php
                        $empStmt = $db->query("SELECT id, first_name, last_name FROM employees ORDER BY first_name");
                        foreach($empStmt->fetchAll(PDO::FETCH_ASSOC) as $empRow):
                        ?>
                            <option value="<?= $empRow['id']; ?>" <?= ($employeeFilter == $empRow['id'] ? 'selected' : ''); ?>>
                                <?= htmlspecialchars($empRow['first_name'].' '.$empRow['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <button type="submit">Apply Filter</button>
            <a href="?type=<?= $reportType; ?>&dept=<?= urlencode($departmentFilter); ?><?php if($reportType==='leave_card' && $employeeFilter) echo '&employee_id='.$employeeFilter; ?>&export=1" class="btn-export">Export CSV</a>
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
    <?php elseif ($reportType === 'leave_card' && $employeeFilter): ?>
        <div class="card">
            <h3>Leave Card for <?= htmlspecialchars($currentEmp['first_name'].' '.$currentEmp['last_name']); ?></h3>
            <table>
                <tr>
                    <th>Period</th><th>Particulars</th><th>Vac Earned</th><th>Vac Undertime Paid</th><th>Vac Balance</th><th>Vac Undertime Unpaid</th><th>Sick Earned</th><th>Sick Undertime Paid</th><th>Sick Balance</th><th>Sick Undertime Unpaid</th><th>Remarks</th>
                </tr>
                <?php foreach($reportData as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['month']); ?></td>
                    <td></td>
                    <td><?= $row['earned']; ?></td>
                    <td><?= $row['ut_paid']; ?></td>
                    <td><?= round($row['vac_bal'],2); ?></td>
                    <td><?= $row['ut_unpaid']; ?></td>
                    <td><?= $row['earned']; ?></td>
                    <td><?= $row['ut_paid']; ?></td>
                    <td><?= round($row['sick_bal'],2); ?></td>
                    <td><?= $row['ut_unpaid']; ?></td>
                    <td></td>
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
