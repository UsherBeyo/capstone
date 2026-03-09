<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/database.php';

if ($_SESSION['role'] !== 'admin') {
    die("Access denied");
}

$db = (new Database())->connect();
require_once '../models/Department.php';
$departmentModel = new Department($db);

$departments = $departmentModel->getAll();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Departments</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="content">

    <button id="openCreateModal" class="btn" style="margin:48px 0 0 0;">+ New Department</button>

    <div id="createModal" class="modal" style="display:none;">
        <div class="modal-content small">
            <span class="modal-close" id="closeCreateModal">&times;</span>
            <h3>Create Department</h3>
            <form method="POST" action="../controllers/DepartmentController.php">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                <label>Name</label>
                <input type="text" name="name" required>
                <div style="text-align:right;">
                    <button type="submit" name="create_department">Create</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card" style="margin-top:30px;">
        <h2>Departments</h2>
        <table>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Action</th>
            </tr>
            <?php foreach($departments as $d): ?>
            <tr>
                <td><?= $d['id']; ?></td>
                <td><?= htmlspecialchars($d['name']); ?></td>
                <td>
                    <button class="edit-btn" data-id="<?= $d['id']; ?>" data-name="<?= htmlspecialchars($d['name']); ?>">Edit</button>
                    <form method="POST" action="../controllers/DepartmentController.php" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="id" value="<?= $d['id']; ?>">
                        <button type="submit" name="delete_department" onclick="return confirm('Delete this department?')">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div id="editModal" class="modal" style="display:none;">
        <div class="modal-content small">
            <span class="modal-close" id="closeEditModal">&times;</span>
            <h3>Edit Department</h3>
            <form method="POST" action="../controllers/DepartmentController.php">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="id" id="editId">
                <label>Name</label>
                <input type="text" name="name" id="editName" required>
                <div style="text-align:right;">
                    <button type="submit" name="update_department">Update</button>
                </div>
            </form>
        </div>
    </div>

</div>

<script>
document.getElementById('openCreateModal').addEventListener('click', function(e){
    e.preventDefault();
    document.getElementById('createModal').style.display = 'flex';
});
document.getElementById('closeCreateModal').addEventListener('click', function(){
    document.getElementById('createModal').style.display = 'none';
});
window.addEventListener('click', function(e){
    if(e.target == document.getElementById('createModal')) document.getElementById('createModal').style.display = 'none';
});

document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function(){
        const id = this.getAttribute('data-id');
        const name = this.getAttribute('data-name');
        document.getElementById('editId').value = id;
        document.getElementById('editName').value = name;
        document.getElementById('editModal').style.display = 'flex';
    });
});
document.getElementById('closeEditModal').addEventListener('click', function(){
    document.getElementById('editModal').style.display = 'none';
});
window.addEventListener('click', function(e){
    if(e.target == document.getElementById('editModal')) document.getElementById('editModal').style.display = 'none';
});
</script>

</body>
</html>