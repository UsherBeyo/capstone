<?php
class Leave {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function calculateDays($start, $end) {
        $startDT = new DateTime($start);
        $endDT = new DateTime($end);
        $days = 0;

        $stmt = $this->conn->prepare("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
        $stmt->execute([$startDT->format('Y-m-d'), $endDT->format('Y-m-d')]);
        $holidays = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $holidaySet = array_flip($holidays);

        while ($startDT <= $endDT) {
            $weekday = (int)$startDT->format('N');
            $today = $startDT->format('Y-m-d');
            if ($weekday < 6 && !isset($holidaySet[$today])) {
                $days++;
            }
            $startDT->modify('+1 day');
        }

        return max(1, $days);
    }

    public function checkOverlap($employee_id, $start, $end) {
        $query = "SELECT COUNT(*) FROM leave_requests
                  WHERE employee_id = :id
                  AND status IN ('approved','pending')
                  AND (start_date <= :end AND end_date >= :start)";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':id' => $employee_id,
            ':start' => $start,
            ':end' => $end
        ]);
        return $stmt->fetchColumn() > 0;
    }

    private function getDepartmentHeadUserIdForEmployee(int $employeeId): ?int {
        $stmt = $this->conn->prepare("
            SELECT u.id
            FROM employees e
            JOIN departments d ON e.department_id = d.id
            LEFT JOIN department_head_assignments dha ON d.id = dha.department_id AND dha.is_active = 1
            LEFT JOIN employees dh ON dha.employee_id = dh.id
            LEFT JOIN users u ON dh.user_id = u.id
            WHERE e.id = ?
            LIMIT 1
        ");
        $stmt->execute([$employeeId]);
        $val = $stmt->fetchColumn();
        return $val ? (int)$val : null;
    }

    public function apply($employee_id, $typeIdentifier, $start, $end, $reason, $applicantUserId = null, $applicantRole = 'employee', $commutation = null) {
        $leaveType = $this->getLeaveType($typeIdentifier);
        if (!$leaveType) {
            return "Invalid leave type.";
        }

        $days = $this->calculateDays($start, $end);

        if ($leaveType['deduct_balance']) {
            $currentBal = $this->getBalanceByType($employee_id, $leaveType['name']);
            if ($currentBal < 0) {
                return "Cannot apply: leave balance is negative.";
            }
        }

        if ($this->checkOverlap($employee_id, $start, $end)) {
            return "Overlapping leave exists.";
        }

        if ($leaveType['deduct_balance']) {
            $balance = $this->getBalanceByType($employee_id, $leaveType['name']);
            if ($balance < $days) {
                return "Insufficient {$leaveType['name']} leave balance.";
            }
        }

        if (!is_null($leaveType['max_days_per_year']) && $leaveType['max_days_per_year'] > 0) {
            $stmt = $this->conn->prepare(
                "SELECT SUM(total_days) FROM leave_requests
                 WHERE employee_id = ? AND leave_type = ? AND YEAR(start_date) = YEAR(?) AND status = 'approved'"
            );
            $stmt->execute([$employee_id, $leaveType['name'], $start]);
            $already = floatval($stmt->fetchColumn() ?: 0);
            if ($already + $days > $leaveType['max_days_per_year']) {
                return "Applying would exceed annual limit for {$leaveType['name']} leave.";
            }
        }

        $snapshots = $this->getBalanceSnapshots($employee_id);

        // Get employee's department
        $stmtDept = $this->conn->prepare("SELECT department_id FROM employees WHERE id = ?");
        $stmtDept->execute([$employee_id]);
        $departmentId = $stmtDept->fetchColumn();

        $status = 'pending';
        $workflowStatus = 'pending_department_head';
        $departmentHeadUserId = $this->getDepartmentHeadUserIdForEmployee((int)$employee_id);
        $departmentHeadApprovedAt = null;
        $personnelUserId = null; // Will be assigned later or find a personnel user

        // Check if employee is the department head
        $isDepartmentHead = false;
        if ($departmentHeadUserId && $applicantUserId == $departmentHeadUserId) {
            $isDepartmentHead = true;
        }

        // If no department head or employee is department head, go to personnel
        if (!$departmentHeadUserId || $isDepartmentHead) {
            $workflowStatus = 'pending_personnel';
            $departmentHeadApprovedAt = date('Y-m-d H:i:s');
        }

        // Self-approval of department head / legacy manager requests
        if (in_array($applicantRole, ['manager', 'department_head', 'admin'], true)) {
            $workflowStatus = 'pending_personnel';
            $departmentHeadUserId = $applicantUserId ?: $departmentHeadUserId;
            $departmentHeadApprovedAt = date('Y-m-d H:i:s');
        }

        if (!empty($leaveType['auto_approve'])) {
            $status = 'approved';
            $workflowStatus = 'finalized';
        }

        // Block if no department head and not self-approving
        if (!$departmentHeadUserId && !in_array($applicantRole, ['manager', 'department_head', 'admin'], true)) {
            return "Cannot submit leave: No department head assigned to your department.";
        }

        try {
            $query = "INSERT INTO leave_requests 
                (employee_id, department_id, leave_type, leave_type_id, start_date, end_date, total_days, reason, status, workflow_status, department_head_user_id, personnel_user_id, department_head_approved_at, snapshot_annual_balance, snapshot_sick_balance, snapshot_force_balance, commutation)
                VALUES (:eid, :dept_id, :type, :typeid, :start, :end, :days, :reason, :status, :workflow_status, :department_head_user_id, :personnel_user_id, :department_head_approved_at, :snap_annual, :snap_sick, :snap_force, :commutation)";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':eid' => $employee_id,
                ':dept_id' => $departmentId,
                ':type' => $leaveType['name'],
                ':typeid' => $leaveType['id'],
                ':start' => $start,
                ':end' => $end,
                ':days' => $days,
                ':reason' => $reason,
                ':status' => $status,
                ':workflow_status' => $workflowStatus,
                ':department_head_user_id' => $departmentHeadUserId,
                ':personnel_user_id' => $personnelUserId,
                ':department_head_approved_at' => $departmentHeadApprovedAt,
                ':snap_annual' => $snapshots['annual_balance'],
                ':snap_sick' => $snapshots['sick_balance'],
                ':snap_force' => $snapshots['force_balance'],
                ':commutation' => $commutation
            ]);
        } catch (\Throwable $e) {
            // fallback for older schema
            $query = "INSERT INTO leave_requests 
                (employee_id, leave_type, leave_type_id, start_date, end_date, total_days, reason, status, workflow_status, department_head_user_id, department_head_approved_at, snapshot_annual_balance, snapshot_sick_balance, snapshot_force_balance, commutation)
                VALUES (:eid, :type, :typeid, :start, :end, :days, :reason, :status, :workflow_status, :department_head_user_id, :department_head_approved_at, :snap_annual, :snap_sick, :snap_force, :commutation)";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':eid' => $employee_id,
                ':type' => $leaveType['name'],
                ':typeid' => $leaveType['id'],
                ':start' => $start,
                ':end' => $end,
                ':days' => $days,
                ':reason' => $reason,
                ':status' => $status,
                ':workflow_status' => $workflowStatus,
                ':department_head_user_id' => $departmentHeadUserId,
                ':department_head_approved_at' => $departmentHeadApprovedAt,
                ':snap_annual' => $snapshots['annual_balance'],
                ':snap_sick' => $snapshots['sick_balance'],
                ':snap_force' => $snapshots['force_balance'],
                ':commutation' => $commutation
            ]);
        }

        if ($status === 'approved' && $leaveType['deduct_balance']) {
            $newId = $this->conn->lastInsertId();
            $this->respondToLeave($newId, null, 'approve');
        }

        return "Leave submitted successfully.";
    }

    public function getBalanceSnapshots($employee_id) {
        try {
            $stmt = $this->conn->prepare("SELECT annual_balance, sick_balance, force_balance, leave_balance FROM employees WHERE id = :id");
            $stmt->execute([':id' => $employee_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: ['annual_balance'=>0,'sick_balance'=>0,'force_balance'=>0,'leave_balance'=>0];
        } catch (\Throwable $e) {
            $stmt = $this->conn->prepare("SELECT annual_balance, sick_balance, force_balance FROM employees WHERE id = :id");
            $stmt->execute([':id' => $employee_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $row = $row ?: ['annual_balance'=>0,'sick_balance'=>0,'force_balance'=>0];
            $row['leave_balance'] = 0;
            return $row;
        }
    }

    public function getLeaveType($identifier) {
        if (is_numeric($identifier)) {
            $stmt = $this->conn->prepare("SELECT * FROM leave_types WHERE id = ?");
        } else {
            $stmt = $this->conn->prepare("SELECT * FROM leave_types WHERE name = ?");
        }
        $stmt->execute([$identifier]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getBalanceByType($employee_id, $type) {
        if (is_numeric($type)) {
            $typeInfo = $this->getLeaveType($type);
            $name = $typeInfo ? $typeInfo['name'] : null;
        } else {
            $name = $type;
        }

        switch (strtolower((string)$name)) {
            case 'annual':
            case 'vacational':
            case 'vacation':
                $col = 'annual_balance';
                break;
            case 'sick':
                $col = 'sick_balance';
                break;
            case 'force':
                $col = 'force_balance';
                break;
            default:
                $col = 'leave_balance';
        }

        $stmt = $this->conn->prepare("SELECT $col FROM employees WHERE id = :id");
        $stmt->execute([':id' => $employee_id]);
        return $stmt->fetchColumn();
    }

    private function recordAccrualLogsForEmployee(
        int $employeeId,
        float $amount,
        string $monthRef,
        string $transDate,
        string $notePrefix = 'Accrual recorded'
    ): void {
        $insert = $this->conn->prepare("
            INSERT INTO accrual_history (employee_id, amount, date_accrued, month_reference)
            VALUES (?, ?, ?, ?)
        ");
        $insert->execute([$employeeId, $amount, $transDate . ' 00:00:00', $monthRef]);

        $stmt = $this->conn->prepare("
            SELECT annual_balance, sick_balance
            FROM employees
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['annual_balance' => 0, 'sick_balance' => 0];

        $newAnnual = floatval($row['annual_balance']);
        $newSick   = floatval($row['sick_balance']);
        $oldAnnual = $newAnnual - $amount;
        $oldSick   = $newSick - $amount;

        $note = $notePrefix . ' for ' . $monthRef;

        $this->logBudgetChange(
            $employeeId,
            'Vacational',
            $oldAnnual,
            $newAnnual,
            'accrual',
            null,
            $note,
            $transDate
        );

        $this->logBudgetChange(
            $employeeId,
            'Sick',
            $oldSick,
            $newSick,
            'accrual',
            null,
            $note,
            $transDate
        );
    }

    public function accrueSingleEmployee(
        int $employeeId,
        float $amount = 1.25,
        ?string $monthRef = null,
        ?string $transDate = null,
        string $notePrefix = 'Manual accrual recorded'
    ): bool {
        $monthRef = $monthRef ?: date('Y-m');
        $transDate = $transDate ?: date('Y-m-d');

        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare("
                UPDATE employees
                SET annual_balance = annual_balance + ?,
                    sick_balance = sick_balance + ?
                WHERE id = ?
            ");
            $stmt->execute([$amount, $amount, $employeeId]);

            if ($stmt->rowCount() < 1) {
                throw new Exception('Employee not found.');
            }

            $this->recordAccrualLogsForEmployee($employeeId, $amount, $monthRef, $transDate, $notePrefix);

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return false;
        }
    }

    public function accrueAllEmployees(
        float $amount = 1.25,
        ?string $monthRef = null,
        ?string $transDate = null,
        string $notePrefix = 'Bulk accrual recorded'
    ): array {
        $monthRef = $monthRef ?: date('Y-m');
        $transDate = $transDate ?: date('Y-m-d');

        try {
            $this->conn->beginTransaction();

            $empStmt = $this->conn->query("SELECT id FROM employees ORDER BY id ASC");
            $employeeIds = $empStmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($employeeIds)) {
                $this->conn->commit();
                return [
                    'success' => true,
                    'count' => 0,
                    'message' => 'No employees found.'
                ];
            }

            $updateStmt = $this->conn->prepare("
                UPDATE employees
                SET annual_balance = annual_balance + ?,
                    sick_balance = sick_balance + ?
                WHERE id = ?
            ");

            foreach ($employeeIds as $employeeId) {
                $updateStmt->execute([$amount, $amount, $employeeId]);
                $this->recordAccrualLogsForEmployee((int)$employeeId, $amount, $monthRef, $transDate, $notePrefix);
            }

            $this->conn->commit();

            return [
                'success' => true,
                'count' => count($employeeIds),
                'message' => 'Bulk accrual completed successfully.'
            ];
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            return [
                'success' => false,
                'count' => 0,
                'message' => 'Failed to perform bulk accrual.'
            ];
        }
    }

    public function accrueMonthly(): bool {
        $result = $this->accrueAllEmployees(
            1.25,
            date('Y-m'),
            date('Y-m-t'),
            'Monthly accrual recorded'
        );

        return !empty($result['success']);
    }

    public function logBudgetChange(
        $employee_id,
        $leave_type,
        $old_balance,
        $new_balance,
        $action,
        $leave_request_id = null,
        $notes = null,
        $trans_date = null
    ) {
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO budget_history (employee_id, trans_date, leave_type, old_balance, new_balance, action, leave_request_id, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            return $stmt->execute([
                $employee_id,
                $trans_date,
                $leave_type,
                $old_balance,
                $new_balance,
                $action,
                $leave_request_id,
                $notes
            ]);
        } catch (\Throwable $e) {
            $stmt = $this->conn->prepare(
                "INSERT INTO budget_history (employee_id, leave_type, old_balance, new_balance, action, leave_request_id, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            return $stmt->execute([
                $employee_id,
                $leave_type,
                $old_balance,
                $new_balance,
                $action,
                $leave_request_id,
                $notes
            ]);
        }
    }

    public function respondToLeave($leave_id, $manager_id, $action, $comments = '') {
        if (!in_array($action, ['approve','reject'])) {
            return false;
        }

        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare(
                "SELECT employee_id, total_days, leave_type, leave_type_id
                 FROM leave_requests 
                 WHERE id = :id AND status = 'pending'"
            );
            $stmt->execute([':id' => $leave_id]);
            $leave = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$leave) {
                return false;
            }

            $status = $action === 'approve' ? 'approved' : 'rejected';
            $this->conn->prepare(
                "UPDATE leave_requests 
                 SET status=:status, approved_by=:manager, manager_comments=:comments 
                 WHERE id=:id"
            )->execute([
                ':status' => $status,
                ':manager' => $manager_id,
                ':comments' => $comments,
                ':id' => $leave_id
            ]);

            if ($action === 'approve') {
                $typeInfo = $this->getLeaveType($leave['leave_type_id'] ?? $leave['leave_type']);
                if ($typeInfo && $typeInfo['deduct_balance']) {
                    $col = 'leave_balance';
                    switch (strtolower($typeInfo['name'])) {
                        case 'annual':
                        case 'vacational':
                        case 'vacation':
                            $col = 'annual_balance';
                            break;
                        case 'sick':
                            $col = 'sick_balance';
                            break;
                        case 'force':
                            $col = 'annual_balance';
                            break;
                    }

                    $stmt = $this->conn->prepare("SELECT $col FROM employees WHERE id = ?");
                    $stmt->execute([$leave['employee_id']]);
                    $oldBalance = floatval($stmt->fetchColumn());

                    $stmt = $this->conn->prepare(
                        "UPDATE employees 
                         SET $col = $col - :days 
                         WHERE id=:employee_id"
                    );
                    $stmt->execute([
                        ':days' => $leave['total_days'],
                        ':employee_id' => $leave['employee_id']
                    ]);

                    $snapshots = $this->getBalanceSnapshots($leave['employee_id']);

                    $this->conn->prepare(
                        "UPDATE leave_requests 
                         SET snapshot_annual_balance = ?, snapshot_sick_balance = ?, snapshot_force_balance = ? 
                         WHERE id = ?"
                    )->execute([
                        $snapshots['annual_balance'],
                        $snapshots['sick_balance'],
                        $snapshots['force_balance'],
                        $leave_id
                    ]);

                    $newBalance = max(0, $oldBalance - $leave['total_days']);
                    $this->logBudgetChange(
                        $leave['employee_id'],
                        $typeInfo['name'],
                        $oldBalance,
                        $newBalance,
                        'deduction',
                        $leave_id,
                        'Leave approved'
                    );

                    $stmt = $this->conn->prepare(
                        "INSERT INTO leave_balance_logs (employee_id, change_amount, reason, leave_id)
                         VALUES (?, ?, ?, ?)"
                    );
                    $stmt->execute([$leave['employee_id'], -1 * $leave['total_days'], 'deduction', $leave_id]);
                }
            }

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return false;
        }
    }

    public function approveLeave($leave_id, $manager_id) {
        return $this->respondToLeave($leave_id, $manager_id, 'approve');
    }
}