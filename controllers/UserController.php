<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../views/login.php');
    exit();
}

$db = (new Database())->connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF validation failed.");
    }

    if ($_POST['action'] === 'change_password') {
        $userId = $_SESSION['user_id'];
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && password_verify($_POST['current'], $row['password'])) {
            $hash = password_hash($_POST['new'], PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $userId]);
            $_SESSION['message'] = "Password updated";
        } else {
            $_SESSION['message'] = "Current password incorrect";
        }
        header('Location: ../views/dashboard.php');
        exit();
    }
}
