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
        $headers = ['ID', 'First Name', 'Last Name', 'Department', 'Vacational Balance', 'Sick Balance', 'Force Balance'];
    } elseif ($reportType === 'leave_card' && $employeeFilter) {
        // Fetch COMPLETE TRANSACTION HISTORY - every single record chronologically
        $empId = $employeeFilter;
        
        /* previous transaction assembly removed
        $transactions = []; 
        
        // 1. Get all accrual records (earned balances)
        $accrualStmt = $db->prepare("
            SELECT 'accrual' as type, date_accrued as trans_date, amount, NULL as leave_type_name, NULL as status, NULL as days_deducted
            FROM accrual_history 
            WHERE employee_id = ? 
            ORDER BY date_accrued ASC
        ");
        $accrualStmt->execute([$empId]);
        foreach($accrualStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $transactions[] = $row;
        }
        
        // 2. Get all approved leave requests (deductions)
        $leaveStmt = $db->prepare("
            SELECT 'leave' as type, start_date as trans_date, NULL as amount, COALESCE(lt.name, lr.leave_type) as leave_type_name, lr.status, lr.total_days as days_deducted
            FROM leave_requests lr
            LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
            WHERE lr.employee_id = ? 
            ORDER BY lr.start_date ASC
        ");
        $leaveStmt->execute([$empId]);
        foreach($leaveStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $transactions[] = $row;
        }
        
        // 3. Get all undertime records (paid/unpaid deductions)
        $undertimeStmt = $db->prepare("
            SELECT 'undertime' as type, created_at as trans_date, change_amount as amount, reason as leave_type_name, NULL as status, ABS(change_amount) as days_deducted
            FROM leave_balance_logs
            WHERE employee_id = ? AND (reason = 'undertime_paid' OR reason = 'undertime_unpaid')
            ORDER BY created_at ASC
        ");
        $undertimeStmt->execute([$empId]);
        foreach($undertimeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $transactions[] = $row;
        }
        
        // 4. Get all budget history (comprehensive tracking)
        $budgetStmt = $db->prepare("
            SELECT 'balance_change' as type, created_at as trans_date, NULL as amount, leave_type as leave_type_name, action as status, 
                   CASE WHEN action = 'deduction' THEN (old_balance - new_balance) ELSE (new_balance - old_balance) END as days_deducted,
                   old_balance, new_balance
            FROM budget_history
            WHERE employee_id = ?
            ORDER BY created_at ASC
        ");
        $budgetStmt->execute([$empId]);
        foreach($budgetStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $transactions[] = $row;
        }
        
        // Sort all transactions by date
        usort($transactions, function($a, $b) {
            return strtotime($a['trans_date']) - strtotime($b['trans_date']);
        });
        
        // Get current balances for reference (not used in running calculation but may be helpful later)
        $empStmt = $db->prepare("SELECT annual_balance, sick_balance, force_balance FROM employees WHERE id = ?");
        $empStmt->execute([$empId]);
        $currentBalances = $empStmt->fetch(PDO::FETCH_ASSOC);
        
        // determine starting balance from budget history before first transaction
        $vacBalance = 0;
        $sickBalance = 0;
        if (!empty($transactions)) {
            $firstDate = substr($transactions[0]['trans_date'], 0, 10);
            $balStmt = $db->prepare("SELECT new_balance FROM budget_history WHERE employee_id=? AND leave_type IN ('Annual','Vacational','Vacation') AND created_at < ? ORDER BY created_at DESC LIMIT 1");
            $balStmt->execute([$empId, $firstDate]);
            $vacBalance = floatval($balStmt->fetchColumn() ?: 0);
            $balStmt2 = $db->prepare("SELECT new_balance FROM budget_history where employee_id=? AND leave_type='Sick' AND created_at < ? ORDER BY created_at DESC LIMIT 1");
            $balStmt2->execute([$empId, $firstDate]);
            $sickBalance = floatval($balStmt2->fetchColumn() ?: 0);
        }
        
        // Build rows with running balance calculation
        $rows = [];
        
        foreach($transactions as $trans) {
            $particulars = '';
            $vacEarned = 0;
            $sickEarned = 0;
            $vacDeducted = 0;
            $sickDeducted = 0;
            $status = '';
            
            if ($trans['type'] === 'accrual') {
                // Both types earn the same amount
                $vacEarned = floatval($trans['amount']);
                $sickEarned = floatval($trans['amount']);
                $particulars = 'Monthly Earned (Accrual)';
                $vacBalance += $vacEarned;
                $sickBalance += $sickEarned;
                
            } elseif ($trans['type'] === 'leave') {
                $status = ucfirst($trans['status']);
                $particulars = $trans['leave_type_name'] . ' Leave';
                
                if ($trans['status'] === 'approved') {
                    $days = floatval($trans['days_deducted']);
                    if (strtolower($trans['leave_type_name']) === 'sick') {
                        $sickDeducted = $days;
                        $sickBalance -= $days;
                    } else {
                        $vacDeducted = $days;
                        $vacBalance -= $days;
                    }
                } elseif ($trans['status'] === 'rejected') {
                    $particulars .= ' (Rejected)';
                }
                
            } elseif ($trans['type'] === 'undertime') {
                $particularType = ($trans['leave_type_name'] === 'undertime_paid') ? 'Undertime (Paid)' : 'Absence w/o Pay (Unpaid)';
                $days = floatval($trans['days_deducted']);
                $vacDeducted = $days;
                $vacBalance -= $days;
                $particulars = $particularType;
                $status = 'Processed';
                
            } elseif ($trans['type'] === 'balance_change') {
                // Skip if already covered by other transactions
                continue;
            }
            
            $rows[] = [
                'date' => substr($trans['trans_date'], 0, 10),
                'particulars' => $particulars,
                'vac_earned' => $vacEarned,
                'vac_deducted' => $vacDeducted,
                'vac_balance' => $vacBalance,
                'sick_earned' => $sickEarned,
                'sick_deducted' => $sickDeducted,
                'sick_balance' => $sickBalance,
                'status' => $status
            ];
        }
        
        */
        // combine leave request snapshots and budget history to ensure correct balances
        $merged = [];
        // leave request entries; balance determined via budget_history lookup
        $leaveStmt = $db->prepare(
            "SELECT lr.created_at, COALESCE(lt.name, lr.leave_type) AS leave_type, lr.status, lr.total_days
             FROM leave_requests lr
             LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
             WHERE lr.employee_id = ?
             ORDER BY lr.created_at ASC"
        );
        $leaveStmt->execute([$empId]);
        foreach ($leaveStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $vacDed = 0;
            $sickDed = 0;
            if (strtolower($r['leave_type']) !== 'sick' && $r['status'] === 'approved') {
                $vacDed = floatval($r['total_days']);
            } elseif (strtolower($r['leave_type']) === 'sick' && $r['status'] === 'approved') {
                $sickDed = floatval($r['total_days']);
            }
            // find next budget entry after this leave for each type
            $vacBal = '';
            $sickBal = '';
            $balStmt = $db->prepare("SELECT new_balance FROM budget_history WHERE employee_id=? AND leave_type IN ('Annual','Vacational','Vacation') AND created_at >= ? ORDER BY created_at ASC LIMIT 1");
            $balStmt->execute([$empId, $r['created_at']]);
            $vacBal = floatval($balStmt->fetchColumn() ?: 0);
            $balStmt2 = $db->prepare("SELECT new_balance FROM budget_history WHERE employee_id=? AND leave_type='Sick' AND created_at >= ? ORDER BY created_at ASC LIMIT 1");
            $balStmt2->execute([$empId, $r['created_at']]);
            $sickBal = floatval($balStmt2->fetchColumn() ?: 0);

            $merged[] = [
                'date' => substr($r['created_at'], 0, 10),
                'particulars' => $r['leave_type'] . ' Leave',
                'vac_earned' => 0,
                'vac_deducted' => $vacDed,
                'vac_balance' => $vacBal,
                'sick_earned' => 0,
                'sick_deducted' => $sickDed,
                'sick_balance' => $sickBal,
                'status' => ucfirst($r['status'])
            ];
        }
        // budget history entries
        $budgetStmt = $db->prepare(
            "SELECT created_at, leave_type, action, old_balance, new_balance
             FROM budget_history
             WHERE employee_id = ?
             ORDER BY created_at ASC"
        );
        $budgetStmt->execute([$empId]);
        foreach ($budgetStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $vacDed = 0;
            $sickDed = 0;
            $vacEarn = 0;
            $sickEarn = 0;

            $part = ucfirst($r['action']) . ' ' . $r['leave_type'];
            if (in_array(strtolower($r['leave_type']), ['annual','vacational','vacation'])) {
                $vacEarn = max(0, floatval($r['new_balance']) - floatval($r['old_balance']));
                $vacDed  = max(0, floatval($r['old_balance']) - floatval($r['new_balance']));
                $vacBal  = floatval($r['new_balance']);
                $sickBal = '';
            } elseif (strtolower($r['leave_type']) === 'sick') {
                $sickEarn = max(0, floatval($r['new_balance']) - floatval($r['old_balance']));
                $sickDed  = max(0, floatval($r['old_balance']) - floatval($r['new_balance']));
                $sickBal  = floatval($r['new_balance']);
                $vacBal   = '';
            } else {
                $vacBal = '';
                $sickBal = '';
            }

            $merged[] = [
                'date' => substr($r['created_at'], 0, 10),
                'particulars' => $part,
                'vac_earned' => $vacEarn,
                'vac_deducted' => $vacDed,
                'vac_balance' => isset($vacBal) ? $vacBal : '',
                'sick_earned' => $sickEarn,
                'sick_deducted' => $sickDed,
                'sick_balance' => isset($sickBal) ? $sickBal : '',
                'status' => ucfirst($r['action'])
            ];
        }
        usort($merged, function($a,$b){return strtotime($a['date'])-strtotime($b['date']);});
        $rows = $merged;

        // headers for complete leave card
        $headers = ['Date','Particulars','Vac Earned','Vac Deducted','Vac Balance','Sick Earned','Sick Deducted','Sick Balance','Status'];
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
        
        if ($reportType === 'leave_card') {
            // Single sheet with ALL transaction history
            $sheet->setTitle('Leave Card History');
            $col = 1;
            
            // Add title and employee info at top
            $sheet->setCellValueByColumnAndRow(1, 1, 'LEAVE CARD - COMPLETE TRANSACTION HISTORY');
            $sheet->mergeCells('A1:I1');
            $sheet->getStyle('A1')->getFont()->setBold(true);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
            
            if (!empty($currentEmp)) {
                $sheet->setCellValueByColumnAndRow(1, 2, 'Employee: ' . htmlspecialchars($currentEmp['first_name'] . ' ' . $currentEmp['last_name']));
                $sheet->mergeCells('A2:I2');
            }
            
            // Headers in row 4
            $headerRow = 4;
            $headerCols = ['A' => 'Date', 'B' => 'Particulars', 'C' => 'Vac Earned', 'D' => 'Vac Deducted', 'E' => 'Vac Balance', 
                          'F' => 'Sick Earned', 'G' => 'Sick Deducted', 'H' => 'Sick Balance', 'I' => 'Status'];
            
            foreach ($headerCols as $colLetter => $headerText) {
                $sheet->setCellValue($colLetter . $headerRow, $headerText);
                $sheet->getStyle($colLetter . $headerRow)->getFont()->setBold(true);
                $sheet->getStyle($colLetter . $headerRow)->getFill()->setFillType('solid')->getStartColor()->setRGB('D3D3D3');
            }
            
            // Data rows starting from row 5
            $rownum = 5;
            foreach ($rows as $row) {
                $sheet->setCellValueByColumnAndRow(1, $rownum, $row['date']);
                $sheet->setCellValueByColumnAndRow(2, $rownum, $row['particulars']);
                $sheet->setCellValueByColumnAndRow(3, $rownum, $row['vac_earned'] != 0 ? number_format($row['vac_earned'], 3) : '');
                $sheet->setCellValueByColumnAndRow(4, $rownum, $row['vac_deducted'] != 0 ? number_format($row['vac_deducted'], 3) : '');
                $sheet->setCellValueByColumnAndRow(5, $rownum, ($row['vac_balance'] !== '' && $row['vac_balance'] != 0) ? number_format($row['vac_balance'], 3) : '');
                $sheet->setCellValueByColumnAndRow(6, $rownum, $row['sick_earned'] != 0 ? number_format($row['sick_earned'], 3) : '');
                $sheet->setCellValueByColumnAndRow(7, $rownum, $row['sick_deducted'] != 0 ? number_format($row['sick_deducted'], 3) : '');
                $sheet->setCellValueByColumnAndRow(8, $rownum, ($row['sick_balance'] !== '' && $row['sick_balance'] != 0) ? number_format($row['sick_balance'], 3) : '');
                $sheet->setCellValueByColumnAndRow(9, $rownum, $row['status']);
                $rownum++;
            }
            
            // Auto-fit columns
            foreach (range('A', 'I') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        } else {
            // For other report types, use regular export
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
        }
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="leave_card_' . date('Y-m-d') . '.xlsx"');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit();
    } elseif ($format === 'pdf' && class_exists('TCPDF')) {
        // simple TCPDF usage - requires library included separately
        $pdf = new TCPDF();
        $pdf->AddPage();
        $html = '<h2>' . htmlspecialchars($reportTitle) . '</h2>';
        
        if ($reportType === 'leave_card' && !empty($currentEmp)) {
            $html .= '<p><strong>Employee:</strong> ' . htmlspecialchars($currentEmp['first_name'] . ' ' . $currentEmp['last_name']) . '</p>';
        }
        
        $html .= '<table border="1" cellpadding="4">';
        $html .= '<tr>';
        foreach ($headers as $h) {
            $html .= '<th>' . htmlspecialchars($h) . '</th>';
        }
        $html .= '</tr>';
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            
            $html .= '<tr>';
            if ($reportType === 'leave_card') {
                $html .= '<td>' . htmlspecialchars($row['date'] ?? '') . '</td>';
                $html .= '<td>' . htmlspecialchars($row['particulars'] ?? '') . '</td>';
                $html .= '<td>' . ((($row['vac_earned'] ?? 0) != 0) ? number_format($row['vac_earned'], 3) : '') . '</td>';
                $html .= '<td>' . number_format($row['vac_deducted'] ?? 0, 3) . '</td>';
                $vb = $row['vac_balance'] ?? '';
                $html .= '<td>' . ($vb === '' ? '' : number_format($vb,3)) . '</td>';
                $html .= '<td>' . number_format($row['sick_earned'] ?? 0, 3) . '</td>';
                $html .= '<td>' . number_format($row['sick_deducted'] ?? 0, 3) . '</td>';
                $sb = $row['sick_balance'] ?? '';
                $html .= '<td>' . ($sb === '' ? '' : number_format($sb,3)) . '</td>';
                $html .= '<td>' . htmlspecialchars($row['status'] ?? '') . '</td>';
            } else {
                foreach ($row as $val) {
                    $html .= '<td>' . htmlspecialchars($val) . '</td>';
                }
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
        $pdf->writeHTML($html);
        $pdf->Output('leave_card_' . date('Y-m-d') . '.pdf', 'D');
        exit();
    } else {
        // default to csv
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="leave_card_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            
            if ($reportType === 'leave_card') {
                $vb = $row['vac_balance'] ?? '';
                $sb = $row['sick_balance'] ?? '';
                fputcsv($out, [
                    $row['date'] ?? '',
                    $row['particulars'] ?? '',
                    (($row['vac_earned'] ?? 0) != 0 ? number_format($row['vac_earned'], 3) : ''),
                    (($row['vac_deducted'] ?? 0) != 0 ? number_format($row['vac_deducted'], 3) : ''),
                    ($vb === '' ? '' : number_format($vb,3)),
                    (($row['sick_earned'] ?? 0) != 0 ? number_format($row['sick_earned'], 3) : ''),
                    (($row['sick_deducted'] ?? 0) != 0 ? number_format($row['sick_deducted'], 3) : ''),
                    ($sb === '' ? '' : number_format($sb,3)),
                    $row['status'] ?? ''
                ]);
            } else {
                fputcsv($out, array_values($row));
            }
        }
        fclose($out);
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
    $reportData = [];

    // leave requests with correct balances from budget_history
    $leaveStmt = $db->prepare(
        "SELECT lr.created_at, COALESCE(lt.name, lr.leave_type) AS leave_type, lr.status, lr.total_days
         FROM leave_requests lr
         LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
         WHERE lr.employee_id = ?
         ORDER BY lr.created_at ASC"
    );
    $leaveStmt->execute([$empId]);
    foreach ($leaveStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $vacDed = 0;
        $sickDed = 0;
        if (strtolower($r['leave_type']) !== 'sick' && $r['status'] === 'approved') {
            $vacDed = floatval($r['total_days']);
        } elseif (strtolower($r['leave_type']) === 'sick' && $r['status'] === 'approved') {
            $sickDed = floatval($r['total_days']);
        }
        // balance after this leave
        $vacBal = '';
        $sickBal = '';
        $balStmt = $db->prepare("SELECT new_balance FROM budget_history WHERE employee_id=? AND leave_type IN ('Annual','Vacational','Vacation') AND created_at >= ? ORDER BY created_at ASC LIMIT 1");
        $balStmt->execute([$empId, $r['created_at']]);
        $vacBal = floatval($balStmt->fetchColumn() ?: 0);
        $balStmt2 = $db->prepare("SELECT new_balance FROM budget_history WHERE employee_id=? AND leave_type='Sick' AND created_at >= ? ORDER BY created_at ASC LIMIT 1");
        $balStmt2->execute([$empId, $r['created_at']]);
        $sickBal = floatval($balStmt2->fetchColumn() ?: 0);
        $reportData[] = [
            'date' => substr($r['created_at'],0,10),
            'particulars' => $r['leave_type'] . ' Leave',
            'vac_earned' => 0,
            'vac_deducted' => $vacDed,
            'vac_balance' => $vacBal,
            'sick_earned' => 0,
            'sick_deducted' => $sickDed,
            'sick_balance' => $sickBal,
            'status' => ucfirst($r['status'])
        ];
    }

    // budget history entries
    $budgetStmt = $db->prepare(
        "SELECT created_at, leave_type, action, old_balance, new_balance
         FROM budget_history
         WHERE employee_id = ?
         ORDER BY created_at ASC"
    );
    $budgetStmt->execute([$empId]);
    foreach ($budgetStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $vacDed = 0;
        $sickDed = 0;
        $vacEarn = 0;
        $sickEarn = 0;

        $part = ucfirst($r['action']) . ' ' . $r['leave_type'];
        if (in_array(strtolower($r['leave_type']), ['annual','vacational','vacation'])) {
            $vacEarn = max(0, floatval($r['new_balance']) - floatval($r['old_balance']));
            $vacDed  = max(0, floatval($r['old_balance']) - floatval($r['new_balance']));
            $vacBal  = floatval($r['new_balance']);
            $sickBal = '';
        } elseif (strtolower($r['leave_type']) === 'sick') {
            $sickEarn = max(0, floatval($r['new_balance']) - floatval($r['old_balance']));
            $sickDed  = max(0, floatval($r['old_balance']) - floatval($r['new_balance']));
            $sickBal  = floatval($r['new_balance']);
            $vacBal   = '';
        } else {
            $vacBal = '';
            $sickBal = '';
        }

        $reportData[] = [
            'date' => substr($r['created_at'],0,10),
            'particulars' => $part,
            'vac_earned' => $vacEarn,
            'vac_deducted' => $vacDed,
            'vac_balance' => isset($vacBal) ? $vacBal : '',
            'sick_earned' => $sickEarn,
            'sick_deducted' => $sickDed,
            'sick_balance' => isset($sickBal) ? $sickBal : '',
            'status' => ucfirst($r['action'])
        ];
    }

    usort($reportData, function($a,$b){return strtotime($a['date'])-strtotime($b['date']);});
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
            <?php if($_SESSION['role'] !== 'employee'): ?>
            <div>
                <label>Report Type:</label>
                <select name="type">
                    <option value="summary" <?= ($reportType === 'summary' ? 'selected' : ''); ?>>Summary</option>
                    <option value="balance" <?= ($reportType === 'balance' ? 'selected' : ''); ?>>Leave Balance</option>
                    <option value="usage" <?= ($reportType === 'usage' ? 'selected' : ''); ?>>Leave Usage</option>
                    <option value="leave_card" <?= ($reportType === 'leave_card' ? 'selected' : ''); ?>>Leave Card</option>
                </select>
            </div>
            <?php else: ?>
            <!-- For employees, only show leave_card without dropdown -->
            <input type="hidden" name="type" value="leave_card">
            <p style="margin:0;">Viewing: <strong>Leave Card</strong></p>
            <?php endif; ?>
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
                <tr><td>Average Vacational Balance</td><td><?= round($avgAnnualBalance, 3); ?> days</td></tr>
            </table>
        </div>
    <?php elseif ($reportType === 'balance'): ?>
        <div class="card">
            <table>
                <tr>
                    <th>Name</th>
                    <th>Department</th>
                    <th>Vacational Balance</th>
                    <th>Sick Balance</th>
                    <th>Force Balance</th>
                </tr>
                <?php foreach ($reportData as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                    <td><?= htmlspecialchars($row['department']); ?></td>
                    <td><?= round($row['annual_balance'], 3); ?></td>
                    <td><?= round($row['sick_balance'], 3); ?></td>
                    <td><?= $row['force_balance']; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php elseif ($reportType === 'leave_card' && $employeeFilter): ?>
        <div class="card">
            <h3>Leave Card - Complete Transaction History for <?= htmlspecialchars($currentEmp['first_name'].' '.$currentEmp['last_name']); ?></h3>
            <p style="font-size:12px;color:#666;">Shows all transactions by date: earned accruals, approved leaves, undertime deductions, and absences without pay.</p>
            <table>
                <tr style="background-color:#e0e0e0;font-weight:bold;">
                    <th>Date</th>
                    <th>Particulars</th>
                    <th>Vac Earned</th>
                    <th>Vac Deducted</th>
                    <th>Vac Balance</th>
                    <th>Sick Earned</th>
                    <th>Sick Deducted</th>
                    <th>Sick Balance</th>
                    <th>Status</th>
                </tr>
                <?php foreach($reportData as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['date']); ?></td>
                    <td><?= htmlspecialchars($row['particulars']); ?></td>
                    <td><?= ($row['vac_earned'] != 0 ? number_format($row['vac_earned'], 3) : ''); ?></td>
                    <td><?= ($row['vac_deducted'] != 0 ? number_format($row['vac_deducted'], 3) : ''); ?></td>
                    <td style="background-color:<?= ($row['vac_balance'] < 0 ? '#ffcccc' : '#ccffcc'); ?>;"><?= ($row['vac_balance'] !== '' ? number_format($row['vac_balance'], 3) : ''); ?></td>
                    <td><?= ($row['sick_earned'] != 0 ? number_format($row['sick_earned'], 3) : ''); ?></td>
                    <td><?= ($row['sick_deducted'] != 0 ? number_format($row['sick_deducted'], 3) : ''); ?></td>
                    <td style="background-color:<?= ($row['sick_balance'] < 0 ? '#ffcccc' : '#ccffcc'); ?>;"><?= ($row['sick_balance'] !== '' ? number_format($row['sick_balance'], 3) : ''); ?></td>
                    <td><?= htmlspecialchars($row['status']); ?></td>
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
                    <td><?= round($row['total_days'], 3); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

</div>

</body>
</html>
