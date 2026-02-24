<?php
class Leave {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function calculateDays($start, $end) {
        $start = new DateTime($start);
        $end = new DateTime($end);
        return $end->diff($start)->days + 1;
    }

    public function checkOverlap($employee_id, $start, $end) {
        $query = "SELECT COUNT(*) FROM leave_requests
                  WHERE employee_id = :id
                  AND status = 'approved'
                  AND (start_date <= :end AND end_date >= :start)";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':id' => $employee_id,
            ':start' => $start,
            ':end' => $end
        ]);
        return $stmt->fetchColumn() > 0;
    }

    public function apply($employee_id, $type, $start, $end, $reason) {

        $days = $this->calculateDays($start, $end);

        if ($this->checkOverlap($employee_id, $start, $end)) {
            return "Overlapping leave exists.";
        }

        // enforce use of force leave first
        if (strtolower($type) !== 'force') {
            $forceBal = $this->getBalanceByType($employee_id, 'force');
            if ($forceBal > 0) {
                return "You have $forceBal force leave day(s) left which must be taken before requesting other leave types.";
            }
        }

        $balance = $this->getBalanceByType($employee_id, $type);

        if ($balance < $days) {
            return "Insufficient $type leave balance.";
        }

        $query = "INSERT INTO leave_requests 
                  (employee_id, leave_type, start_date, end_date, total_days, reason)
                  VALUES (:eid, :type, :start, :end, :days, :reason)";
        $stmt = $this->conn->prepare($query);

        $stmt->execute([
            ':eid' => $employee_id,
            ':type' => $type,
            ':start' => $start,
            ':end' => $end,
            ':days' => $days,
            ':reason' => $reason
        ]);

        return "Leave submitted successfully.";
    }

    /**
     * Retrieve a balance depending on leave type.
     */
    private function getBalanceByType($employee_id, $type) {
        switch (strtolower($type)) {
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
                // fallback to the old generic column
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
        $query = "UPDATE employees 
                  SET annual_balance = annual_balance + 1.25,
                      force_balance = 5";
        return $this->conn->exec($query) !== false;
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

            // if approved, deduct from balance and log change
            if ($action === 'approve') {
                $col = 'leave_balance';
                switch (strtolower($leave['leave_type'])) {
                    case 'annual':
                        $col = 'annual_balance';
                        break;
                    case 'sick':
                        $col = 'sick_balance';
                        break;
                    case 'force':
                        $col = 'force_balance';
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
                
                // record in budget history
                $newBalance = max(0, $oldBalance - $leave['total_days']);
                $this->logBudgetChange($leave['employee_id'], $leave['leave_type'], $oldBalance, $newBalance, 'deduction', $leave_id, 'Leave approved');
            }

            $this->conn->commit();
            return true;

        } catch (Exception $e) {
            $this->conn->rollBack();
            return false;
        }
    }

    // keep the old helper around for backwards compatibility
    public function approveLeave($leave_id, $manager_id) {
        return $this->respondToLeave($leave_id, $manager_id, 'approve');
    }
}