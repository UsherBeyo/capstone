<?php
session_start();
require_once '../config/database.php';
require_once '../models/LeaveType.php';

if (!in_array($_SESSION['role'], ['admin','hr'])) {
    die("Unauthorized");
}

$db = (new Database())->connect();
$typeModel = new LeaveType($db);

action:
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($action === 'list') {
    $types = $typeModel->all();
    include __DIR__ . '/../views/manage_leave_types.php';
    exit();
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'name' => trim($_POST['name']),
        'deduct_balance' => isset($_POST['deduct_balance']) ? 1 : 0,
        'requires_approval' => isset($_POST['requires_approval']) ? 1 : 0,
        'max_days_per_year' => $_POST['max_days_per_year'] ?: null,
        'auto_approve' => isset($_POST['auto_approve']) ? 1 : 0,
    ];
    $typeModel->create($data);
    header('Location: ../controllers/LeaveTypeController.php');
    exit();
}

// other actions such as edit/delete could be added later
