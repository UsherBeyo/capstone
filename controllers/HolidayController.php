<?php
session_start();
require_once '../config/database.php';
require_once '../models/Holiday.php';

if (!in_array($_SESSION['role'], ['admin','manager','hr'])) {
    die("Access denied");
}

$db = (new Database())->connect();
$holidayModel = new Holiday($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF validation failed.");
    }
    if (isset($_POST['add'])) {
        $date = $_POST['date'];
        $desc = trim($_POST['description']);
        $type = $_POST['type'] ?? 'Other';
        $holidayModel->add($date, $desc, $type);
        $msg = 'Holiday+added';
    }
    if (isset($_POST['update'])) {
        $id = intval($_POST['id']);
        $date = $_POST['date'];
        $desc = trim($_POST['description']);
        $type = $_POST['type'] ?? 'Other';
        $holidayModel->update($id, $date, $desc, $type);
        $msg = 'Holiday+updated';
    }
    if (isset($_POST['delete'])) {
        $id = $_POST['id'];
        $holidayModel->delete($id);
        $msg = 'Holiday+removed';
    }
    $redir = '../views/holidays.php';
    if (!empty($msg)) {
        $redir .= '?toast_success=' . $msg;
    }
    header("Location: $redir");
    exit();
}
