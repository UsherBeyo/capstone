<?php
session_start();
require_once '../config/database.php';
require_once '../models/Leave.php';

$db = (new Database())->connect();
$leaveModel = new Leave($db);

// determine the requested action
$action = isset($_POST['action']) ? $_POST['action'] : null;

if ($action === 'approve') {

    if (!in_array($_SESSION['role'], ['manager','admin'])) {
        die("Unauthorized access");
    }

    $leave_id = $_POST['leave_id'];
    $manager_id = $_SESSION['user_id'];

    $leaveModel->respondToLeave($leave_id, $manager_id, 'approve');

    header("Location: ../views/dashboard.php");
    exit();
}

if ($action === 'reject') {
    if (!in_array($_SESSION['role'], ['manager','admin'])) {
        die("Unauthorized access");
    }
    $leave_id = $_POST['leave_id'];
    $manager_id = $_SESSION['user_id'];
    $comments = trim($_POST['comments'] ?? '');

    $leaveModel->respondToLeave($leave_id, $manager_id, 'reject', $comments);
    header("Location: ../views/dashboard.php");
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

    $type = $_POST['leave_type'];
    $start = $_POST['start_date'];
    $end = $_POST['end_date'];
    $reason = trim($_POST['reason']);

    $result = $leaveModel->apply($employee_id, $type, $start, $end, $reason);

    // you could attach the message to session for display
    $_SESSION['message'] = $result;

    header("Location: ../views/dashboard.php");
    exit();
}
