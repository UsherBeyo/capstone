<?php
session_start();
require_once '../config/database.php';
require_once '../helpers/Auth.php';

// most endpoints require the user be authenticated
if (empty($_SESSION['user_id'])) {
    header("Location: ../views/login.php");
    exit();
}
require_once '../models/Leave.php';
require_once '../models/LeaveType.php';
require_once '../services/Mail.php';
require_once '../helpers/Validator.php';
require_once '../helpers/ErrorHandler.php';

$db = (new Database())->connect();
$leaveModel = new Leave($db);

// determine the requested action
$action = isset($_POST['action']) ? $_POST['action'] : null;

if ($action === 'approve') {

    if (!in_array($_SESSION['role'], ['manager','admin'])) {
        die("Unauthorized access");
    }
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF validation failed.");
    }

    $leave_id = $_POST['leave_id'];
    $manager_id = $_SESSION['user_id'];

    $leaveModel->respondToLeave($leave_id, $manager_id, 'approve');

    // send email to employee informing approval
    $stmt = $db->prepare("SELECT u.email, lr.start_date, lr.end_date, COALESCE(lt.name, lr.leave_type) AS leave_type
        FROM leave_requests lr
        JOIN employees e ON lr.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
        WHERE lr.id = ?");
    $stmt->execute([$leave_id]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        Mail::send($row['email'], "Your leave request approved", "Your {$row['leave_type']} leave from {$row['start_date']} to {$row['end_date']} has been approved.");
    }

    header("Location: ../views/dashboard.php?toast_success=Leave+approved");
    exit();
}

if ($action === 'reject') {
    if (!in_array($_SESSION['role'], ['manager','admin'])) {
        die("Unauthorized access");
    }
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF validation failed.");
    }
    $leave_id = $_POST['leave_id'];
    $manager_id = $_SESSION['user_id'];
    $comments = trim($_POST['comments'] ?? '');

    $leaveModel->respondToLeave($leave_id, $manager_id, 'reject', $comments);

    // notify employee
    $stmt = $db->prepare("SELECT u.email, lr.start_date, lr.end_date, COALESCE(lt.name, lr.leave_type) AS leave_type
        FROM leave_requests lr
        JOIN employees e ON lr.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
        WHERE lr.id = ?");
    $stmt->execute([$leave_id]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        Mail::send($row['email'], "Your leave request was rejected", "Your {$row['leave_type']} leave from {$row['start_date']} to {$row['end_date']} has been rejected. Reason: {$comments}");
    }

    header("Location: ../views/dashboard.php?toast_warning=Leave+rejected");
    exit();
}

if ($action === 'apply') {
    // only employees can apply
    if ($_SESSION['role'] !== 'employee') {
        die("Unauthorized access");
    }

    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF validation failed.");
    }

    $employee_id = $_SESSION['emp_id'] ?? null; // store employee id in session when user logs in
    if (!$employee_id) {
        die("Employee record not found");
    }

    $typeId = $_POST['leave_type_id'] ?? null;
    $start = $_POST['start_date'] ?? '';
    $end = $_POST['end_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    // validate input
    $v = new Validator();
    $v->required('leave_type_id', $typeId)
      ->required('start_date', $start)
      ->date('start_date', $start)
      ->required('end_date', $end)
      ->date('end_date', $end);
    if ($v->fails()) {
        $err = implode(' ', array_map('implode', $v->getErrors()));
        header("Location: ../views/dashboard.php?toast_error=".urlencode($err));
        exit();
    }

    $result = $leaveModel->apply($employee_id, $typeId, $start, $end, $reason);

    // send notification to admin that a new leave was submitted
    if (strpos($result, 'successfully') !== false) {
        // basic mail; in production use a real templating engine
        $dbType = new LeaveType($db);
        $typeInfo = $dbType->get($typeId);
        $subject = "New leave request from employee {$employee_id}";
        $body = "Employee {$employee_id} has applied for {$typeInfo['name']} leave from {$start} to {$end}.";
        Mail::send('hr@example.com', $subject, $body);

        // if auto-approved, notify the employee
        if ($typeInfo && $typeInfo['auto_approve']) {
            $userEmail = $_SESSION['user_email'] ?? null;
            if ($userEmail) {
                Mail::send($userEmail, "Your leave has been approved", "Your {$typeInfo['name']} leave from {$start} to {$end} was auto-approved.");
            }
        }
    }

    // use toast based on success or failure
    if (strpos($result, 'successfully') !== false) {
        header("Location: ../views/dashboard.php?toast_success=".urlencode($result));
    } else {
        header("Location: ../views/dashboard.php?toast_error=".urlencode($result));
    }
    exit();
}
