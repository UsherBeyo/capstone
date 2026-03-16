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

$employees = $db->query("SELECT e.*, u.email, u.role FROM employees e JOIN users u ON e.user_id = u.id")->fetchAll(PDO::FETCH_ASSOC);

$departments = $db->query("SELECT * FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$managers = $db->query("
    SELECT e.id, e.first_name, e.middle_name, e.last_name
    FROM employees e
    JOIN users u ON e.user_id = u.id
    WHERE u.role IN ('manager','department_head')
")->fetchAll(PDO::FETCH_ASSOC);

$historyEmployee = null;
if (isset($_GET['view_history'])) {
    $eid = intval($_GET['view_history']);
    $stmt = $db->prepare("SELECT lr.*, e.first_name, e.last_name, COALESCE(lt.name, lr.leave_type) AS leave_type_name FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id WHERE lr.employee_id = ?");
    $stmt->execute([$eid]);
    $historyEmployee = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

<div class="app-main">
    <?php
    $title = 'Manage Employees';
    $actions = ['<button id="openCreateModal" class="btn btn-primary">+ New Employee</button>'];
    include __DIR__ . '/partials/ui/page-header.php';
    ?>

    <div id="createModal" class="modal" style="display:none;">
        <div class="modal-content small">
            <span class="modal-close" id="closeCreateModal">&times;</span>
            <h3>Create Employee</h3>
            <form method="POST" action="../controllers/AdminController.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required class="form-control">
                </div>

                <div class="form-group">
                    <label>Profile Picture</label>
                    <input type="file" name="profile_pic" accept="image/*" class="form-control">
                </div>

                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" name="first_name" required class="form-control">
                </div>

                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" name="middle_name" class="form-control">
                </div>

                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" name="last_name" required class="form-control">
                </div>

                <div class="form-group">
                    <label>Department</label>
                    <select name="department_id" required class="form-select">
                        <option value="">Select Department</option>
                        <?php foreach($departments as $d): ?>
                            <option value="<?= $d['id']; ?>"><?= htmlspecialchars($d['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Position</label>
                    <input type="text" name="position" class="form-control">
                </div>

                <div class="form-group">
                    <label>Salary</label>
                    <input type="number" step="0.01" name="salary" class="form-control">
                </div>

                <div class="form-group">
                    <label>Status</label>
                    <input type="text" name="status" class="form-control">
                </div>

                <div class="form-group">
                    <label>Civil Status</label>
                    <input type="text" name="civil_status" class="form-control">
                </div>

                <div class="form-group">
                    <label>Entrance to Duty</label>
                    <input type="date" name="entrance_to_duty" class="form-control">
                </div>

                <div class="form-group">
                    <label>Unit</label>
                    <input type="text" name="unit" class="form-control">
                </div>

                <div class="form-group">
                    <label>GSIS Policy No.</label>
                    <input type="text" name="gsis_policy_no" class="form-control">
                </div>

                <div class="form-group">
                    <label>National Reference Card No.</label>
                    <input type="text" name="national_reference_card_no" class="form-control">
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required placeholder="Set temporary password" class="form-control">
                </div>

                <div class="form-group">
                    <label>Role</label>
                    <select name="role" id="roleSelect" class="form-select">
                        <option value="employee" selected>Employee</option>
                        <option value="department_head">Department Head</option>
                        <option value="personnel">Personnel</option>
                        <option value="manager">Manager (Legacy)</option>
                        <option value="hr">HR (Legacy)</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

                <div id="deptHeadField" style="display:none;">
                    <label>Department Head Of (auto-assigned based on department)</label>
                    <p style="font-size:12px;color:#666;">This will be set automatically when department is selected.</p>
                </div>

                <div class="form-group">
                    <label>Assign Department Head</label>
                    <select name="manager_id" class="form-select">
                        <option value="">None</option>
                        <?php foreach($managers as $m): ?>
                            <option value="<?= $m['id']; ?>">
                                <?= htmlspecialchars(trim($m['first_name'].' '.($m['middle_name'] ?? '').' '.$m['last_name'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

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

        document.getElementById('roleSelect').addEventListener('change', function(){
            const deptHeadField = document.getElementById('deptHeadField');
            if(this.value === 'department_head'){
                deptHeadField.style.display = 'block';
            } else {
                deptHeadField.style.display = 'none';
            }
        });
    </script>

    <div class="ui-card" style="margin-top:30px;">
        <h2>Employee List</h2>
        <div class="search-input" style="margin: 20px 0;">
            <input class="form-control" type="text" id="empSearch" placeholder="Search employees...">
        </div>
        <div class="table-wrap" style="margin-top: 16px;">
            <table class="ui-table">
                <thead>
                <tr>
                    <th>Photo</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Department</th>
                    <th>Position</th>
                    <th>Salary</th>
                    <th>Status</th>
                    <th>Vacational</th>
                    <th>Sick</th>
                    <th>Force</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>

            <?php foreach($employees as $e): ?>
            <tr>
                <td><?php if(!empty($e['profile_pic'])) echo "<img src='".$e['profile_pic']."' style='width:40px;height:40px;border-radius:50%;cursor:pointer;' onclick=\"openImageModal('".$e['profile_pic']."', '".htmlspecialchars(trim($e['first_name'].' '.($e['middle_name'] ?? '').' '.$e['last_name']))."')\">"; ?></td>
                <td><?= htmlspecialchars(trim($e['first_name']." ".($e['middle_name'] ?? '')." ".$e['last_name'])); ?></td>
                <td><?= htmlspecialchars($e['email']); ?></td>
                <td><?= htmlspecialchars($e['role']); ?></td>
                <td><?= htmlspecialchars($e['department']); ?></td>
                <td><?= htmlspecialchars($e['position'] ?? ''); ?></td>
                <td><?= ($e['salary'] !== null && $e['salary'] !== '') ? number_format((float)$e['salary'], 2) : '—'; ?></td>
                <td><?= htmlspecialchars($e['status'] ?? ''); ?></td>
                <td><?= isset($e['annual_balance']) ? number_format($e['annual_balance'],3) : '0.000'; ?></td>
                <td><?= isset($e['sick_balance']) ? number_format($e['sick_balance'],3) : '0.000'; ?></td>
                <td><?= isset($e['force_balance']) ? $e['force_balance'] : 0; ?></td>
                <td>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <a href="employee_profile.php?id=<?= $e['id']; ?>" title="View profile" class="profile-link" style="display: flex; align-items: center; gap: 4px;">
                            <span>&#128100;</span>
                            <span>View</span>
                        </a>
                        <a href="edit_employee.php?id=<?= $e['id']; ?>" title="Edit" class="profile-link" style="display: flex; align-items: center; gap: 4px;">
                            <span>✏️</span>
                            <span>Edit</span>
                        </a>
                        <a href="employee_profile.php?export=leave_card&id=<?= $e['id']; ?>" title="Export leave card" class="profile-link" style="display: flex; align-items: center; gap: 4px;">
                            <span>📊</span>
                            <span>Export</span>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if(!empty($historyEmployee)): ?>
    <div class="ui-card" style="margin-top:30px;">
        <h3>Leave History for Employee</h3>
        <table>
            <tr><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Workflow</th><th>Comments</th></tr>
            <?php foreach($historyEmployee as $h): ?>
            <tr>
                <td><?= htmlspecialchars($h['leave_type_name'] ?? $h['leave_type']); ?></td>
                <td><?= htmlspecialchars(($h['start_date'] ?? '').' to '.($h['end_date'] ?? '')); ?></td>
                <td><?= number_format((float)($h['total_days'] ?? 0), 3); ?></td>
                <td><?= ucfirst($h['status']); ?></td>
                <td><?= htmlspecialchars($h['workflow_status'] ?? '—'); ?></td>
                <td><?= htmlspecialchars($h['manager_comments'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

</div>

<script>
document.getElementById('empSearch').addEventListener('keyup', function(){
    var filter = this.value.toLowerCase();
    var rows = document.querySelectorAll('table tr');
    rows.forEach(function(row, index){
        if(index === 0) return;
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
    <span style="color:white;font-size:20px;margin-bottom:24px;" id="modalImageName"></span>
    <img id="modalImage" style="max-width:80%;max-height:80%;border-radius:8px;">
    <button onclick="closeImageModal()" style="margin-top:20px;padding:10px 20px;background:var(--primary);color:white;border:none;border-radius:4px;cursor:pointer;">Close</button>
</div>

</body>
</html>
