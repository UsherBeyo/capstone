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

    // new employee metadata fields requested by client
    $db->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS position VARCHAR(128) NULL");
    $db->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS status VARCHAR(64) NULL");
    $db->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS civil_status VARCHAR(64) NULL");
    $db->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS entrance_to_duty DATE NULL");
    $db->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS unit VARCHAR(128) NULL");
    $db->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS gsis_policy_no VARCHAR(128) NULL");
    $db->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS national_reference_card_no VARCHAR(128) NULL");

    // you may keep leave_balance for backwards compatibility but treat it as alias
    // some earlier code still references leave_balance; you can copy values over
    $db->exec("UPDATE employees SET annual_balance = leave_balance WHERE annual_balance = 0");

    // ensure leave_requests has necessary fields for the new logic
    $db->exec("ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS leave_type VARCHAR(50) NOT NULL DEFAULT 'Annual'");
    $db->exec("ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'pending'");
    $db->exec("ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS approved_by INT NULL");
    $db->exec("ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS manager_comments TEXT NULL");
    $db->exec("ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    $db->exec("ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS snapshot_annual_balance DECIMAL(6,2) NULL");
    $db->exec("ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS snapshot_sick_balance DECIMAL(6,2) NULL");
    $db->exec("ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS snapshot_force_balance INT NULL");

    // holidays table for calendar
    $db->exec("CREATE TABLE IF NOT EXISTS holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        holiday_date DATE NOT NULL UNIQUE,
        description VARCHAR(255) NULL,
        type VARCHAR(50) NOT NULL DEFAULT 'Other'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    // leave_types table holds metadata for each kind of leave
    $db->exec("CREATE TABLE IF NOT EXISTS leave_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        deduct_balance TINYINT(1) NOT NULL DEFAULT 1,
        requires_approval TINYINT(1) NOT NULL DEFAULT 1,
        max_days_per_year DECIMAL(6,2) DEFAULT NULL,
        auto_approve TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    // set up some defaults if none exist
    $db->exec("INSERT IGNORE INTO leave_types (name, deduct_balance, requires_approval, max_days_per_year, auto_approve) VALUES
        ('Vacation',1,1,NULL,0),
        ('Sick',1,1,NULL,0),
        ('Emergency',0,1,NULL,1),
        ('Special',0,0,NULL,1)");

    // ensure leave_requests stores a reference to leave_types and keep old column for backwards compatibility
    $db->exec("ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS leave_type_id INT NULL AFTER leave_type");
    // try to backfill the new column for existing rows
    $db->exec("UPDATE leave_requests lr
                 JOIN leave_types lt ON LOWER(lr.leave_type) = LOWER(lt.name)
                 SET lr.leave_type_id = lt.id");
    // optionally create a foreign key once data is consistent
    // $db->exec("ALTER TABLE leave_requests ADD CONSTRAINT fk_leave_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(id)");

    // new history tables for audit
    $db->exec("CREATE TABLE IF NOT EXISTS accrual_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        amount DECIMAL(6,2) NOT NULL,
        date_accrued DATE NOT NULL,
        month_reference VARCHAR(7) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    $db->exec("CREATE TABLE IF NOT EXISTS leave_balance_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        change_amount DECIMAL(6,2) NOT NULL,
        reason VARCHAR(50) NOT NULL,
        leave_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
