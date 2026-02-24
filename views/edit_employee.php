<?php
session_start();
require_once '../config/database.php';

if ($_SESSION['role'] !== 'admin') {
    die("Access denied");
}

if (!isset($_GET['id'])) {
    header("Location: manage_employees.php");
    exit();
}

$db = (new Database())->connect();
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$_GET['id']]);
$e = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$e) {
    header("Location: manage_employees.php");
    exit();
}

$managers = $db->query("SELECT e.id, e.first_name, e.last_name
    FROM employees e
    JOIN users u ON e.user_id = u.id
    WHERE u.role = 'manager'")->fetchAll(PDO::FETCH_ASSOC);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Employee</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="content">
    <div class="card">
        <h2>Edit Employee</h2>
        <form method="POST" action="../controllers/AdminController.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="update_employee" value="1">
            <input type="hidden" name="employee_id" value="<?= $e['id']; ?>">

            <?php if(!empty($e['profile_pic'])): ?>
                <img src="<?= $e['profile_pic']; ?>" alt="Profile" style="width:100px; height:100px; object-fit:cover; border-radius:50%;"><br>
            <?php endif; ?>
            <label>Profile Picture</label>
            <input type="file" name="profile_pic" accept="image/*">
            <label>First Name</label>
            <input type="text" name="first_name" value="<?= htmlspecialchars($e['first_name']); ?>" required>
            <label>Last Name</label>
            <input type="text" name="last_name" value="<?= htmlspecialchars($e['last_name']); ?>" required>
            <label>Department</label>
            <input type="text" name="department" value="<?= htmlspecialchars($e['department']); ?>" required>
            <label>Annual Balance</label>
            <input type="number" step="0.01" name="annual_balance" value="<?= $e['annual_balance']; ?>">
            <label>Sick Balance</label>
            <input type="number" step="0.01" name="sick_balance" value="<?= $e['sick_balance']; ?>">
            <label>Force Balance</label>
            <input type="number" name="force_balance" value="<?= $e['force_balance']; ?>">
            <label>Assign Manager</label>
            <select name="manager_id">
                <option value="">None</option>
                <?php foreach($managers as $m): ?>
                    <option value="<?= $m['id']; ?>" <?php if($e['manager_id']==$m['id']) echo 'selected'; ?>>
                        <?= $m['first_name'].' '.$m['last_name']; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Save changes</button>
        </form>
    </div>
</div>
</body>
</html>