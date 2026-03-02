<?php
session_start();

require_once '../config/database.php';
require_once '../models/User.php';
require_once '../models/Leave.php';

// ensure user is logged in
if (empty($_SESSION['role'])) {
    die("Access denied");
}

$db = (new Database())->connect();
$userModel = new User($db);
$leaveModel = new Leave($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (!isset($_POST['csrf_token']) || 
        $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF validation failed.");
    }

    // handle undertime recording (admin/hr only)
    if (isset($_POST['record_undertime'])) {
        $empId = intval($_POST['employee_id']);
        $date = $_POST['date'];
        $hours = intval($_POST['hours'] ?? 0);
        $minutes = intval($_POST['undertime_minutes'] ?? 0);
        $withPay = isset($_POST['with_pay']) ? 1 : 0;
        // permission: only admin/hr can record undertime
        if (!in_array($_SESSION['role'], ['admin','hr'])) {
            die("Access denied");
        }
        // calculate total minutes
        $totalMinutes = $hours * 60 + $minutes;
        // compute deduction
        $deduct = round($totalMinutes * 0.002, 3); // per policy with 3-decimal precision
        // get old balance
        $stmt = $db->prepare("SELECT annual_balance FROM employees WHERE id = ?");
        $stmt->execute([$empId]);
        $oldBal = floatval($stmt->fetchColumn());
        $newBal = max(0, $oldBal - $deduct);
        $db->prepare("UPDATE employees SET annual_balance = ? WHERE id = ?")->execute([$newBal, $empId]);
        // log budget change and leave_balance_logs
        $leaveModel->logBudgetChange($empId, 'Vacational', $oldBal, $newBal, $withPay ? 'undertime_paid' : 'undertime_unpaid', null, 'Undertime '.$hours.'h '.$minutes.'m');
        $stmt2 = $db->prepare("INSERT INTO leave_balance_logs (employee_id, change_amount, reason) VALUES (?, ?, ?)");
        $stmt2->execute([$empId, -1 * $deduct, $withPay ? 'undertime_paid' : 'undertime_unpaid']);

        header("Location: ../views/employee_profile.php?id=$empId&undertime=1");
        exit();
    }

    // handle update of existing employee record
    if (isset($_POST['update_employee'])) {
        $empId = $_POST['employee_id'];
        $role = $_SESSION['role'] ?? '';
        $emp_id = $_SESSION['emp_id'] ?? 0;
        
        // permission check: admin/hr/manager can update any, employees can update own
        if ($role === 'employee') {
            if ($emp_id != $empId) {
                die("You can only update your own profile");
            }
        } elseif (!in_array($role, ['admin','hr','manager'])) {
            die("Access denied");
        }

        // load existing values so we can preserve when not provided
        $rowStmt = $db->prepare("SELECT first_name, last_name, department, position, status, civil_status, entrance_to_duty, unit, gsis_policy_no, national_reference_card_no FROM employees WHERE id = ?");
        $rowStmt->execute([$empId]);
        $existing = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $first_name = isset($_POST['first_name']) ? trim($_POST['first_name']) : ($existing['first_name'] ?? '');
        $last_name = isset($_POST['last_name']) ? trim($_POST['last_name']) : ($existing['last_name'] ?? '');
        $department = isset($_POST['department']) ? trim($_POST['department']) : ($existing['department'] ?? '');
        $position = isset($_POST['position']) ? trim($_POST['position']) : ($existing['position'] ?? null);
        $statusField = isset($_POST['status']) ? trim($_POST['status']) : ($existing['status'] ?? null);
        $civil_status = isset($_POST['civil_status']) ? trim($_POST['civil_status']) : ($existing['civil_status'] ?? null);
        $entrance_to_duty = isset($_POST['entrance_to_duty']) ? trim($_POST['entrance_to_duty']) : ($existing['entrance_to_duty'] ?? null);
        $unit = isset($_POST['unit']) ? trim($_POST['unit']) : ($existing['unit'] ?? null);
        $gsis_policy_no = isset($_POST['gsis_policy_no']) ? trim($_POST['gsis_policy_no']) : ($existing['gsis_policy_no'] ?? null);
        $national_ref = isset($_POST['national_reference_card_no']) ? trim($_POST['national_reference_card_no']) : ($existing['national_reference_card_no'] ?? null);

        // only admins/hr can update balances and manager
        $manager_id = NULL;
        $annual = null;
        $sick = null;
        $force = null;
        
        if (in_array($role, ['admin','hr'])) {
            $manager_id = !empty($_POST['manager_id']) ? $_POST['manager_id'] : NULL;
            $annual = isset($_POST['annual_balance']) ? floatval($_POST['annual_balance']) : null;
            $sick = isset($_POST['sick_balance']) ? floatval($_POST['sick_balance']) : null;
            $force = isset($_POST['force_balance']) ? intval($_POST['force_balance']) : null;
        }

        // get old balances to log changes
        $oldStmt = $db->prepare("SELECT annual_balance, sick_balance, force_balance FROM employees WHERE id = ?");
        $oldStmt->execute([$empId]);
        $oldBalances = $oldStmt->fetch(PDO::FETCH_ASSOC);

        // handle picture upload if provided
        $picPath = null;
        if (!empty($_FILES['profile_pic']['name'])) {
            $dest = '../uploads/' . uniqid() . '_' . basename($_FILES['profile_pic']['name']);
            move_uploaded_file($_FILES['profile_pic']['tmp_name'], $dest);
            $picPath = $dest;
        }

        // update based on role
        if (in_array($role, ['admin','hr','manager'])) {
            // full update for admins/hr
            if ($picPath) {
                $stmt = $db->prepare("UPDATE employees SET first_name=?, last_name=?, department=?, position=?, status=?, civil_status=?, entrance_to_duty=?, unit=?, gsis_policy_no=?, national_reference_card_no=?, manager_id=?, annual_balance=?, sick_balance=?, force_balance=?, profile_pic=? WHERE id=?");
                $stmt->execute([$first_name,$last_name,$department,$position,$statusField,$civil_status,$entrance_to_duty,$unit,$gsis_policy_no,$national_ref,$manager_id,$annual,$sick,$force,$picPath,$empId]);
            } else {
                $stmt = $db->prepare("UPDATE employees SET first_name=?, last_name=?, department=?, position=?, status=?, civil_status=?, entrance_to_duty=?, unit=?, gsis_policy_no=?, national_reference_card_no=?, manager_id=?, annual_balance=?, sick_balance=?, force_balance=? WHERE id=?");
                $stmt->execute([$first_name,$last_name,$department,$position,$statusField,$civil_status,$entrance_to_duty,$unit,$gsis_policy_no,$national_ref,$manager_id,$annual,$sick,$force,$empId]);
            }

            // log budget changes
            if ($oldBalances['annual_balance'] != $annual) {
                $leaveModel->logBudgetChange($empId, 'Annual', $oldBalances['annual_balance'], $annual, 'adjustment', null, 'Admin manual adjustment');
            }
            if ($oldBalances['sick_balance'] != $sick) {
                $leaveModel->logBudgetChange($empId, 'Sick', $oldBalances['sick_balance'], $sick, 'adjustment', null, 'Admin manual adjustment');
            }
            if ($oldBalances['force_balance'] != $force) {
                $leaveModel->logBudgetChange($empId, 'Force', $oldBalances['force_balance'], $force, 'adjustment', null, 'Admin manual adjustment');
            }
            
            header("Location: ../views/manage_employees.php?updated=1");
        } else {
            // employees can only update profile info
            if ($picPath) {
                $stmt = $db->prepare("UPDATE employees SET first_name=?, last_name=?, department=?, position=?, status=?, civil_status=?, entrance_to_duty=?, unit=?, gsis_policy_no=?, national_reference_card_no=?, profile_pic=? WHERE id=?");
                $stmt->execute([$first_name,$last_name,$department,$position,$statusField,$civil_status,$entrance_to_duty,$unit,$gsis_policy_no,$national_ref,$picPath,$empId]);
            } else {
                $stmt = $db->prepare("UPDATE employees SET first_name=?, last_name=?, department=?, position=?, status=?, civil_status=?, entrance_to_duty=?, unit=?, gsis_policy_no=?, national_reference_card_no=? WHERE id=?");
                $stmt->execute([$first_name,$last_name,$department,$position,$statusField,$civil_status,$entrance_to_duty,$unit,$gsis_policy_no,$national_ref,$empId]);
            }
            
            header("Location: ../views/employee_profile.php?id=$empId&updated=1");
        }
        exit();
    }

    // admin adding historical leave entry for employee
    if (isset($_POST['add_history'])) {
        $empId = intval($_POST['employee_id']);
        $typeId = intval($_POST['leave_type_id']);
        $start = $_POST['start_date'];
        $end = $_POST['end_date'];
        $days = floatval($_POST['total_days']);
        $reason = trim($_POST['reason'] ?? '');
        $earningAmount = isset($_POST['earning_amount']) && $_POST['earning_amount'] !== '' ? floatval($_POST['earning_amount']) : 0;
        $status = 'approved';
        $approved_by = $_SESSION['user_id'];

        // resolve leave type name for compatibility
        $ltStmt = $db->prepare("SELECT * FROM leave_types WHERE id = ?");
        $ltStmt->execute([$typeId]);
        $ltInfo = $ltStmt->fetch(PDO::FETCH_ASSOC);
        $typeName = $ltInfo ? $ltInfo['name'] : '';

        // get balance snapshots before deduction, allow override via form
        $snapshots = $leaveModel->getBalanceSnapshots($empId);
        if (isset($_POST['snapshot_annual_balance']) && $_POST['snapshot_annual_balance'] !== '') {
            $snapshots['annual_balance'] = floatval($_POST['snapshot_annual_balance']);
        }
        if (isset($_POST['snapshot_sick_balance']) && $_POST['snapshot_sick_balance'] !== '') {
            $snapshots['sick_balance'] = floatval($_POST['snapshot_sick_balance']);
        }
        if (isset($_POST['snapshot_force_balance']) && $_POST['snapshot_force_balance'] !== '') {
            $snapshots['force_balance'] = floatval($_POST['snapshot_force_balance']);
        }

        // Handle earning first (if specified)
        if ($earningAmount > 0) {
            // Determine which column to add earning to
            $col = 'annual_balance';
            switch (strtolower($typeName)) {
                case 'sick': $col='sick_balance'; break;
                case 'force': $col='force_balance'; break;
            }
            // get old balance
            $oldStmt = $db->prepare("SELECT $col FROM employees WHERE id = ?");
            $oldStmt->execute([$empId]);
            $oldBalance = floatval($oldStmt->fetchColumn());
            // update balance
            $db->prepare("UPDATE employees SET $col = $col + ? WHERE id = ?")->execute([$earningAmount, $empId]);
            // log to budget history
            $newBalance = $oldBalance + $earningAmount;
            $leaveModel->logBudgetChange($empId, $typeName, $oldBalance, $newBalance, 'earning', null, 'Historical earning added by admin');
        }

        $stmt = $db->prepare("INSERT INTO leave_requests (employee_id, leave_type, leave_type_id, start_date, end_date, total_days, reason, status, approved_by, snapshot_annual_balance, snapshot_sick_balance, snapshot_force_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$empId, $typeName, $typeId, $start, $end, $days, $reason, $status, $approved_by, $snapshots['annual_balance'], $snapshots['sick_balance'], $snapshots['force_balance']]);
        $leave_id = $db->lastInsertId();

        // if this type deducts balance
        if ($ltInfo && $ltInfo['deduct_balance']) {
            // choose correct column
            $col = 'annual_balance';
            switch (strtolower($ltInfo['name'])) {
                case 'sick': $col='sick_balance'; break;
                case 'force': $col='force_balance'; break;
            }
            // get old balance
            $oldStmt = $db->prepare("SELECT $col FROM employees WHERE id = ?");
            $oldStmt->execute([$empId]);
            $oldBalance = floatval($oldStmt->fetchColumn());
            // update balance
            $db->prepare("UPDATE employees SET $col = GREATEST(0, $col - ?) WHERE id = ?")->execute([$days, $empId]);
            // log to budget history and leave_balance_logs
            $newBalance = max(0, $oldBalance - $days);
            $leaveModel->logBudgetChange($empId, $ltInfo['name'], $oldBalance, $newBalance, 'deduction', $leave_id, 'Historical leave entry added by admin');
            $stmtLog = $db->prepare("INSERT INTO leave_balance_logs (employee_id, change_amount, reason, leave_id) VALUES (?, ?, ?, ?)");
            $stmtLog->execute([$empId, -1 * $days, 'deduction', $leave_id]);
        }

        header("Location: ../views/employee_profile.php?id=$empId&added_history=1");
        exit();
    }

    // otherwise create a new employee
    // only admin may create new employees
    if ($_SESSION['role'] !== 'admin') {
        die("Access denied");
    }

    $email = trim($_POST['email']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $department = trim($_POST['department']);
    $manager_id = !empty($_POST['manager_id']) ? $_POST['manager_id'] : NULL;
    $role = isset($_POST['role']) ? $_POST['role'] : 'employee';
    $password = trim($_POST['password']);
    $activation_token = bin2hex(random_bytes(32));

    try {
        $db->beginTransaction();

        // 1️⃣ Create the user account and mark active immediately
        $userModel->create($email, $password, $role, $activation_token);
        $user_id = $db->lastInsertId();
        // activate right away (bypass activation link)
        $db->prepare("UPDATE users SET is_active=1, activation_token=NULL WHERE id = ?")
           ->execute([$user_id]);

        // 2️⃣ Create employee profile with balances
        // check for profile picture upload
        $picPath = null;
        if (!empty($_FILES['profile_pic']['name'])) {
            $dest = '../uploads/' . uniqid() . '_' . basename($_FILES['profile_pic']['name']);
            move_uploaded_file($_FILES['profile_pic']['tmp_name'], $dest);
            $picPath = $dest;
        }
        $stmt = $db->prepare("INSERT INTO employees 
            (user_id, first_name, last_name, department, position, status, civil_status, entrance_to_duty, unit, gsis_policy_no, national_reference_card_no, manager_id, 
             annual_balance, sick_balance, force_balance, profile_pic) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $user_id,
            $first_name,
            $last_name,
            $department,
            $_POST['position'] ?? null,
            $_POST['status'] ?? null,
            $_POST['civil_status'] ?? null,
            $_POST['entrance_to_duty'] ?? null,
            $_POST['unit'] ?? null,
            $_POST['gsis_policy_no'] ?? null,
            $_POST['national_reference_card_no'] ?? null,
            $manager_id,
            0,
            0,
            5,
            $picPath
        ]);

        $db->commit();
        header("Location: ../views/manage_employees.php?success=1");
        exit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        echo "Error: " . $e->getMessage();
    }
}