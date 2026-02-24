<?php
session_start();

require_once '../config/database.php';
require_once '../models/User.php';
require_once '../models/Leave.php';

if ($_SESSION['role'] !== 'admin') {
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

    // handle update of existing employee record
    if (isset($_POST['update_employee'])) {
        $empId = $_POST['employee_id'];
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $department = trim($_POST['department']);
        $manager_id = !empty($_POST['manager_id']) ? $_POST['manager_id'] : NULL;
        $annual = floatval($_POST['annual_balance']);
        $sick   = floatval($_POST['sick_balance']);
        $force  = intval($_POST['force_balance']);

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

        if ($picPath) {
            $stmt = $db->prepare("UPDATE employees SET first_name=?, last_name=?, department=?, manager_id=?, annual_balance=?, sick_balance=?, force_balance=?, profile_pic=? WHERE id=?");
            $stmt->execute([$first_name,$last_name,$department,$manager_id,$annual,$sick,$force,$picPath,$empId]);
        } else {
            $stmt = $db->prepare("UPDATE employees SET first_name=?, last_name=?, department=?, manager_id=?, annual_balance=?, sick_balance=?, force_balance=? WHERE id=?");
            $stmt->execute([$first_name,$last_name,$department,$manager_id,$annual,$sick,$force,$empId]);
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
        exit();
    }

    // admin adding historical leave entry for employee
    if (isset($_POST['add_history'])) {
        $empId = intval($_POST['employee_id']);
        $type = trim($_POST['leave_type']);
        $start = $_POST['start_date'];
        $end = $_POST['end_date'];
        $days = floatval($_POST['total_days']);
        $reason = trim($_POST['reason'] ?? '');
        $status = 'approved';
        $approved_by = $_SESSION['user_id'];

        $stmt = $db->prepare("INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, total_days, reason, status, approved_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$empId, $type, $start, $end, $days, $reason, $status, $approved_by]);
        $leave_id = $db->lastInsertId();

        // deduct from corresponding balance and log change
        $col = 'annual_balance';
        switch (strtolower($type)) {
            case 'sick': $col='sick_balance'; break;
            case 'force': $col='force_balance'; break;
        }
        
        // get old balance
        $oldStmt = $db->prepare("SELECT $col FROM employees WHERE id = ?");
        $oldStmt->execute([$empId]);
        $oldBalance = floatval($oldStmt->fetchColumn());
        
        // update balance
        $db->prepare("UPDATE employees SET $col = GREATEST(0, $col - ?) WHERE id = ?")->execute([$days, $empId]);
        
        // log to budget history
        $newBalance = max(0, $oldBalance - $days);
        $leaveModel->logBudgetChange($empId, ucfirst($type), $oldBalance, $newBalance, 'deduction', $leave_id, 'Historical leave entry added by admin');

        header("Location: ../views/employee_profile.php?id=$empId&added_history=1");
        exit();
    }

    // otherwise create a new employee
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
            (user_id, first_name, last_name, department, manager_id, 
             annual_balance, sick_balance, force_balance, profile_pic) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $user_id,
            $first_name,
            $last_name,
            $department,
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
        $db->rollBack();
        echo "Error: " . $e->getMessage();
    }
}