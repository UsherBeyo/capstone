## Leave Management Enhancements

This project now supports a more complete leave policy:

* **Multiple leave types** – annual, sick and force leave are tracked separately.
* **Monthly accrual** – employees earn **1.25 days of annual leave per month**. Run `php scripts/accrue.php` (via cron) to apply.
* **Force leave quota** – every month each employee receives **5 force leave days** which are tracked separately and can be used at the employee's discretion; unused days are reset on accrual.
* **Sick balance** – tracked separately; can be adjusted by admin.
* **New database columns**:
  * `annual_balance` (decimal)
  * `sick_balance`   (decimal)
  * `force_balance`  (int)

### Database migration

A helper script has been added at `scripts/migration.php` that will add the new columns and copy any existing `leave_balance` values into `annual_balance`.

Use this script to also create tables such as `holidays` needed for the calendar feature.

#### Adding additional roles

The `users` table stores a `role` field; existing roles are `admin`, `manager`, and `employee`.  You can insert other roles (e.g. `hr`) with a simple SQL statement:

```sql
INSERT INTO users (email, password, role, is_active) \
VALUES ('hr@company.com', '<hash>', 'hr', 1);
```

`<hash>` should be generated with `password_hash('yourpassword', PASSWORD_DEFAULT)` (you can run a small PHP snippet).  After adding the user, create a corresponding row in `employees`:

```sql
INSERT INTO employees (user_id, first_name, last_name, department, manager_id, annual_balance, sick_balance, force_balance)
VALUES (LAST_INSERT_ID(), 'HR','User','Human Resources', NULL, 0, 0, 5);
```

Managers and HR now have access to approval screens and the calendar.

```sh
php scripts/migration.php
```

Run this once after pulling the changes.

### Cron job / monthly update

Schedule the following command to run on the first day of each month:

```sh
php /path/to/capstone/scripts/accrue.php
```

It increments annual balances and resets the force leave quota. The script also prints a warning if any employees still had leftover force days.

### UI changes

* Dashboard now displays all three balances when employees log in.
* Employees can view their request history and change password from their dashboard.
* Apply‑for‑leave form shows current balances and allows choosing a type (force enforced).
* Managers and admins can approve/reject requests; admins have a dedicated listing with inline editing.
* Admin panel allows selecting role when creating a user, editing employee details/balances, and displays the three balances in the employee list.
* Holiday management page permits creating named holidays; these and approved leaves are drawn on a basic calendar view.
* A calendar page shows approved leaves and holidays month‑by‑month; navigation lets you move between months.
* Statistics page gives counts of employees by department and role, plus total headcount.
* Change‑password form for all logged‑in users.

### Other notes

* `LeaveController` now handles leave submission as well as approval.
* The `Leave` model encapsulates type‑specific balance logic.
* Session now stores `emp_id` for the currently logged in employee.

---

Please refer to the code comments for further details.
