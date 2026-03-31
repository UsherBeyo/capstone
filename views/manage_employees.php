<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/database.php';
require_once '../helpers/Auth.php';
Auth::requireLogin('login.php');
require_once '../helpers/DateHelper.php';
require_once '../helpers/Pagination.php';

if ($_SESSION['role'] !== 'admin') {
    die("Access denied");
}

$db = (new Database())->connect();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$employees = $db->query("SELECT e.*, u.email, u.role FROM employees e JOIN users u ON e.user_id = u.id")->fetchAll(PDO::FETCH_ASSOC);

$employeeSearch = trim((string)($_GET['q'] ?? ''));
$historySearch = trim((string)($_GET['history_q'] ?? ''));

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

$employees = pagination_filter_array($employees, $employeeSearch, [
    function ($e) { return trim(($e['first_name'] ?? '') . ' ' . ($e['middle_name'] ?? '') . ' ' . ($e['last_name'] ?? '')); },
    'email', 'role', 'department', 'position', 'status'
]);

$employeesPagination = paginate_array($employees, (int)($_GET['page'] ?? 1), 12);
$employees = $employeesPagination['items'];

$historyPagination = null;
if (is_array($historyEmployee)) {
    $historyEmployee = pagination_filter_array($historyEmployee, $historySearch, [
        'leave_type_name', 'leave_type', 'status', 'workflow_status', 'manager_comments', 'start_date', 'end_date'
    ]);
    $historyPagination = paginate_array($historyEmployee, (int)($_GET['history_page'] ?? 1), 10);
    $historyEmployee = $historyPagination['items'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <base href="<?= htmlspecialchars(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/', ENT_QUOTES, 'UTF-8'); ?>">
    <title>Manage Employees</title>
    <link rel="stylesheet" href="../assets/css/styles.css">

    <style>
        .employee-page-shell {
            display: grid;
            gap: 24px;
        }
        .employee-list-card {
            margin-top: 24px;
        }
        .employee-list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .employee-list-meta {
            color: var(--muted);
            font-size: 13px;
        }
        .employee-search-row {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .employee-search-row .search-input {
            flex: 1 1 280px;
            min-width: 220px;
        }
        .employee-list-card .table-wrap {
            overflow-x: auto;
            overflow-y: visible;
            padding-bottom: 8px;
            scrollbar-gutter: stable both-edges;
        }
        .employee-list-card .table-wrap::after {
            content: 'Scroll sideways to see more columns';
            display: block;
            margin-top: 10px;
            font-size: 12px;
            color: var(--muted);
        }
        .employee-table {
            width: 100%;
            min-width: 1180px;
            table-layout: fixed;
        }
        .employee-table th,
        .employee-table td {
            white-space: nowrap;
            vertical-align: middle;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .employee-table th:nth-child(1),
        .employee-table td:nth-child(1) { width: 70px; }
        .employee-table th:nth-child(2),
        .employee-table td:nth-child(2) { width: 150px; }
        .employee-table th:nth-child(3),
        .employee-table td:nth-child(3) { width: 220px; }
        .employee-table th:nth-child(4),
        .employee-table td:nth-child(4) { width: 120px; }
        .employee-table th:nth-child(5),
        .employee-table td:nth-child(5) { width: 120px; }
        .employee-table th:nth-child(6),
        .employee-table td:nth-child(6) { width: 130px; }
        .employee-table th:nth-child(7),
        .employee-table td:nth-child(7) { width: 110px; }
        .employee-table th:nth-child(8),
        .employee-table td:nth-child(8) { width: 100px; }
        .employee-table th:nth-child(9),
        .employee-table td:nth-child(9),
        .employee-table th:nth-child(10),
        .employee-table td:nth-child(10),
        .employee-table th:nth-child(11),
        .employee-table td:nth-child(11) { width: 96px; }
        .employee-table th:nth-child(12),
        .employee-table td:nth-child(12) { width: 132px; }
        .employee-table th:last-child,
        .employee-table td:last-child {
            position: sticky;
            right: 0;
            z-index: 2;
            box-shadow: -8px 0 18px rgba(15,23,42,.04);
        }
        .employee-table thead th:last-child {
            background: #fff;
            z-index: 3;
        }
        .employee-table tbody tr:nth-child(odd) td:last-child {
            background: #fff;
        }
        .employee-table tbody tr:nth-child(even) td:last-child {
            background: #f8fafc;
        }
        .employee-avatar-thumb {
            width: 42px;
            height: 42px;
            border-radius: 999px;
            object-fit: cover;
            cursor: pointer;
            border: 2px solid #dbeafe;
            box-shadow: 0 6px 14px rgba(37,99,235,.12);
        }
        .employee-name-cell {
            min-width: 180px;
        }
        .employee-role-pill {
            display: inline-flex;
            align-items: center;
            padding: 5px 10px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }
        .employee-balance-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 84px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            font-weight: 600;
            color: #0f172a;
            white-space: nowrap;
        }
        .employee-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
            min-width: 0;
        }
        .employee-actions .profile-link {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            min-width: 96px;
            height: 32px;
            padding: 0 10px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text);
            font-weight: 600;
            font-size: 13px;
            line-height: 1;
            transition: all .18s ease;
            box-shadow: 0 4px 12px rgba(15,23,42,.04);
            flex: 0 0 auto;
            overflow: hidden;
        }
        .employee-actions .profile-link:hover {
            border-color: #bfdbfe;
            background: #eff6ff;
            color: var(--primary);
            transform: translateY(-1px);
        }
        .employee-actions .profile-link:hover {
            border-color: #bfdbfe;
            background: #eff6ff;
            color: var(--primary);
            transform: translateY(-1px);
        }
        .history-card table {
            width: 100%;
            border-collapse: collapse;
        }
        .history-card th,
        .history-card td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            text-align: left;
        }
        .history-card th {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--muted);
        }
        .employee-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 8px;
        }

        .create-employee-modal {
            width: min(980px, calc(100vw - 32px));
            max-height: calc(100vh - 32px);
            padding: 0;
            overflow: hidden;
            border-radius: 24px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, .18);
            display: flex;
            flex-direction: column;
        }
        .create-employee-modal .modal-close {
            top: 18px;
            right: 18px;
            width: 36px;
            height: 36px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #eef2ff;
            color: #1e3a8a;
            font-size: 22px;
            z-index: 3;
        }
        .create-employee-shell {
            display: grid;
            grid-template-columns: 220px 1fr;
            min-height: 100%;
            max-height: calc(100vh - 32px);
        }
        .create-employee-aside {
            background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            padding: 28px 24px;
            position: relative;
        }
        .create-employee-aside::after {
            content: '';
            position: absolute;
            inset: auto -80px -80px auto;
            width: 200px;
            height: 200px;
            border-radius: 999px;
            background: rgba(255,255,255,.12);
            filter: blur(2px);
        }
        .create-employee-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,.16);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            margin-bottom: 18px;
        }
        .create-employee-aside h3 {
            margin: 0 0 10px;
            font-size: 28px;
            line-height: 1.15;
            color: #fff;
        }
        .create-employee-aside p {
            margin: 0;
            color: rgba(255,255,255,.88);
            font-size: 14px;
            line-height: 1.7;
        }
        .create-employee-form {
            padding: 22px 22px 18px;
            background: #fff;
            overflow-y: auto;
            overscroll-behavior: contain;
        }
        .create-employee-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px 16px;
        }
        .create-employee-grid .form-group {
            margin-bottom: 0;
        }
        .create-employee-grid .form-group.full {
            grid-column: 1 / -1;
        }
        .create-employee-grid label {
            display: block;
            margin-bottom: 6px;
            font-size: 12px;
            font-weight: 700;
            color: #334155;
        }
        .create-employee-grid .form-control,
        .create-employee-grid .form-select {
            width: 100%;
            min-height: 42px;
            border-radius: 12px;
            border: 1px solid #dbe3ef;
            background: #fff;
            box-shadow: inset 0 1px 2px rgba(15,23,42,.03);
        }
        .create-employee-grid .form-control:focus,
        .create-employee-grid .form-select:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 4px rgba(37,99,235,.12);
            outline: none;
        }
        .salary-input-wrap {
            position: relative;
        }
        .salary-input-wrap .currency-badge {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 14px;
            font-weight: 800;
            color: #1d4ed8;
            pointer-events: none;
        }
        .salary-input-wrap input {
            padding-left: 42px;
        }
        .employee-modal-actions {
            position: sticky;
            bottom: 0;
            background: #fff;
            border-top: 1px solid #e5e7eb;
            margin-top: 18px;
            padding-top: 14px;
            padding-bottom: 2px;
        }

        @media (max-width: 1600px) {
            .employee-table th,
            .employee-table td {
                padding: 10px 10px;
                font-size: 13px;
            }
            .employee-table th:nth-child(2),
            .employee-table td:nth-child(2) { width: 132px; }
            .employee-table th:nth-child(3),
            .employee-table td:nth-child(3) { width: 190px; }
            .employee-table th:nth-child(12),
            .employee-table td:nth-child(12) { width: 132px; }
        }
        @media (max-width: 1500px) {
            .employee-table th:nth-child(8),
            .employee-table td:nth-child(8) { display: none; }
            .employee-table { min-width: 1080px; }
        }
        @media (max-width: 1380px) {
            .employee-table th:nth-child(7),
            .employee-table td:nth-child(7),
            .employee-table th:nth-child(6),
            .employee-table td:nth-child(6) { display: none; }
            .employee-table { min-width: 930px; }
        }
        @media (max-width: 1260px) {
            .employee-table th:nth-child(5),
            .employee-table td:nth-child(5) { display: none; }
            .employee-table { min-width: 820px; }
        }
        @media (max-width: 1120px) {
            .employee-table th:nth-child(1),
            .employee-table td:nth-child(1),
            .employee-table th:nth-child(4),
            .employee-table td:nth-child(4) { display: none; }
            .employee-table { min-width: 720px; }
        }
        @media (max-width: 920px) {
            .responsive-admin-table {
                min-width: 100%;
                border-collapse: separate;
            }
            .employee-list-card .table-wrap::after {
                display: none;
            }
            .responsive-admin-table thead {
                display: none;
            }
            .responsive-admin-table tbody {
                display: grid;
                gap: 14px;
            }
            .responsive-admin-table tr {
                display: grid;
                gap: 10px;
                padding: 14px;
                border: 1px solid var(--border);
                border-radius: 16px;
                background: #fff;
                box-shadow: 0 10px 24px rgba(15,23,42,.05);
            }
            .responsive-admin-table td,
            .responsive-admin-table th:last-child,
            .responsive-admin-table td:last-child {
                position: static;
                box-shadow: none;
                background: transparent;
            }
            .responsive-admin-table td {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 12px;
                padding: 0;
                border-bottom: none;
                font-size: 13px;
                white-space: normal;
            }
            .responsive-admin-table td::before {
                content: attr(data-label);
                flex: 0 0 110px;
                max-width: 110px;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: .05em;
                color: var(--muted);
                font-weight: 700;
            }
            .responsive-admin-table td.employee-photo-cell,
            .responsive-admin-table td.employee-actions-cell {
                display: block;
            }
            .responsive-admin-table td.employee-photo-cell::before,
            .responsive-admin-table td.employee-actions-cell::before {
                display: block;
                margin-bottom: 8px;
                max-width: none;
            }
            .employee-actions {
                min-width: 0;
                flex-wrap: wrap;
                justify-content: flex-start;
            }
            .employee-actions .profile-link {
                width: auto;
                height: auto;
                padding: 8px 12px;
                flex: 1 1 calc(50% - 8px);
            }
            .employee-actions .profile-link span:last-child {
                display: inline !important;
            }
        }

        @media (max-width: 1100px) {
            .create-employee-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 900px) {
            .create-employee-modal {
                max-height: calc(100vh - 20px);
            }
            .create-employee-shell {
                grid-template-columns: 1fr;
                max-height: calc(100vh - 20px);
            }
            .create-employee-aside {
                padding: 18px 20px 14px;
            }
            .create-employee-form {
                padding: 18px 20px 16px;
            }
            .create-employee-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 560px) {
            .employee-search-row {
                margin-bottom: 14px;
            }
            .employee-actions .profile-link {
                flex: 1 1 100%;
            }
            .modal-content.small {
                width: min(100%, 520px);
            }
            #modalImage {
                max-width: 92% !important;
                max-height: 72% !important;
            }
        }
    </style>

    <script src="../assets/js/script.js"></script>
</head>
<body>

<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="app-main employee-page-shell">
    <?php
    $title = 'Manage Employees';
    $subtitle = 'Create, update, and organize employee records';
    $actions = ['<button id="openCreateModal" class="btn btn-primary">+ New Employee</button>'];
    include __DIR__ . '/partials/ui/page-header.php';
    ?>

    <div id="createModal" class="modal" style="display:none;">
        <div class="modal-content create-employee-modal">
            <span class="modal-close" id="closeCreateModal">&times;</span>
            <div class="create-employee-shell">
                <div class="create-employee-aside">
                    <div class="create-employee-kicker">Admin setup</div>
                    <h3>Create Employee</h3>
                    <p>Fill out the employee’s account, role, and profile details in one clean form.</p>
                </div>
                <form method="POST" action="../controllers/AdminController.php" enctype="multipart/form-data" class="create-employee-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                    <div class="create-employee-grid">
                        <div class="form-group full">
                            <label>Email</label>
                            <input type="email" name="email" required class="form-control" placeholder="employee@example.com">
                        </div>

                        <div class="form-group full">
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
                            <div class="salary-input-wrap">
                                <span class="currency-badge">₱</span>
                                <input type="number" step="0.01" name="salary" class="form-control" placeholder="0.00">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" class="form-select">
                                <option value="">Select status</option>
                                <option value="Permanent">Permanent</option>
                                <option value="JO">JO</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Civil Status</label>
                            <select name="civil_status" class="form-select">
                                <option value="">Select civil status</option>
                                <option value="Single">Single</option>
                                <option value="Married">Married</option>
                                <option value="Divorced">Divorced</option>
                            </select>
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
                            <select name="role" class="form-select">
                                <option value="employee" selected>Employee</option>
                                <option value="department_head">Department Head</option>
                                <option value="personnel">Personnel</option>
                                <option value="manager">Manager (Legacy)</option>
                                <option value="hr">HR (Legacy)</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                    </div>

                    <div class="employee-modal-actions">
                        <button type="button" class="btn btn-secondary" id="cancelCreateModal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create</button>
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
        document.getElementById('cancelCreateModal').addEventListener('click', function(){
            document.getElementById('createModal').style.display = 'none';
        });
        window.addEventListener('click', function(e){
            if(e.target == document.getElementById('createModal')) document.getElementById('createModal').style.display = 'none';
        });

    </script>

    <div class="ui-card employee-list-card ajax-fragment" data-fragment-id="employee-list" data-page-param="page" data-search-param="q">
        <div class="employee-list-header">
            <div>
                <h2>Employee List</h2>
                <div class="employee-list-meta">Manage employee profiles, balances, and quick actions in one place.</div>
            </div>
            <div class="employee-list-meta">Showing <strong><?= $employeesPagination['from']; ?>–<?= $employeesPagination['to']; ?></strong> of <strong><?= $employeesPagination['total']; ?></strong> employees</div>
        </div>
        <div class="employee-search-row">
            <div class="search-input">
                <input class="form-control live-search-input" type="text" id="empSearch" name="q" value="<?= htmlspecialchars($employeeSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search by name, email, role, department, or status...">
            </div>
        </div>
        <div class="table-wrap">
            <table class="ui-table employee-table responsive-admin-table">
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
                <td data-label="Photo" class="employee-photo-cell"><?php if(!empty($e['profile_pic'])): ?><img src="<?= htmlspecialchars($e['profile_pic']); ?>" class="employee-avatar-thumb" onclick="openImageModal('<?= htmlspecialchars($e['profile_pic']); ?>', '<?= htmlspecialchars(trim($e['first_name'].' '.($e['middle_name'] ?? '').' '.$e['last_name'])); ?>')"><?php else: ?><div class="employee-avatar-thumb" style="display:flex;align-items:center;justify-content:center;background:#eff6ff;color:#1d4ed8;font-weight:700;">👤</div><?php endif; ?></td>
                <td data-label="Name" class="employee-name-cell"><?= htmlspecialchars(trim($e['first_name']." ".($e['middle_name'] ?? '')." ".$e['last_name'])); ?></td>
                <td data-label="Email"><?= htmlspecialchars($e['email']); ?></td>
                <td data-label="Role"><span class="employee-role-pill"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $e['role']))); ?></span></td>
                <td data-label="Department"><?= htmlspecialchars($e['department']); ?></td>
                <td data-label="Position"><?= htmlspecialchars($e['position'] ?? '—'); ?></td>
                <td data-label="Salary"><?= ($e['salary'] !== null && $e['salary'] !== '') ? number_format((float)$e['salary'], 2) : '—'; ?></td>
                <td data-label="Status"><?= htmlspecialchars($e['status'] ?? '—'); ?></td>
                <td data-label="Vacational"><span class="employee-balance-chip"><?= isset($e['annual_balance']) ? number_format($e['annual_balance'],3) : '0.000'; ?></span></td>
                <td data-label="Sick"><span class="employee-balance-chip"><?= isset($e['sick_balance']) ? number_format($e['sick_balance'],3) : '0.000'; ?></span></td>
                <td data-label="Force"><span class="employee-balance-chip"><?= isset($e['force_balance']) ? $e['force_balance'] : 0; ?></span></td>
                <td data-label="Actions" class="employee-actions-cell">
                    <div class="employee-actions">
                                <a href="employee_profile.php?id=<?= $e['id']; ?>" title="View profile" class="profile-link">
                            <span aria-hidden="true">👁️</span>
                            <span>View</span>
                        </a>
                        <a href="edit_employee.php?id=<?= $e['id']; ?>" title="Edit user" class="profile-link">
                            <span aria-hidden="true">✏️</span>
                            <span>Edit</span>
                        </a>
                        <a href="employee_profile.php?export=leave_card&id=<?= $e['id']; ?>" title="Export leave card" class="profile-link">
                            <span aria-hidden="true">🗄️</span>
                            <span>Export</span>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?= pagination_render($employeesPagination, 'page'); ?>
    </div>

    <?php if(!empty($historyEmployee) || (isset($_GET['view_history']) && $historyPagination !== null)): ?>
    <div class="ui-card history-card ajax-fragment" data-fragment-id="employee-history" data-page-param="history_page" data-search-param="history_q">
        <h3>Leave History for Employee</h3>
        <div class="fragment-toolbar">
            <div class="search-input">
                <input class="form-control live-search-input" type="text" name="history_q" value="<?= htmlspecialchars($historySearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search leave history...">
            </div>
            <div class="fragment-summary">Showing <?= $historyPagination['from'] ?? 0; ?>–<?= $historyPagination['to'] ?? 0; ?> of <?= $historyPagination['total'] ?? 0; ?> history rows</div>
        </div>
        <div class="table-wrap">
        <table class="ui-table">
            <tr><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Workflow</th><th>Comments</th></tr>
            <?php foreach($historyEmployee as $h): ?>
            <tr>
                <td><?= htmlspecialchars($h['leave_type_name'] ?? $h['leave_type']); ?></td>
                <td><?= htmlspecialchars(app_format_date_range($h['start_date'] ?? '', $h['end_date'] ?? '')); ?></td>
                <td><?= number_format((float)($h['total_days'] ?? 0), 3); ?></td>
                <td><?= ucfirst($h['status']); ?></td>
                <td><?= htmlspecialchars($h['workflow_status'] ?? '—'); ?></td>
                <td><?= htmlspecialchars($h['manager_comments'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?= $historyPagination ? pagination_render($historyPagination, 'history_page', ['view_history' => (int)($_GET['view_history'] ?? 0), 'page' => (int)($_GET['page'] ?? 1)]) : ''; ?>
    </div>
    <?php endif; ?>

</div>

<script>
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
