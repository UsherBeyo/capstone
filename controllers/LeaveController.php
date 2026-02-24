<?php
session_start();
require_once '../config/database.php';
require_once '../models/Leave.php';

$db = (new Database())->connect();
$leaveModel = new Leave($db);

if ($_POST['action'] === 'approve') {

    if ($_SESSION['role'] !== 'manager') {
        die("Unauthorized access");
    }

    $leave_id = $_POST['leave_id'];
    $manager_id = $_SESSION['user_id'];

    $leaveModel->approveLeave($leave_id, $manager_id);

    header("Location: ../views/dashboard.php");
    exit();
}