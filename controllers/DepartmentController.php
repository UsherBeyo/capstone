<?php
session_start();

require_once '../config/database.php';
require_once '../models/Department.php';

if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Access denied");
}

$db = (new Database())->connect();
$departmentModel = new Department($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF validation failed.");
    }

    if (isset($_POST['create_department'])) {
        $name = trim($_POST['name']);
        if (empty($name)) {
            header("Location: ../views/manage_departments.php?toast_error=Department+name+required");
            exit();
        }
        $departmentModel->create($name);
        header("Location: ../views/manage_departments.php?toast_success=Department+created");
        exit();
    }

    if (isset($_POST['update_department'])) {
        $id = intval($_POST['id']);
        $name = trim($_POST['name']);
        if (empty($name)) {
            header("Location: ../views/manage_departments.php?toast_error=Department+name+required");
            exit();
        }
        $departmentModel->update($id, $name);
        header("Location: ../views/manage_departments.php?toast_success=Department+updated");
        exit();
    }

    if (isset($_POST['delete_department'])) {
        $id = intval($_POST['id']);
        if ($departmentModel->delete($id)) {
            header("Location: ../views/manage_departments.php?toast_success=Department+deleted");
        } else {
            header("Location: ../views/manage_departments.php?toast_error=Cannot+delete+department+in+use");
        }
        exit();
    }
}