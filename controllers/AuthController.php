<?php
session_start();

require_once '../config/database.php';
require_once '../models/User.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = (new Database())->connect();
$userModel = new User($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    $user = $userModel->login($email, $password);

    if ($user) {

        // Secure session
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role']    = $user['role'];
        $_SESSION['email']   = $user['email'];

        header("Location: ../views/dashboard.php");
        exit();

    } else {
        header("Location: ../views/login.php?error=1");
        exit();
    }
}