<?php
session_start();
require_once '../config/database.php';

if ($_SESSION['role'] !== 'admin') {
    die("Access denied");
}

$db = (new Database())->connect();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$employees = $db->query("
    SELECT e.*, u.email 
    FROM employees e
    JOIN users u ON e.user_id = u.id
")->fetchAll(PDO::FETCH_ASSOC);

$managers = $db->query("
    SELECT e.id, e.first_name, e.last_name
    FROM employees e
    JOIN users u ON e.user_id = u.id
    WHERE u.role = 'manager'
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Employees</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div class="sidebar">
    <h3>Admin Panel</h3>
    <a href="dashboard.php">Dashboard</a>
    <a href="#">Manage Employees</a>
    <a href="../controllers/logout.php">Logout</a>
</div>

<div class="content">

    <div class="card">
        <h2>Create Employee</h2>

        <form method="POST" action="../controllers/AdminController.php">

            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">

            <label>Email</label>
            <input type="email" name="email" required>

            <label>First Name</label>
            <input type="text" name="first_name" required>

            <label>Last Name</label>
            <input type="text" name="last_name" required>

            <label>Department</label>
            <input type="text" name="department" required>

            <label>Password</label>
            <input type="password" name="password" required placeholder="Set temporary password">

            <label>Assign Manager</label>
            <select name="manager_id">
                <option value="">None</option>
                <?php foreach($managers as $m): ?>
                    <option value="<?= $m['id']; ?>">
                        <?= $m['first_name']." ".$m['last_name']; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Create Employee</button>
        </form>
    </div>

    <div class="card" style="margin-top:30px;">
        <h2>Employee List</h2>

        <table>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Department</th>
                <th>Balance</th>
            </tr>

            <?php foreach($employees as $e): ?>
            <tr>
                <td><?= $e['first_name']." ".$e['last_name']; ?></td>
                <td><?= $e['email']; ?></td>
                <td><?= $e['department']; ?></td>
                <td><?= $e['leave_balance']; ?></td>
            </tr>
            <?php endforeach; ?>

        </table>
    </div>

</div>

</body>
</html>