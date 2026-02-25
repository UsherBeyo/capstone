<?php
session_start();
require_once '../config/database.php';
if (!in_array($_SESSION['role'], ['admin','hr'])) {
    die("Access denied");
}

// ensure we always have types available even if view called directly
$db = (new Database())->connect();
$types = $db->query("SELECT * FROM leave_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Leave Types</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<div class="content">
    <h2>Leave Types</h2>
    <div class="card">
        <table border="1" width="100%">
            <tr><th>ID</th><th>Name</th><th>Deduct?</th><th>Requires Approval</th><th>Max/yr</th><th>Auto approve</th></tr>
            <?php foreach ($types as $t): ?>
            <tr>
                <td><?= $t['id']; ?></td>
                <td><?= htmlspecialchars($t['name']); ?></td>
                <td><?= $t['deduct_balance'] ? 'Yes' : 'No'; ?></td>
                <td><?= $t['requires_approval'] ? 'Yes' : 'No'; ?></td>
                <td><?= $t['max_days_per_year'] ?: '-'; ?></td>
                <td><?= $t['auto_approve'] ? 'Yes' : 'No'; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <div class="card" style="margin-top:20px;">
        <h3>Add New Type</h3>
        <form method="POST" action="../controllers/LeaveTypeController.php">
            <input type="hidden" name="action" value="create">
            <label>Name</label><input type="text" name="name" required><br>
            <label><input type="checkbox" name="deduct_balance" checked> Deduct balance</label><br>
            <label><input type="checkbox" name="requires_approval" checked> Requires approval</label><br>
            <label>Max days per year</label><input type="number" step="0.01" name="max_days_per_year"><br>
            <label><input type="checkbox" name="auto_approve"> Auto approve</label><br>
            <button type="submit">Create</button>
        </form>
    </div>
</div>
</body>
</html>