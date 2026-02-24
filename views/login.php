<?php
session_start();

if (!empty($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - Leave System</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body style="justify-content:center;align-items:center;height:100vh;">

<div class="card" style="width:350px;">
    <h2>Login</h2>

    <?php if(isset($_GET['error'])): ?>
        <p style="color:red;">Invalid credentials</p>
    <?php endif; ?>

    <form action="../controllers/AuthController.php" method="POST">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">

        <label>Email</label>
        <input type="email" name="email" required>

        <label>Password</label>
        <input type="password" name="password" required>

        <br><br>
        <button type="submit">Login</button>
    </form>
</div>

</body>
</html>