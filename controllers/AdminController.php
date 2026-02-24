<?php
session_start();

require_once '../config/database.php';
require_once '../models/User.php';

if ($_SESSION['role'] !== 'admin') {
    die("Access denied");
}

$db = (new Database())->connect();
$userModel = new User($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (!isset($_POST['csrf_token']) || 
        $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF validation failed.");
    }

    $email = trim($_POST['email']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $department = trim($_POST['department']);
    $manager_id = !empty($_POST['manager_id']) ? $_POST['manager_id'] : NULL;
    $password = trim($_POST['password']);
    //$temp_password = "Temp@123";
    $activation_token = bin2hex(random_bytes(32));

    try {

        $db->beginTransaction();

        // 1️⃣ Create user
        $stmt = $db->prepare("
            INSERT INTO employees 
            (user_id, first_name, last_name, department, manager_id, leave_balance)
            VALUES (?, ?, ?, ?, ?, 20)
        ");

        $stmt->execute([
            $user_id,
            $first_name,
            $last_name,
            $department,
            $manager_id// <-- Missing manager_id? 
        ]);                                         

        $user_id = $db->lastInsertId();

        // 2️⃣ Create employee record
        $stmt = $db->prepare("
            INSERT INTO employees 
            (user_id, first_name, last_name, department, manager_id, leave_balance)
            VALUES (?, ?, ?, ?, ?, 20)
        ");

        $stmt->execute([
            $user_id,
            $first_name,
            $last_name,
            $department,
            $manager_id
        ]);

        $db->commit();

        header("Location: ../views/manage_employees.php?success=1");
        exit();

    } catch (Exception $e) {
        $db->rollBack();
        echo "Error: " . $e->getMessage();
    }
}