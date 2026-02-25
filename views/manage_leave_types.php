<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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
            <tr><th>ID</th><th>Name</th><th>Deduct?</th><th>Requires Approval</th><th>Max/yr</th><th>Auto approve</th><th>Actions</th></tr>
            <?php foreach ($types as $t): ?>
            <tr>
                <td><?= $t['id']; ?></td>
                <td><?= htmlspecialchars($t['name']); ?></td>
                <td><?= $t['deduct_balance'] ? 'Yes' : 'No'; ?></td>
                <td><?= $t['requires_approval'] ? 'Yes' : 'No'; ?></td>
                <td><?= $t['max_days_per_year'] ?: '-'; ?></td>
                <td><?= $t['auto_approve'] ? 'Yes' : 'No'; ?></td>
                <td>
                    <button onclick="openEditModal(<?= $t['id']; ?>, '<?= htmlspecialchars($t['name']); ?>', <?= $t['deduct_balance']; ?>, <?= $t['requires_approval']; ?>, <?= $t['max_days_per_year'] ?: 'null'; ?>, <?= $t['auto_approve']; ?>)">Edit</button>
                    <form method="POST" action="../controllers/LeaveTypeController.php" style="display:inline;" onsubmit="return confirm('Delete this type?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="type_id" value="<?= $t['id']; ?>">
                        <button type="submit">Delete</button>
                    </form>
                </td>
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

<div id="editModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;justify-content:center;align-items:center;">
    <div style="background:white;padding:30px;border-radius:8px;width:90%;max-width:500px;">
        <h3>Edit Leave Type</h3>
        <form method="POST" action="../controllers/LeaveTypeController.php">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="type_id" id="editTypeId">
            <label>Name</label><input type="text" name="name" id="editName" required><br>
            <label><input type="checkbox" name="deduct_balance" id="editDeduct"> Deduct balance</label><br>
            <label><input type="checkbox" name="requires_approval" id="editApproval"> Requires approval</label><br>
            <label>Max days per year</label><input type="number" step="0.01" name="max_days_per_year" id="editMax"><br>
            <label><input type="checkbox" name="auto_approve" id="editAuto"> Auto approve</label><br>
            <button type="submit">Save</button>
            <button type="button" onclick="closeEditModal()">Cancel</button>
        </form>
    </div>
</div>

<script>
function openEditModal(id, name, deduct, approval, max, auto) {
    document.getElementById('editTypeId').value = id;
    document.getElementById('editName').value = name;
    document.getElementById('editDeduct').checked = deduct == 1;
    document.getElementById('editApproval').checked = approval == 1;
    document.getElementById('editMax').value = max || '';
    document.getElementById('editAuto').checked = auto == 1;
    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}
</script>
</body>
</html>