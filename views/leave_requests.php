<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/database.php';

if (!in_array($_SESSION['role'], ['admin','manager','department_head','hr','personnel'], true)) {
    die("Access denied");
}

$db = (new Database())->connect();

$role = $_SESSION['role'];
$userId = (int)($_SESSION['user_id'] ?? 0);

// tab filter controls (all / pending / approved / rejected / archived)
$tab = $_GET['tab'] ?? 'all';
$validTabs = ['all','pending','approved','rejected','archived'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'all';
}

function safe_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function trunc3($v): string {
    if ($v === null || $v === '') return '';
    $n = (float)$v;
    $t = floor($n * 1000) / 1000;
    return number_format($t, 3, '.', '');
}

$signatories = [];

try {
    $stmt = $db->query("SELECT key_name, name, position FROM system_signatories");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $signatories[$s['key_name']] = $s;
    }
} catch (Throwable $t) {
    $signatories = [];
}

function columnExists(PDO $db, string $table, string $column): bool {
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $t) {
        return false;
    }
}

function bh_date(array $r, bool $hasTransDate): string {
    if ($hasTransDate && !empty($r['trans_date'])) return (string)$r['trans_date'];
    return substr((string)($r['created_at'] ?? ''), 0, 10);
}

function buildLeaveCardRows(PDO $db, int $empId, bool $hasTransDate, bool $hasSnapshots): array {
    $rows = [];

    // 1) LEAVE REQUESTS
    $leaveSql = "
        SELECT
            lr.id,
            lr.created_at,
            lr.start_date,
            lr.end_date,
            COALESCE(lt.name, lr.leave_type) AS leave_type,
            lr.status,
            lr.total_days,
            lr.snapshot_annual_balance,
            lr.snapshot_sick_balance,
            lr.snapshot_force_balance
        FROM leave_requests lr
        LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
        WHERE lr.employee_id = ?
        ORDER BY COALESCE(lr.start_date, DATE(lr.created_at)) ASC, lr.created_at ASC, lr.id ASC
    ";
    $leaveStmt = $db->prepare($leaveSql);
    $leaveStmt->execute([$empId]);

    foreach ($leaveStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $leaveType = trim((string)$r['leave_type']);
        $typeLower = strtolower($leaveType);
        $statusRaw = strtolower(trim((string)$r['status']));
        $days = floatval($r['total_days']);

        if ($typeLower === 'undertime') {
            continue;
        }

        $txDate = !empty($r['start_date'])
            ? (string)$r['start_date']
            : substr((string)$r['created_at'], 0, 10);

        $vacEarn = 0.0;
        $sickEarn = 0.0;
        $vacDed = 0.0;
        $sickDed = 0.0;

        if ($statusRaw === 'approved') {
            if (in_array($typeLower, ['sick', 'sick leave'], true)) {
                $sickDed = $days;
            } else {
                $vacDed = $days;
            }
        }

        $vacBal = ($r['snapshot_annual_balance'] !== null && $r['snapshot_annual_balance'] !== '')
            ? floatval($r['snapshot_annual_balance'])
            : '';
        $sickBal = ($r['snapshot_sick_balance'] !== null && $r['snapshot_sick_balance'] !== '')
            ? floatval($r['snapshot_sick_balance'])
            : '';

        $rows[] = [
            'date' => $txDate,
            'particulars' => $leaveType . ' Leave',
            'vac_earned' => $vacEarn,
            'vac_deducted' => $vacDed,
            'vac_balance' => $vacBal,
            'sick_earned' => $sickEarn,
            'sick_deducted' => $sickDed,
            'sick_balance' => $sickBal,
            'status' => ucfirst($statusRaw),
            'entry_type' => 'leave',
            '_sort_ts' => strtotime($txDate ?: '1970-01-01'),
            '_sort_seq' => 1,
        ];
    }

    // 2) BUDGET HISTORY
    $budgetSql = "
        SELECT
            id,
            created_at" . ($hasTransDate ? ", trans_date" : "") . ",
            leave_type, action, old_balance, new_balance, notes
        FROM budget_history
        WHERE employee_id = ?
          AND (leave_request_id IS NULL OR leave_request_id = 0)
        ORDER BY " . ($hasTransDate ? "COALESCE(trans_date, DATE(created_at))" : "DATE(created_at)") . " ASC,
                 created_at ASC, id ASC
    ";
    $budgetStmt = $db->prepare($budgetSql);
    $budgetStmt->execute([$empId]);

    foreach ($budgetStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $leaveType = trim((string)$r['leave_type']);
        $typeLower = strtolower($leaveType);
        $actionLower = strtolower(trim((string)$r['action']));
        $notes = (string)($r['notes'] ?? '');

        $txDate = bh_date($r, $hasTransDate);

        $vacEarn = 0.0;
        $sickEarn = 0.0;
        $vacDed = 0.0;
        $sickDed = 0.0;
        $vacBal = '';
        $sickBal = '';

        if ($actionLower === 'undertime_paid' || $actionLower === 'undertime_unpaid') {
            $particulars = 'Undertime';
            $meta = [];

            if (preg_match_all('/([A-Z_]+)=([0-9.]+)/', $notes, $m, PREG_SET_ORDER)) {
                foreach ($m as $pair) {
                    $meta[$pair[1]] = $pair[2];
                }
            }

            if (isset($meta['UT_DEDUCT'])) $vacDed = (float)$meta['UT_DEDUCT'];
            if (isset($meta['VAC'])) $vacBal = (float)$meta['VAC'];
            if (isset($meta['SICK'])) $sickBal = (float)$meta['SICK'];
        } else {
            $old = floatval($r['old_balance']);
            $new = floatval($r['new_balance']);
            $deltaEarn = max(0, $new - $old);
            $deltaDed = max(0, $old - $new);

            if (in_array($actionLower, ['accrual', 'earning'], true)) {
                $particulars = 'Monthly Accrual';
                $vacEarn = $deltaEarn;
                $sickEarn = $deltaEarn;

                if (strpos($typeLower, 'sick') !== false) {
                    $sickBal = $new;
                } else {
                    $vacBal = $new;
                }
            } else {
                $particulars = ucfirst($actionLower) . ' ' . $leaveType;

                if (in_array($typeLower, ['annual', 'vacational', 'vacation', 'force'], true)) {
                    $vacEarn = $deltaEarn;
                    $vacDed = $deltaDed;
                    $vacBal = $new;
                } elseif ($typeLower === 'sick') {
                    $sickEarn = $deltaEarn;
                    $sickDed = $deltaDed;
                    $sickBal = $new;
                }
            }
        }

        $rows[] = [
            'date' => $txDate,
            'particulars' => $particulars,
            'vac_earned' => $vacEarn,
            'vac_deducted' => $vacDed,
            'vac_balance' => ($vacBal === '' ? '' : $vacBal),
            'sick_earned' => $sickEarn,
            'sick_deducted' => $sickDed,
            'sick_balance' => ($sickBal === '' ? '' : $sickBal),
            'status' => ucfirst($actionLower),
            'entry_type' => 'budget',
            '_sort_ts' => strtotime($txDate ?: '1970-01-01'),
            '_sort_seq' => 2,
        ];
    }

    usort($rows, function($a, $b) {
        $ta = $a['_sort_ts'] ?? 0;
        $tb = $b['_sort_ts'] ?? 0;
        if ($ta !== $tb) return $tb <=> $ta; // newest first

        $sa = $a['_sort_seq'] ?? 0;
        $sb = $b['_sort_seq'] ?? 0;
        if ($sa !== $sb) return $sa <=> $sb;

        return strcmp((string)($a['particulars'] ?? ''), (string)($b['particulars'] ?? ''));
    });

    foreach ($rows as &$rr) {
        unset($rr['_sort_ts'], $rr['_sort_seq']);
    }
    unset($rr);

    return array_slice($rows, 0, 8);
}

function computeProjectedBalances(array $row): array {
    $annualBefore = floatval($row['annual_balance'] ?? 0);
    $sickBefore   = floatval($row['sick_balance'] ?? 0);
    $forceBefore  = floatval($row['force_balance'] ?? 0);

    $days = floatval($row['total_days'] ?? 0);
    $type = strtolower(trim((string)($row['leave_type_name'] ?? $row['leave_type'] ?? '')));

    $projected = [
        'annual_before' => $annualBefore,
        'sick_before'   => $sickBefore,
        'force_before'  => $forceBefore,
        'annual_after'  => $annualBefore,
        'sick_after'    => $sickBefore,
        'force_after'   => $forceBefore,
        'bucket'        => 'none',
    ];

    if (in_array($type, ['annual', 'vacational', 'vacation', 'vacation leave'], true)) {
        $projected['annual_after'] = max(0, $annualBefore - $days);
        $projected['bucket'] = 'annual';
    } elseif (in_array($type, ['sick', 'sick leave'], true)) {
        $projected['sick_after'] = max(0, $sickBefore - $days);
        $projected['bucket'] = 'sick';
    } elseif (in_array($type, ['force', 'mandatory', 'forced', 'mandatory / forced leave', 'mandatory/forced leave'], true)) {
        $projected['force_after'] = max(0, $forceBefore - $days);
        $projected['bucket'] = 'force';
    }

    return $projected;
}

$whereDeptHead = "lr.workflow_status = 'pending_department_head' AND lr.status = 'pending'";
$wherePersonnel = "lr.workflow_status = 'pending_personnel' AND lr.status = 'pending'";

if (in_array($role, ['manager','department_head'], true)) {
    $whereDeptHead .= " AND lr.department_head_user_id = " . $userId;
}
if (in_array($role, ['personnel','hr'], true)) {
    // personnel sees only their stage
} elseif ($role !== 'admin') {
    $wherePersonnel .= " AND 1 = 0";
}

$pendingDeptHead = $db->query("
    SELECT lr.*, e.first_name, e.middle_name, e.last_name, u.email,
           COALESCE(lt.name, lr.leave_type) AS leave_type_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE {$whereDeptHead}
    ORDER BY lr.start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pendingPersonnel = $db->query("
    SELECT
        lr.*,
        e.first_name,
        e.middle_name,
        e.last_name,
        e.department,
        e.position,
        e.annual_balance,
        e.sick_balance,
        e.force_balance,
        u.email,
        COALESCE(lt.name, lr.leave_type) AS leave_type_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE {$wherePersonnel}
    ORDER BY lr.start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$finalized = $db->query("
    SELECT lr.*, e.first_name, e.middle_name, e.last_name, u.email,
           COALESCE(lt.name, lr.leave_type) AS leave_type_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE lr.workflow_status = 'finalized' OR lr.status = 'approved'
    ORDER BY lr.start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$returnedOrRejected = $db->query("
    SELECT lr.*, e.first_name, e.middle_name, e.last_name, u.email,
           COALESCE(lt.name, lr.leave_type) AS leave_type_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE lr.workflow_status IN ('rejected_department_head','returned_by_personnel') OR lr.status = 'rejected'
    ORDER BY lr.start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$hasTransDate = columnExists($db, 'budget_history', 'trans_date');
$hasSnapshots = columnExists($db, 'leave_requests', 'snapshot_annual_balance') && columnExists($db, 'leave_requests', 'snapshot_sick_balance');

$leaveCardPreviewMap = [];
$personnelEmployeeIds = array_values(array_unique(array_map(function($r) {
    return (int)$r['employee_id'];
}, $pendingPersonnel)));

foreach ($personnelEmployeeIds as $empId) {
    $leaveCardPreviewMap[$empId] = buildLeaveCardRows($db, $empId, $hasTransDate, $hasSnapshots);
}

// Prepare archived requests for the "Archived" toggle
if ($role === 'manager') {
    $archivedQuery = $db->prepare("
        SELECT lr.*, e.first_name, e.last_name, COALESCE(lt.name, lr.leave_type) AS leave_type_name
        FROM leave_requests lr
        JOIN employees e ON lr.employee_id = e.id
        LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
        WHERE lr.status IN ('approved', 'rejected', 'cancelled') AND e.manager_id = ?
          AND lr.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY lr.created_at DESC
        LIMIT 50
    ");
    $archivedQuery->execute([$_SESSION['emp_id'] ?? 0]);
} else {
    $archivedQuery = $db->prepare("
        SELECT lr.*, e.first_name, e.last_name, COALESCE(lt.name, lr.leave_type) AS leave_type_name
        FROM leave_requests lr
        JOIN employees e ON lr.employee_id = e.id
        LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
        WHERE lr.status IN ('approved', 'rejected', 'cancelled')
          AND lr.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY lr.created_at DESC
        LIMIT 100
    ");
    $archivedQuery->execute();
}
$archived = $archivedQuery->fetchAll(PDO::FETCH_ASSOC);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Leave Requests</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="app-main">
    <h2>Leave Requests</h2>

    <div class="filter-row" style="margin-bottom:16px;">
        <div class="filter-tabs" id="leaveRequestTabs">
            <?php
            $tabs = [
                'all' => 'All',
                'pending' => 'Pending',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                'archived' => 'Archived',
            ];
            foreach ($tabs as $key => $label) {
                $active = ($tab === $key) ? ' is-active' : '';
                echo '<a href="?tab=' . $key . '" class="filter-tab' . $active . '" data-tab="' . $key . '">' . htmlspecialchars($label) . '</a>';
            }
            ?>
        </div>
    </div>

    <div id="section-pending" style="<?= ($tab === 'all' || $tab === 'pending') ? '' : 'display:none;'; ?>">
        <div class="ui-card mb-6">
            <h3>Pending Department Head Approval</h3>
        <?php if (empty($pendingDeptHead)): ?>
            <p>No requests pending for Department Head approval.</p>
        <?php else: ?>
            <?php $deptActionModalsHtml = ''; ?>
            <div class="table-wrap">
                <table class="ui-table">
                    <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Days</th>
                        <th>Reason</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingDeptHead as $r): ?>
                        <?php
                        $deptEmployeeName = trim($r['first_name'].' '.($r['middle_name'] ?? '').' '.$r['last_name']);
                        ob_start();
                        ?>
                        <tr class="personnel-row">
                            <td class="col-employee">
                                <div class="personnel-employee-cell">
                                    <strong><?= safe_h($deptEmployeeName); ?></strong>
                                    <div class="subtext"><?= safe_h($r['email']); ?></div>
                                </div>
                            </td>

                            <td class="col-type">
                                <span class="leave-type-pill"><?= safe_h($r['leave_type_name']); ?></span>
                            </td>

                            <td class="col-dates">
                                <div class="date-stack">
                                    <span><?= safe_h($r['start_date']); ?></span>
                                    <span class="date-arrow">to</span>
                                    <span><?= safe_h($r['end_date']); ?></span>
                                </div>
                            </td>

                            <td class="col-days">
                                <strong><?= trunc3($r['total_days']); ?></strong>
                            </td>

                            <td class="col-comment">
                                <div class="comment-preview" title="<?= safe_h($r['reason'] ?? ''); ?>">
                                    <?= safe_h($r['reason'] ?? '—'); ?>
                                </div>
                            </td>

                            <td class="col-action">
                                <div class="personnel-action-bar">
                                    <button type="button"
                                            class="icon-action-btn labelled icon-approve"
                                            onclick="openModal('deptActionModal_<?= (int)$r['id']; ?>')"
                                            title="Approve or reject request">
                                        <span class="action-icon">⚙</span>
                                        <span class="action-label">Action</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php
                        $rowHtml = ob_get_clean();
                        echo $rowHtml;

                        ob_start();
                        ?>
                        <div id="deptActionModal_<?= (int)$r['id']; ?>" class="modal">
                            <div class="modal-content floating-action-modal small-action-modal">
                                <button type="button" class="modal-close" onclick="closeModal('deptActionModal_<?= (int)$r['id']; ?>')">&times;</button>
                                <h3 style="margin-bottom:14px;">Department Head Action</h3>
                                <p class="review-muted" style="margin-bottom:14px;"><?= safe_h($deptEmployeeName); ?> • <?= safe_h($r['leave_type_name']); ?></p>

                                <form method="POST" action="../controllers/LeaveController.php" class="mini-action-form">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="leave_id" value="<?= (int)$r['id']; ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button type="submit">Approve &amp; Forward</button>
                                </form>

                                <form method="POST" action="../controllers/LeaveController.php" class="mini-action-form">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="leave_id" value="<?= (int)$r['id']; ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="text" name="comments" placeholder="Reason" required>
                                    <button type="submit" class="danger-btn">Reject</button>
                                </form>
                            </div>
                        </div>
                        <?php
                        $deptActionModalsHtml .= ob_get_clean();
                        ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $deptActionModalsHtml; ?>
        <?php endif; ?>
    </div>

    <div class="ui-card mb-6">
        <h3>Pending Personnel Review</h3>
        <?php if (empty($pendingPersonnel)): ?>
            <p>No requests pending for personnel review.</p>
        <?php else: ?>
            <?php $personnelModalHtml = ''; ?>
            <div class="table-wrap">
                <table class="ui-table">
                    <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Days</th>
                        <th>Balance</th>
                        <th>After Approval</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>

                    <?php foreach ($pendingPersonnel as $r): ?>
                        <?php
                        $employeeName = trim($r['first_name'].' '.($r['middle_name'] ?? '').' '.$r['last_name']);
                        $projected = computeProjectedBalances($r);
                        $modalId = 'reviewModal_' . (int)$r['id'];
                        $previewRows = $leaveCardPreviewMap[(int)$r['employee_id']] ?? [];
                        ?>
                        <tr class="personnel-row">
                            <td class="col-employee">
                                <div class="personnel-employee-cell">
                                    <strong><?= safe_h($employeeName); ?></strong>
                                    <div class="subtext"><?= safe_h($r['email']); ?></div>
                                    <div class="subtext"><?= safe_h($r['department'] ?? ''); ?><?= !empty($r['position']) ? ' • '.safe_h($r['position']) : ''; ?></div>
                                </div>
                            </td>

                            <td class="col-type">
                                <span class="leave-type-pill"><?= safe_h($r['leave_type_name']); ?></span>
                            </td>

                            <td class="col-dates">
                                <div class="date-stack">
                                    <span><?= safe_h($r['start_date']); ?></span>
                                    <span class="date-arrow">to</span>
                                    <span><?= safe_h($r['end_date']); ?></span>
                                </div>
                            </td>

                            <td class="col-days">
                                <strong><?= trunc3($r['total_days']); ?></strong>
                            </td>

                            <td class="col-balance">
                                <div class="balance-stack compact">
                                    <span class="balance-chip">Vac: <strong><?= trunc3($projected['annual_before']); ?></strong></span>
                                    <span class="balance-chip">Sick: <strong><?= trunc3($projected['sick_before']); ?></strong></span>
                                    <span class="balance-chip">Force: <strong><?= trunc3($projected['force_before']); ?></strong></span>
                                </div>
                            </td>

                            <td class="col-balance">
                                <div class="balance-stack compact">
                                    <span class="balance-chip <?= $projected['bucket'] === 'annual' ? 'chip-affected' : ''; ?>">Vac: <strong><?= trunc3($projected['annual_after']); ?></strong></span>
                                    <span class="balance-chip <?= $projected['bucket'] === 'sick' ? 'chip-affected' : ''; ?>">Sick: <strong><?= trunc3($projected['sick_after']); ?></strong></span>
                                    <span class="balance-chip <?= $projected['bucket'] === 'force' ? 'chip-affected' : ''; ?>">Force: <strong><?= trunc3($projected['force_after']); ?></strong></span>
                                </div>
                            </td>

                            <td class="col-action">
                                <div class="personnel-action-bar">
                                    <button type="button"
                                            class="icon-action-btn labelled"
                                            onclick="openModal('<?= $modalId; ?>')"
                                            title="Review details">
                                        <span class="action-icon">👁</span>
                                        <span class="action-label">View</span>
                                    </button>

                                    <a href="reports.php?type=leave_card&employee_id=<?= (int)$r['employee_id']; ?>"
                                       target="_blank"
                                       class="icon-action-btn labelled"
                                       title="Open full leave card">
                                        <span class="action-icon">📄</span>
                                        <span class="action-label">Leave Card</span>
                                    </a>

                                    <button type="button"
                                            class="icon-action-btn labelled icon-approve"
                                            onclick="openModal('personnelActionModal_<?= (int)$r['id']; ?>')"
                                            title="Approve or return request">
                                        <span class="action-icon">⚙</span>
                                        <span class="action-label">Action</span>
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <?php ob_start(); ?>
                        <div id="<?= $modalId; ?>" class="modal">
                            <div class="modal-content review-modal">
                                <button type="button" class="modal-close" onclick="closeModal('<?= $modalId; ?>')">&times;</button>

                                <div class="review-modal-header">
                                    <div>
                                        <h3 style="margin-bottom:6px;"><?= safe_h($employeeName); ?></h3>
                                        <p class="review-muted"><?= safe_h($r['email']); ?></p>
                                        <p class="review-muted"><?= safe_h($r['department'] ?? ''); ?><?= !empty($r['position']) ? ' • '.safe_h($r['position']) : ''; ?></p>
                                    </div>
                                    <div class="review-badge">Pending Personnel Review</div>
                                </div>

                                <div class="review-grid">
                                    <div class="review-panel">
                                        <h4>Leave Request Summary</h4>
                                        <div class="review-kv"><span>Leave Type</span><strong><?= safe_h($r['leave_type_name']); ?></strong></div>
                                        <div class="review-kv"><span>Date Range</span><strong><?= safe_h($r['start_date'].' to '.$r['end_date']); ?></strong></div>
                                        <div class="review-kv"><span>Total Days</span><strong><?= trunc3($r['total_days']); ?></strong></div>
                                        <div class="review-kv"><span>Reason</span><strong><?= safe_h($r['reason'] ?? ''); ?></strong></div>
                                        <div class="review-kv"><span>Dept Head Comment</span><strong><?= safe_h($r['department_head_comments'] ?? ''); ?></strong></div>
                                    </div>

                                    <div class="review-panel">
                                        <h4>Current Balances Before Deduction</h4>
                                        <div class="review-kv"><span>Vacational</span><strong><?= trunc3($projected['annual_before']); ?></strong></div>
                                        <div class="review-kv"><span>Sick</span><strong><?= trunc3($projected['sick_before']); ?></strong></div>
                                        <div class="review-kv"><span>Force</span><strong><?= trunc3($projected['force_before']); ?></strong></div>
                                    </div>

                                    <div class="review-panel">
                                        <h4>Projected Balances After Final Approval</h4>
                                        <div class="review-kv"><span>Vacational</span><strong><?= trunc3($projected['annual_after']); ?></strong></div>
                                        <div class="review-kv"><span>Sick</span><strong><?= trunc3($projected['sick_after']); ?></strong></div>
                                        <div class="review-kv"><span>Force</span><strong><?= trunc3($projected['force_after']); ?></strong></div>
                                    </div>
                                </div>

                                <div class="review-panel review-panel-full" style="margin-top:18px;">
                                    <div class="review-panel-head">
                                        <div>
                                            <h4 style="margin-bottom:4px;">Leave Card Preview</h4>
                                            <p class="review-muted">Latest employee transactions and balance movement</p>
                                        </div>
                                        <a href="reports.php?type=leave_card&employee_id=<?= (int)$r['employee_id']; ?>" target="_blank" class="btn-export">Open Full Leave Card</a>
                                    </div>

                                    <?php
                                    $latestVac = trunc3($projected['annual_before']);
                                    $latestSick = trunc3($projected['sick_before']);
                                    $latestForce = trunc3($projected['force_before']);
                                    ?>

                                    <div class="preview-summary-strip">
                                        <div class="preview-summary-card">
                                            <span class="preview-summary-label">Current Vacational</span>
                                            <strong><?= $latestVac; ?></strong>
                                        </div>
                                        <div class="preview-summary-card">
                                            <span class="preview-summary-label">Current Sick</span>
                                            <strong><?= $latestSick; ?></strong>
                                        </div>
                                        <div class="preview-summary-card">
                                            <span class="preview-summary-label">Current Force</span>
                                            <strong><?= $latestForce; ?></strong>
                                        </div>
                                    </div>

                                    <div class="review-leave-card-wrap modern-preview-wrap">
                                        <table class="review-leave-card-table modern-preview-table">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Particulars</th>
                                                    <th>Vac Earned</th>
                                                    <th>Vac Deducted</th>
                                                    <th>Vac Balance</th>
                                                    <th>Sick Earned</th>
                                                    <th>Sick Deducted</th>
                                                    <th>Sick Balance</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($previewRows)): ?>
                                                    <tr>
                                                        <td colspan="9" class="preview-empty">No leave card history available.</td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($previewRows as $row): ?>
                                                        <?php
                                                        $vb = $row['vac_balance'] ?? '';
                                                        $sb = $row['sick_balance'] ?? '';
                                                        $statusTextRaw = (string)($row['status'] ?? '');
                                                        $statusText = safe_h($statusTextRaw);
                                                        $entryType = $row['entry_type'] ?? '';
                                                        $rowClass = $entryType === 'leave' ? 'preview-row-leave' : 'preview-row-budget';
                                                        $statusClass = strtolower(preg_replace('/[^a-z0-9]+/', '-', $statusTextRaw));
                                                        ?>
                                                        <tr class="<?= $rowClass; ?>">
                                                            <td><?= safe_h($row['date'] ?? ''); ?></td>
                                                            <td class="preview-particulars"><?= safe_h($row['particulars'] ?? ''); ?></td>
                                                            <td><?= ((($row['vac_earned'] ?? 0) != 0) ? trunc3($row['vac_earned']) : '—'); ?></td>
                                                            <td><?= ((($row['vac_deducted'] ?? 0) != 0) ? trunc3($row['vac_deducted']) : '—'); ?></td>
                                                            <td><?= ($vb === '' ? '—' : trunc3($vb)); ?></td>
                                                            <td><?= ((($row['sick_earned'] ?? 0) != 0) ? trunc3($row['sick_earned']) : '—'); ?></td>
                                                            <td><?= ((($row['sick_deducted'] ?? 0) != 0) ? trunc3($row['sick_deducted']) : '—'); ?></td>
                                                            <td><?= ($sb === '' ? '—' : trunc3($sb)); ?></td>
                                                            <td>
                                                                <span class="preview-status-badge preview-status-<?= $statusClass !== '' ? $statusClass : 'default'; ?>">
                                                                    <?= $statusText !== '' ? $statusText : '—'; ?>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="review-modal-actions">
                                    <button type="button" class="btn-secondary" onclick="closeModal('<?= $modalId; ?>')">Close</button>
                                </div>
                            </div>
                        </div>

                        <div id="personnelActionModal_<?= (int)$r['id']; ?>" class="modal">
                            <div class="modal-content floating-action-modal small-action-modal">
                                <button type="button" class="modal-close" onclick="closeModal('personnelActionModal_<?= (int)$r['id']; ?>')">&times;</button>
                                <h3 style="margin-bottom:14px;">Personnel Action</h3>
                                <p class="review-muted" style="margin-bottom:14px;"><?= safe_h($employeeName); ?> • <?= safe_h($r['leave_type_name']); ?></p>

                                <form method="POST" action="../controllers/LeaveController.php" class="mini-action-form">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="leave_id" value="<?= (int)$r['id']; ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="text" name="comments" placeholder="Optional note">
                                    <button type="submit">Final Approve</button>
                                </form>

                                <form method="POST" action="../controllers/LeaveController.php" class="mini-action-form">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="leave_id" value="<?= (int)$r['id']; ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="text" name="comments" placeholder="Reason" required>
                                    <button type="submit" class="danger-btn">Return / Reject</button>
                                </form>
                            </div>
                        </div>
                        <?php
                        $personnelModalHtml .= ob_get_clean();
                        ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?= $personnelModalHtml; ?>
        <?php endif; ?>
    </div>
</div>

<div id="section-approved" style="<?= ($tab === 'all' || $tab === 'approved') ? '' : 'display:none;'; ?>">
    <div class="ui-card mb-6">
        <h3>Finalized / Approved</h3>

        <?php if (empty($finalized)): ?>
            <p>No finalized requests.</p>
        <?php else: ?>
            <div class="table-container">
                <table width="100%">
                    <tr>
                        <th>Employee</th>
                        <th>Email</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Days</th>
                        <th>Workflow</th>
                        <th>Print Status</th>
                        <?php if (in_array($role, ['personnel','hr','admin'], true)): ?>
                            <th>Action</th>
                        <?php endif; ?>
                    </tr>

                    <?php foreach ($finalized as $r): ?>
                        <tr>
                            <td><?= safe_h(trim($r['first_name'].' '.($r['middle_name'] ?? '').' '.$r['last_name'])); ?></td>
                            <td><?= safe_h($r['email']); ?></td>
                            <td><?= safe_h($r['leave_type_name']); ?></td>
                            <td><?= safe_h($r['start_date'].' to '.$r['end_date']); ?></td>
                            <td><?= trunc3($r['total_days']); ?></td>
                            <td><?= safe_h($r['workflow_status'] ?? 'finalized'); ?></td>
                            <td><?= safe_h($r['print_status'] ?? '—'); ?></td>

                            <?php if (in_array($role, ['personnel','hr','admin'], true)): ?>
                                <td>
                                    <button class="icon-action-btn labelled"
                                            onclick="openModal('printModal_<?= (int)$r['id']; ?>')"
                                            title="Customize signatories and print">
                                        <span class="action-icon">🖨</span>
                                        <span class="action-label">Print</span>
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <?php foreach ($finalized as $r): ?>
                <div id="printModal_<?= (int)$r['id']; ?>" class="modal">
                    <div class="modal-content review-modal" style="max-width:520px;">
                        <button type="button"
                                class="modal-close"
                                onclick="closeModal('printModal_<?= (int)$r['id']; ?>')">&times;</button>

                        <h3 style="margin-bottom:10px;">Customize Signatories</h3>

                        <p class="review-muted" style="margin-bottom:18px;">
                            <?= safe_h(trim($r['first_name'].' '.($r['middle_name'] ?? '').' '.$r['last_name'])); ?>
                            • <?= safe_h($r['leave_type_name']); ?>
                        </p>

                        <form method="POST"
                              action="../controllers/save_signatories.php"
                              target="_blank">

                            <input type="hidden" name="leave_id" value="<?= (int)$r['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">

                            <div class="review-panel">
                                <h4>7.A Certification of Leave Credits</h4>

                                <label>Name</label>
                                <input type="text"
                                       name="name_a"
                                       value="<?= safe_h($signatories['certification']['name'] ?? ''); ?>"
                                       required>

                                <label>Position</label>
                                <input type="text"
                                       name="position_a"
                                       value="<?= safe_h($signatories['certification']['position'] ?? ''); ?>"
                                       required>
                            </div>

                            <div class="review-panel" style="margin-top:16px;">
                                <h4>7.C Final Approver</h4>

                                <label>Name</label>
                                <input type="text"
                                       name="name_c"
                                       value="<?= safe_h($signatories['final_approver']['name'] ?? ''); ?>"
                                       required>

                                <label>Position</label>
                                <input type="text"
                                       name="position_c"
                                       value="<?= safe_h($signatories['final_approver']['position'] ?? ''); ?>"
                                       required>
                            </div>

                            <div class="review-modal-actions" style="margin-top:20px;">
                                <button type="submit">Save & Print</button>

                                <button type="button"
                                        class="btn-secondary"
                                        onclick="closeModal('printModal_<?= (int)$r['id']; ?>')">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div id="section-rejected" style="<?= ($tab === 'all' || $tab === 'rejected') ? '' : 'display:none;'; ?>">
    <div class="ui-card">
        <h3>Rejected / Returned</h3>
        <?php if (empty($returnedOrRejected)): ?>
            <p>No rejected or returned requests.</p>
        <?php else: ?>
            <div class="table-container">
                <table width="100%">
                    <tr>
                        <th>Employee</th>
                        <th>Email</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Status</th>
                        <th>Workflow</th>
                        <th>Comments</th>
                    </tr>
                    <?php foreach ($returnedOrRejected as $r): ?>
                        <tr>
                            <td><?= safe_h(trim($r['first_name'].' '.($r['middle_name'] ?? '').' '.$r['last_name'])); ?></td>
                            <td><?= safe_h($r['email']); ?></td>
                            <td><?= safe_h($r['leave_type_name']); ?></td>
                            <td><?= safe_h($r['start_date'].' to '.$r['end_date']); ?></td>
                            <td><?= safe_h($r['status']); ?></td>
                            <td><?= safe_h($r['workflow_status'] ?? '—'); ?></td>
                            <td><?= safe_h($r['personnel_comments'] ?? $r['department_head_comments'] ?? $r['manager_comments'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="section-archived" style="<?= ($tab === 'all' || $tab === 'archived') ? '' : 'display:none;'; ?>">
    <div id="archiveCard" class="ui-card">
        <h3>Archived Requests (<?= count($archived); ?>)</h3>

        <?php if (empty($archived)): ?>
            <p>No archived requests found.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="ui-table">
                    <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Days</th>
                        <th>Status</th>
                        <th>Reason</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach($archived as $r): ?>
                        <tr>
                            <td><?= safe_h(trim($r['first_name'].' '.($r['middle_name'] ?? '').' '.$r['last_name'])); ?></td>
                            <td><?= safe_h($r['leave_type_name'] ?? $r['leave_type']); ?></td>
                            <td><?= safe_h($r['start_date'].' to '.$r['end_date']); ?></td>
                            <td><?= trunc3($r['total_days']); ?></td>
                            <td><?= safe_h(ucfirst($r['status'])); ?></td>
                            <td><?= safe_h($r['manager_comments'] ?? $r['reason'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('open');
}

function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('open');
}

document.querySelectorAll('.modal').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.classList.remove('open');
        }
    });
});

var activeTab = '<?= $tab; ?>';

function setActiveTab(tab) {
    var tabs = document.querySelectorAll('.filter-tab');
    tabs.forEach(function(tabEl) {
        tabEl.classList.toggle('is-active', tabEl.getAttribute('data-tab') === tab);
    });

    var sections = {
        all: ['section-pending', 'section-approved', 'section-rejected', 'section-archived'],
        pending: ['section-pending'],
        approved: ['section-approved'],
        rejected: ['section-rejected'],
        archived: ['section-archived'],
    };

    var visible = sections[tab] || sections.all;

    ['section-pending', 'section-approved', 'section-rejected', 'section-archived'].forEach(function(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.style.display = visible.includes(id) ? '' : 'none';
    });

    activeTab = tab;
}

document.querySelectorAll('.filter-tab').forEach(function(tab) {
    tab.addEventListener('click', function(e) {
        e.preventDefault();
        var selected = this.getAttribute('data-tab');
        if (!selected) return;
        setActiveTab(selected);
    });
});

// initialize (in case server-side default differs)
setActiveTab(activeTab);
</script>

</body>
</html>
