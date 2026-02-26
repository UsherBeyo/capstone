<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/database.php';

if ($_SESSION['role'] !== 'admin') {
    die("Access denied");
}

$db = (new Database())->connect();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$employees = $db->query("SELECT e.*, u.email FROM employees e JOIN users u ON e.user_id = u.id")->fetchAll(PDO::FETCH_ASSOC);

$managers = $db->query("SELECT e.id, e.first_name, e.last_name FROM employees e JOIN users u ON e.user_id = u.id WHERE u.role = 'manager'")->fetchAll(PDO::FETCH_ASSOC);

// if admin requests to view an employee's leave history
$historyEmployee = null;
if (isset($_GET['view_history'])) {
    $eid = intval($_GET['view_history']);
    $stmt = $db->prepare("SELECT lr.*, e.first_name, e.last_name, COALESCE(lt.name, lr.leave_type) AS leave_type_name FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id WHERE lr.employee_id = ?");
    $stmt->execute([$eid]);
    $historyEmployee = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// export leave budget CSV
if (isset($_GET['export_budget'])) {
    $eid = intval($_GET['export_budget']);
    $stmt = $db->prepare("SELECT first_name, last_name, annual_balance, sick_balance, force_balance FROM employees WHERE id = ?");
    $stmt->execute([$eid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="budget_' . $eid . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array_keys($row));
        fputcsv($out, $row);
        exit();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Employees</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="content">

    <button id="openCreateModal" class="btn" style="margin-bottom:20px;">+ New Employee</button>

    <!-- Compact modal for creating employee -->
    <div id="createModal" class="modal" style="display:none;">
        <div class="modal-content small">
            <span class="modal-close" id="closeCreateModal">&times;</span>
            <h3>Create Employee</h3>
            <form method="POST" action="../controllers/AdminController.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                <label>Email</label>
                <input type="email" name="email" required>
                <label>Profile Picture</label>
                <input type="file" name="profile_pic" accept="image/*">
                <label>First Name</label>
                <input type="text" name="first_name" required>
                <label>Last Name</label>
                <input type="text" name="last_name" required>
                <label>Department</label>
                <input type="text" name="department" required>
                <label>Position</label>
                <input type="text" name="position">
                <label>Status</label>
                <input type="text" name="status">
                <label>Civil Status</label>
                <input type="text" name="civil_status">
                <label>Entrance to Duty</label>
                <input type="date" name="entrance_to_duty">
                <label>Unit</label>
                <input type="text" name="unit">
                <label>GSIS Policy No.</label>
                <input type="text" name="gsis_policy_no">
                <label>National Reference Card No.</label>
                <input type="text" name="national_reference_card_no">
                <label>Password</label>
                <input type="password" name="password" required placeholder="Set temporary password">
                <label>Role</label>
                <select name="role">
                    <option value="employee" selected>Employee</option>
                    <option value="manager">Manager</option>
                    <option value="hr">HR</option>
                </select>
                <label>Assign Manager</label>
                <select name="manager_id">
                    <option value="">None</option>
                    <?php foreach($managers as $m): ?>
                        <option value="<?= $m['id']; ?>"><?= $m['first_name']." ".$m['last_name']; ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="text-align:right;">
                    <button type="submit">Create</button>
                </div>
            </form>
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
    </script>

    <div class="card" style="margin-top:30px;">
        <h2>Employee List</h2>
        <input type="text" id="empSearch" placeholder="Search employees..." style="margin-bottom:10px;">
        <table>
            <tr>
                <th>Photo</th>
                <th>Name</th>
                <th>Email</th>
                <th>Department</th>
                <th>Position</th>
                <th>Status</th>
                <th>Annual</th>
                <th>Sick</th>
                <th>Force</th>
                <th>Action</th>
            </tr>

            <?php foreach($employees as $e): ?>
            <tr>
                <td><?php if(!empty($e['profile_pic'])) echo "<img src='".$e['profile_pic']."' style='width:40px;height:40px;border-radius:50%;cursor:pointer;' onclick=\"openImageModal('".$e['profile_pic']."', '".htmlspecialchars($e['first_name'].' '.$e['last_name'])."')\">"; ?></td>
                <td><?= $e['first_name']." ". $e['last_name']; ?></td>
                <td><?= $e['email']; ?></td>
                <td><?= $e['department']; ?></td>
                <td><?= htmlspecialchars($e['position'] ?? ''); ?></td>
                <td><?= htmlspecialchars($e['status'] ?? ''); ?></td>
                <td><?= isset($e['annual_balance']) ? $e['annual_balance'] : 0; ?></td>
                <td><?= isset($e['sick_balance']) ? $e['sick_balance'] : 0; ?></td>
                <td><?= isset($e['force_balance']) ? $e['force_balance'] : 0; ?></td>
                <td>
                    <a href="employee_profile.php?id=<?= $e['id']; ?>" title="View profile" class="profile-link">&#128100;</a>
                    &nbsp;
                    <a href="edit_employee.php?id=<?= $e['id']; ?>" title="Edit" class="profile-link">✏️</a>
                </td>
            </tr>
            <?php endforeach; ?>

        </table>
    </div>

    <?php if(!empty($historyEmployee)): ?>
    <div class="card" style="margin-top:30px;">
        <h3>Leave History for Employee</h3>
        <table>
            <tr><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Comments</th></tr>
            <?php foreach($historyEmployee as $h): ?>
            <tr>
                <td><?= htmlspecialchars($h['leave_type_name'] ?? $h['leave_type']); ?></td>
                <td><?= $h['start_date'].' to '.$h['end_date']; ?></td>
                <td><?= intval($h['total_days']); ?></td>
                <td><?= ucfirst($h['status']); ?></td>
                <td><?= htmlspecialchars($h['manager_comments'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

</div>

<script>
    // filter employee table based on search box
    document.getElementById('empSearch').addEventListener('keyup', function(){
        var filter = this.value.toLowerCase();
        var rows = document.querySelectorAll('table tr');
        rows.forEach(function(row, index){
            if(index === 0) return; // skip header row
            var text = row.textContent.toLowerCase();
            row.style.display = text.indexOf(filter) > -1 ? '' : 'none';
        });
    });

    function openImageModal(src, name) {
        document.getElementById('modalImage').src = src;
        document.getElementById('modalImageName').textContent = name;
        document.getElementById('imageModal').style.display = 'flex';
    }

    function closeImageModal() {
        document.getElementById('imageModal').style.display = 'none';
    }

    document.getElementById('imageModal').addEventListener('click', function(e) {
        if(e.target === this) closeImageModal();
    });
</script>

<div id="imageModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:2000;justify-content:center;align-items:center;flex-direction:column;">
    <span style="color:white;font-size:20px;;margin-bottom:20px;" id="modalImageName"></span>
    <img id="modalImage" style="max-width:80%;max-height:80%;border-radius:8px;">
    <button onclick="closeImageModal()" style="margin-top:20px;padding:10px 20px;background:#007bff;color:white;border:none;border-radius:4px;cursor:pointer;">Close</button>
</div>

</body>
</html>
