<?php
class Leave {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Count deductible days between two dates, excluding weekends and holidays.
     * This value is what will be subtracted from the employee's balance.
     */
    public function calculateDays($start, $end) {
        $startDT = new DateTime($start);
        $endDT = new DateTime($end);
        $days = 0;

        // prefetch all holidays in range to avoid querying inside loop
        $stmt = $this->conn->prepare("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
        $stmt->execute([$startDT->format('Y-m-d'), $endDT->format('Y-m-d')]);
        $holidays = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $holidaySet = array_flip($holidays);

        while ($startDT <= $endDT) {
            $weekday = (int)$startDT->format('N'); // 1 = Mon ... 7 = Sun
            $today = $startDT->format('Y-m-d');
            if ($weekday < 6 && !isset($holidaySet[$today])) {
                $days++;
            }
            $startDT->modify('+1 day');
        }
        return $days;
    }

    /**
     * Determine if the requested date range overlaps with any existing
     * approved or pending leave for the same employee.
     */
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

    /**
     * Submit a leave request.  Accepts either a leave type name or ID.
     * Contains auto‑approval and rule evaluation logic.
     */
    public function apply($employee_id, $typeIdentifier, $start, $end, $reason) {
        // resolve leave type metadata
        $leaveType = $this->getLeaveType($typeIdentifier);
        if (!$leaveType) {
            return "Invalid leave type.";
        }

        $days = $this->calculateDays($start, $end);

        // auto‑approve short sick leaves even if table doesn't specify it
        if (strtolower($leaveType['name']) === 'sick' && $days <= 1) {
            $leaveType['auto_approve'] = 1;
        }

        // if we are deducting balance, and balance is already negative, immediately reject
        if ($leaveType['deduct_balance']) {
            $currentBal = $this->getBalanceByType($employee_id, $leaveType['name']);
            if ($currentBal < 0) {
                return "Cannot apply: leave balance is negative.";
            }
        }

        if ($this->checkOverlap($employee_id, $start, $end)) {
            return "Overlapping leave exists.";
        }

        // if this type deducts balance, ensure sufficient
        if ($leaveType['deduct_balance']) {
            $balance = $this->getBalanceByType($employee_id, $leaveType['name']);
            if ($balance < $days) {
                return "Insufficient {$leaveType['name']} leave balance.";
            }
        }

        // enforce max days per year if configured
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

        // capture snapshots before insertion
        $snapshots = $this->getBalanceSnapshots($employee_id);

        $status = 'pending';
        if ($leaveType['auto_approve']) {
            $status = 'approved';
        }

        // insert request (keep old leave_type string for backwards compatibility)
        $query = "INSERT INTO leave_requests 
                  (employee_id, leave_type, leave_type_id, start_date, end_date, total_days, reason, status, snapshot_annual_balance, snapshot_sick_balance, snapshot_force_balance)
                  VALUES (:eid, :type, :typeid, :start, :end, :days, :reason, :status, :snap_annual, :snap_sick, :snap_force)";
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
            ':snap_annual' => $snapshots['annual_balance'],
            ':snap_sick' => $snapshots['sick_balance'],
            ':snap_force' => $snapshots['force_balance']
        ]);

        // if auto approved, deduct immediately
        if ($status === 'approved' && $leaveType['deduct_balance']) {
            $newId = $this->conn->lastInsertId();
            $this->respondToLeave($newId, null, 'approve');
        }

        return "Leave submitted successfully.";
    }

    /**
     * Retrieve all three balance snapshots for an employee
     */
    public function getBalanceSnapshots($employee_id) {
        $stmt = $this->conn->prepare("SELECT annual_balance, sick_balance, force_balance, leave_balance FROM employees WHERE id = :id");
        $stmt->execute([':id' => $employee_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Look up a leave type record by id or name.
     */
    public function getLeaveType($identifier) {
        if (is_numeric($identifier)) {
            $stmt = $this->conn->prepare("SELECT * FROM leave_types WHERE id = ?");
        } else {
            $stmt = $this->conn->prepare("SELECT * FROM leave_types WHERE name = ?");
        }
        $stmt->execute([$identifier]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve a balance depending on leave type.
     */
    /**
     * Obtain the current balance for a leave type.  Identifier may be name or id.
     */
    private function getBalanceByType($employee_id, $type) {
        // resolve to leave type name if necessary
        if (is_numeric($type)) {
            $typeInfo = $this->getLeaveType($type);
            $name = $typeInfo ? $typeInfo['name'] : null;
        } else {
            $name = $type;
        }

        switch (strtolower($name)) {
            case 'annual':
                $col = 'annual_balance';
                break;
            case 'sick':
                $col = 'sick_balance';
                break;
            case 'force':
                $col = 'force_balance';
                break;
            default:
                // fallback to the generic leave_balance column
                $col = 'leave_balance';
        }
        $stmt = $this->conn->prepare("SELECT $col FROM employees WHERE id = :id");
        $stmt->execute([':id' => $employee_id]);
        return $stmt->fetchColumn();
    }

    /**
     * Perform monthly accrual: each employee gains 1.25 days annual
     * and resets force leave quota to 5 for the new month.
     *
     * This method can be invoked from a cron job or administration script.
     */
    public function accrueMonthly() {
        // each employee gains 1.25 days for both annual and sick at end of month;
        // force leave resets to 5
        try {
            $this->conn->beginTransaction();
            $stmt = $this->conn->prepare("UPDATE employees 
                  SET annual_balance = annual_balance + 1.25,
                      sick_balance = sick_balance + 1.25,
                      force_balance = 5");
            $stmt->execute();

            // log accruals for each user (could also be done in trigger)
            $insert = $this->conn->prepare("INSERT INTO accrual_history (employee_id, amount, date_accrued, month_reference) VALUES (?, ?, ?, ?)");
            $monthRef = date('Y-m');
            // use month end date as the accrual date
            $accrualDate = date('Y-m-t');
            $empStmt = $this->conn->query("SELECT id FROM employees");
            while ($row = $empStmt->fetch(PDO::FETCH_ASSOC)) {
                $insert->execute([$row['id'], 1.25, $accrualDate, $monthRef]);
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

    /**
     * Log a budget change to budget_history table
     */
    public function logBudgetChange($employee_id, $leave_type, $old_balance, $new_balance, $action, $leave_request_id = null, $notes = null) {
        $stmt = $this->conn->prepare(
            "INSERT INTO budget_history (employee_id, leave_type, old_balance, new_balance, action, leave_request_id, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        return $stmt->execute([$employee_id, $leave_type, $old_balance, $new_balance, $action, $leave_request_id, $notes]);
    }

    /**
     * General manager response for a pending leave request.
     * action should be either 'approve' or 'reject'.
     * comments can contain optional reasoning.
     */
    public function respondToLeave($leave_id, $manager_id, $action, $comments = '') {

        if (!in_array($action, ['approve','reject'])) {
            return false;
        }

        try {
            $this->conn->beginTransaction();

            // Get leave details
            $stmt = $this->conn->prepare(
                "SELECT employee_id, total_days, leave_type 
                 FROM leave_requests 
                 WHERE id = :id AND status = 'pending'"
            );
            $stmt->execute([':id' => $leave_id]);
            $leave = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$leave) {
                return false;
            }

            // Update leave status and comments
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

            // if approved, we may need to deduct from balance and log change
            if ($action === 'approve') {
                // fetch leave type metadata to know if this type deducts balance
                $typeInfo = $this->getLeaveType($leave['leave_type_id'] ?? $leave['leave_type']);
                if ($typeInfo && $typeInfo['deduct_balance']) {
                    // choose the correct employee column based on type name
                    $col = 'leave_balance';
                            switch (strtolower($typeInfo['name'])) {
                        case 'annual':
                            $col = 'annual_balance';
                            break;
                        case 'sick':
                            $col = 'sick_balance';
                            break;
                        case 'force':
                            // force leave deducts from vacation (annual) balance per policy
                            $col = 'annual_balance';
                            break;
                    }

                    // get old balance before update
                    $stmt = $this->conn->prepare("SELECT $col FROM employees WHERE id = ?");
                    $stmt->execute([$leave['employee_id']]);
                    $oldBalance = floatval($stmt->fetchColumn());

                    // update balance
                    $stmt = $this->conn->prepare(
                        "UPDATE employees 
                         SET $col = $col - :days 
                         WHERE id=:employee_id"
                    );
                    $stmt->execute([
                        ':days' => $leave['total_days'],
                        ':employee_id' => $leave['employee_id']
                    ]);

                    // get updated snapshots of all three balance types
                    $snapshots = $this->getBalanceSnapshots($leave['employee_id']);

                    // update leave record with approved snapshots
                    $this->conn->prepare(
                        "UPDATE leave_requests 
                         SET snapshot_annual_balance = ?, snapshot_sick_balance = ?, snapshot_force_balance = ? 
                         WHERE id = ?"
                    )->execute([$snapshots['annual_balance'], $snapshots['sick_balance'], $snapshots['force_balance'], $leave_id]);

                    // record in budget history & new leave_balance_logs
                    $newBalance = max(0, $oldBalance - $leave['total_days']);
                    $this->logBudgetChange($leave['employee_id'], $typeInfo['name'], $oldBalance, $newBalance, 'deduction', $leave_id, 'Leave approved');

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

    // keep the old helper around for backwards compatibility
    public function approveLeave($leave_id, $manager_id) {
        return $this->respondToLeave($leave_id, $manager_id, 'approve');
    }
}