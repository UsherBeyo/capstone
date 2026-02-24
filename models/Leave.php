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

        $balance = $this->getBalance($employee_id);

        if ($balance < $days) {
            return "Insufficient leave balance.";
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

    private function getBalance($employee_id) {
        $stmt = $this->conn->prepare("SELECT leave_balance FROM employees WHERE id = :id");
        $stmt->execute([':id' => $employee_id]);
        return $stmt->fetchColumn();
    }

    public function approveLeave($leave_id, $manager_id) {

    try {
        $this->conn->beginTransaction();

        // Get leave details
        $stmt = $this->conn->prepare(
            "SELECT employee_id, total_days 
             FROM leave_requests 
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([':id' => $leave_id]);
        $leave = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$leave) {
            return false;
        }

        // Update leave status
        $this->conn->prepare(
            "UPDATE leave_requests 
             SET status='approved', approved_by=:manager 
             WHERE id=:id"
        )->execute([
            ':manager' => $manager_id,
            ':id' => $leave_id
        ]);

        // Deduct leave balance
        $this->conn->prepare(
            "UPDATE employees 
             SET leave_balance = leave_balance - :days 
             WHERE id=:employee_id"
        )->execute([
            ':days' => $leave['total_days'],
            ':employee_id' => $leave['employee_id']
        ]);

        $this->conn->commit();
        return true;

    } catch (Exception $e) {
        $this->conn->rollBack();
        return false;
    }
}
}