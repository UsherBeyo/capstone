<?php
// Run this script once to update the database schema for new leave policy.
// Usage: php migration.php

require_once __DIR__ . '/../config/database.php';

$db = (new Database())->connect();

try {
    $db->beginTransaction();

    // add new columns if they don't exist
    $db->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS annual_balance DECIMAL(6,2) NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS sick_balance DECIMAL(6,2) NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS force_balance INT NOT NULL DEFAULT 0");
    // new profile picture path
    $db->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS profile_pic VARCHAR(255) NULL");

    // you may keep leave_balance for backwards compatibility but treat it as alias
    // some earlier code still references leave_balance; you can copy values over
    $db->exec("UPDATE employees SET annual_balance = leave_balance WHERE annual_balance = 0");

    // ensure leave_requests has necessary fields for the new logic
    $db->exec("ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS leave_type VARCHAR(50) NOT NULL DEFAULT 'Annual'");
    $db->exec("ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'pending'");
    $db->exec("ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS approved_by INT NULL");
    $db->exec("ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS manager_comments TEXT NULL");
    $db->exec("ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");

    // holidays table for calendar
    $db->exec("CREATE TABLE IF NOT EXISTS holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        holiday_date DATE NOT NULL UNIQUE,
        description VARCHAR(255) NULL,
        type VARCHAR(50) NOT NULL DEFAULT 'Other'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    // create accruals log table to record monthly accruals
    $db->exec("CREATE TABLE IF NOT EXISTS accruals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        amount DECIMAL(6,2) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    // create budget history table to track balance changes
    $db->exec("CREATE TABLE IF NOT EXISTS budget_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        leave_type VARCHAR(50) NOT NULL,
        old_balance DECIMAL(6,2) NOT NULL,
        new_balance DECIMAL(6,2) NOT NULL,
        action VARCHAR(50) NOT NULL,
        leave_request_id INT NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    $db->commit();
    echo "Migration completed.\n";
} catch (Exception $e) {
    $db->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
