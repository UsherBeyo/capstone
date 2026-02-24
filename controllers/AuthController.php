<?php
session_start();

require_once '../config/database.php';
require_once '../models/User.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = (new Database())->connect();
$userModel = new User($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF validation failed.");
    }

    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    $user = $userModel->login($email, $password);

    if ($user) {

        // Secure session
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role']    = $user['role'];
        $_SESSION['email']   = $user['email'];

        // if there is an employee record linked, store its id too
        $stmt = $db->prepare("SELECT id FROM employees WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($emp) {
            $_SESSION['emp_id'] = $emp['id'];
        }

        header("Location: ../views/dashboard.php");
        exit();

    } else {
        header("Location: ../views/login.php?error=1");
        exit();
    }
}