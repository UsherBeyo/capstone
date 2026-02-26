<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = (new Database())->connect();
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// fetch user's first and last name from employee record
$userName = $_SESSION['email'];
if ($role === 'employee') {
    $stmt = $db->prepare("SELECT first_name, last_name FROM employees WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && !empty($user['first_name'])) {
        $userName = $user['first_name'] . ' ' . $user['last_name'];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="content">
    <h2>Welcome <?= htmlspecialchars($userName); ?></h2>

    <?php if(!empty($_SESSION['message'])): ?>
        <div class="card" style="background:#eef; padding:10px;">
            <?= htmlspecialchars($_SESSION['message']); ?>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <?php if($role == 'employee'): ?>

        <?php
        // fetch each balance column and employee id
        $stmt = $db->prepare("SELECT id, annual_balance, sick_balance, force_balance FROM employees WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $balances = $stmt->fetch(PDO::FETCH_ASSOC);
        $annual = $balances['annual_balance'] ?? 0;
        $sick = $balances['sick_balance'] ?? 0;
        $force = $balances['force_balance'] ?? 0;
        $my_emp_id = $balances['id'] ?? null;

        // fetch this employee's own leave requests
        $ownRequests = [];
        if ($my_emp_id) {
            $reqStmt = $db->prepare("SELECT lr.*, COALESCE(lt.name, lr.leave_type) AS leave_type_name
                FROM leave_requests lr
                LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
                WHERE employee_id = ? ORDER BY start_date DESC");
            $reqStmt->execute([$my_emp_id]);
            $ownRequests = $reqStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        ?>

        <div class="card" style="margin:20px auto;max-width:800px;">
            <h3 style="text-align:center;">Leave Balances</h3>
            <div style="display:flex;gap:20px;flex-wrap:wrap;max-width:100%;align-items:center;justify-content:center;">
                <div style="flex:1;min-width:250px;max-width:300px;height:200px;">
                    <canvas id="annualChart"></canvas>
                </div>
                <div style="flex:1;min-width:250px;max-width:300px;height:200px;">
                    <canvas id="sickChart"></canvas>
                </div>
                <div style="flex:1;min-width:250px;max-width:300px;height:200px;">
                    <canvas id="forceChart"></canvas>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top:20px;">
            <h3>My Leave Requests</h3>
            <?php if(empty($ownRequests)): ?>
                <p>No leave requests submitted yet.</p>
            <?php else: ?>
                <?php 
                $pending = array_filter($ownRequests, function($r) { return $r['status'] === 'pending'; });
                $archived = array_filter($ownRequests, function($r) { return $r['status'] !== 'pending'; });
                ?>
                
                <?php if(!empty($pending)): ?>
                <div style="margin-bottom:20px;">
                    <h4>Pending Requests</h4>
                    <table border="1" width="100%" style="margin-bottom:10px;">
                        <tr>
                            <th>Type</th>
                            <th>Dates</th>
                            <th>Days</th>
                            <th>Status</th>
                            <th>Manager Notes</th>
                        </tr>
                        <?php foreach($pending as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['leave_type_name'] ?? $r['leave_type']); ?></td>
                            <td><?= $r['start_date'].' to '.$r['end_date']; ?></td>
                            <td><?= $r['total_days']; ?></td>
                            <td><?= ucfirst($r['status']); ?></td>
                            <td><?= htmlspecialchars($r['manager_comments'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <?php endif; ?>
                
                <?php if(!empty($archived)): ?>
                <div>
                    <h4><a href="#" class="dropdown-toggle" onclick="document.getElementById('archiveSection').style.display = document.getElementById('archiveSection').style.display === 'none' ? 'block' : 'none'; return false;">▼ Archived Requests (?? records)</a></h4>
                    <div id="archiveSection" style="display:none;margin-top:10px;">
                        <table border="1" width="100%">
                            <tr>
                                <th>Type</th>
                                <th>Dates</th>
                                <th>Days</th>
                                <th>Status</th>
                                <th>Manager Notes</th>
                            </tr>
                            <?php foreach($archived as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['leave_type_name'] ?? $r['leave_type']); ?></td>
                                <td><?= $r['start_date'].' to '.$r['end_date']; ?></td>
                                <td><?= $r['total_days']; ?></td>
                                <td><?= ucfirst($r['status']); ?></td>
                                <td><?= htmlspecialchars($r['manager_comments'] ?? ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <script>
            // Employee balance charts
            var ctx1 = document.getElementById('annualChart');
            if (ctx1) {
                var annualContext = ctx1.getContext('2d');
                new Chart(annualContext, {
                    type: 'doughnut',
                    data: {
                        labels: ['Used', 'Remaining'],
                        datasets: [{
                            data: [<?= $annual > 0 ? max(0, 20 - $annual) : 0 ?>, <?= $annual ?>],
                            backgroundColor: ['#ff6384', '#36a2eb']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: '#ffffff'
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.label + ': ' + context.parsed + ' days';
                                    }
                                }
                            },
                            title: {display: true, text: 'Annual Leave', color: '#ffffff'}
                        }
                    }
                });
            }

            var ctx2 = document.getElementById('sickChart');
            if (ctx2) {
                var sickContext = ctx2.getContext('2d');
                new Chart(sickContext, {
                    type: 'doughnut',
                    data: {
                        labels: ['Used', 'Remaining'],
                        datasets: [{
                            data: [<?= $sick > 0 ? max(0, 10 - $sick) : 0 ?>, <?= $sick ?>],
                            backgroundColor: ['#ffc107', '#28a745']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: '#ffffff'
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.label + ': ' + context.parsed + ' days';
                                    }
                                }
                            },
                            title: {display: true, text: 'Sick Leave', color: '#ffffff'}
                        }
                    }
                });
            }

            var ctx3 = document.getElementById('forceChart');
            if (ctx3) {
                var forceContext = ctx3.getContext('2d');
                new Chart(forceContext, {
                    type: 'doughnut',
                    data: {
                        labels: ['Used', 'Remaining'],
                        datasets: [{
                            data: [<?= $force > 0 ? max(0, 5 - $force) : 0 ?>, <?= $force ?>],
                            backgroundColor: ['#dc3545', '#20c997']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: '#ffffff'
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.label + ': ' + context.parsed + ' days';
                                    }
                                }
                            },
                            title: {display: true, text: 'Force Leave', color: '#ffffff'}
                        }
                    }
                });
            }
        </script>

    <?php elseif(in_array($role, ['manager','hr','admin'])): ?>

        <?php
        // analytics: most absent employee
        $mostAbsent = $db->query("SELECT employee_id, COUNT(*) AS cnt FROM leave_requests WHERE status='approved' GROUP BY employee_id ORDER BY cnt DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $mostAbsentName = '';
        if ($mostAbsent) {
            $stmt2 = $db->prepare("SELECT first_name, last_name FROM employees WHERE id = ?");
            $stmt2->execute([$mostAbsent['employee_id']]);
            $e = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($e) {
                $mostAbsentName = $e['first_name'].' '.$e['last_name'];
            }
        }

        // monthly trends
        $monthlyStmt = $db->query("SELECT MONTH(start_date) as m, COUNT(*) as cnt FROM leave_requests WHERE status='approved' GROUP BY MONTH(start_date)");
        $monthlyData = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
        $phpMonthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

        // department counts for chart
        $deptChartStmt = $db->query("SELECT department, COUNT(*) AS cnt FROM employees GROUP BY department");
        $deptChartData = $deptChartStmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="card" style="margin-bottom:20px;">
            <h3>Analytics</h3>
            <?php if($mostAbsent): ?>
                <p><strong>Most absent employee:</strong> <?= htmlspecialchars($mostAbsentName); ?> (<?= $mostAbsent['cnt']; ?> days)</p>
            <?php endif; ?>
            <div style="display:flex;gap:20px;flex-wrap:wrap;max-width:100%;">
                <div style="flex:1;min-width:350px;max-width:600px;">
                    <canvas id="monthlyChart"></canvas>
                </div>
                <div style="flex:1;min-width:350px;max-width:400px;">
                    <canvas id="deptChart"></canvas>
                </div>
            </div>
        </div>
        <script>
            var ctx = document.getElementById('monthlyChart').getContext('2d');
            var chart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: [<?php foreach($monthlyData as $row){ $m = intval($row['m']); echo '"' . ($phpMonthNames[$m-1] ?? $m) . '",'; } ?>],
                    datasets: [{
                        label: 'Approved leaves by month',
                        data: [<?php foreach($monthlyData as $row){ echo ($row['cnt'] . ','); } ?>],
                        backgroundColor: 'rgba(54, 162, 235, 0.5)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        x: {title: {display:true, text:'Month'}},
                        y: {beginAtZero:true}
                    }
                }
            });

            var ctx2 = document.getElementById('deptChart').getContext('2d');
            var deptChart = new Chart(ctx2, {
                type: 'pie',
                data: {
                    labels: [<?php foreach($deptChartData as $d){ echo '"'.htmlspecialchars($d['department']).'",'; } ?>],
                    datasets: [{
                        data: [<?php foreach($deptChartData as $d){ echo ($d['cnt'].','); } ?>],
                        backgroundColor: ['#ff6384','#36a2eb','#ffcd56','#4bc0c0','#9966ff','#ff9f40']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true
                }
            });
        </script>


        <?php
        if ($role === 'manager') {
            $stmt = $db->prepare("
                SELECT lr.*, e.first_name, e.last_name, COALESCE(lt.name, lr.leave_type) AS leave_type_name
                FROM leave_requests lr
                JOIN employees e ON lr.employee_id = e.id
                LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
                WHERE lr.status = 'pending' AND e.manager_id = ?
            ");
            $stmt->execute([$_SESSION['emp_id']]);
        } else {
            // hr sees all pending
            $stmt = $db->prepare("
                SELECT lr.*, e.first_name, e.last_name, COALESCE(lt.name, lr.leave_type) AS leave_type_name
                FROM leave_requests lr
                JOIN employees e ON lr.employee_id = e.id
                LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
                WHERE lr.status = 'pending'
            ");
            $stmt->execute();
        }
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // fetch archived requests
        if ($role === 'manager') {
            $stmt = $db->prepare("
                SELECT lr.*, e.first_name, e.last_name, COALESCE(lt.name, lr.leave_type) AS leave_type_name
                FROM leave_requests lr
                JOIN employees e ON lr.employee_id = e.id
                LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
                WHERE lr.status IN ('approved', 'rejected') AND e.manager_id = ?
                ORDER BY lr.created_at DESC LIMIT 50
            ");
            $stmt->execute([$_SESSION['emp_id']]);
        } else {
            $stmt = $db->prepare("
                SELECT lr.*, e.first_name, e.last_name, COALESCE(lt.name, lr.leave_type) AS leave_type_name
                FROM leave_requests lr
                JOIN employees e ON lr.employee_id = e.id
                LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
                WHERE lr.status IN ('approved', 'rejected')
                ORDER BY lr.created_at DESC LIMIT 100
            ");
            $stmt->execute();
        }
        $archived = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <script>
            function askRejectReason(form) {
                var reason = prompt('Please enter a reason for rejection:');
                if (reason === null) return false;
                form.comments.value = reason;
                return true;
            }
        </script>

        <div class="card">
            <h3>Pending Leave Requests</h3>

            <table border="1" width="100%">
                <tr>
                    <th>Employee</th>
                    <th>Type</th>
                    <th>Dates</th>
                    <th>Days</th>
                    <th>Reason</th>
                    <th>Action</th>
                </tr>

                <?php foreach($requests as $r): ?>
                <tr>
                    <td><?= $r['first_name']." ".$r['last_name']; ?></td>
                    <td><?= htmlspecialchars($r['leave_type']); ?></td>
                    <td><?= $r['start_date']." to ".$r['end_date']; ?></td>
                    <td><?= $r['total_days']; ?></td>
                    <td><?= htmlspecialchars($r['reason']); ?></td>
                    <td>
                        <form method="POST" action="../controllers/LeaveController.php" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="leave_id" value="<?= $r['id']; ?>">
                            <button type="submit">Approve</button>
                        </form>
                        &nbsp;
                        <form method="POST" action="../controllers/LeaveController.php" style="display:inline;" onsubmit="return askRejectReason(this);">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="leave_id" value="<?= $r['id']; ?>">
                            <input type="hidden" name="comments" value="">
                            <button type="submit">Reject</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>

            </table>
        </div>
        
        <?php if(!empty($archived)): ?>
        <div class="card" style="margin-top:20px;">
            <h3><a href="#" onclick="document.getElementById('archivePanel').style.display = document.getElementById('archivePanel').style.display === 'none' ? 'block' : 'none'; return false;">▼ Archived Requests (<?= count($archived); ?> records)</a></h3>
            <div id="archivePanel" style="display:none;margin-top:10px;">
                <table border="1" width="100%" style="font-size:12px;">
                    <tr>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Days</th>
                        <th>Status</th>
                        <th>Reason</th>
                    </tr>
                    <?php foreach($archived as $r): ?>
                    <tr>
                        <td><?= $r['first_name']." ".$r['last_name']; ?></td>
                        <td><?= htmlspecialchars($r['leave_type_name'] ?? $r['leave_type']); ?></td>
                        <td><?= $r['start_date']." to ".$r['end_date']; ?></td>
                        <td><?= $r['total_days']; ?></td>
                        <td><?= ucfirst($r['status']); ?></td>
                        <td><?= htmlspecialchars($r['manager_comments'] ?? $r['reason'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>

    <?php elseif($role == 'admin'): ?>
        <div class="card" style="margin-bottom:20px;">
            <a href="change_password.php" class="btn">Change Password</a>
        </div>
        <?php
        // general counts
        $count = $db->query("SELECT COUNT(*) FROM employees")->fetchColumn();
        $pendingCount = $db->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
        $approvedCount = $db->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'approved'")->fetchColumn();
        // by department
        $deptStmt = $db->query("SELECT department, COUNT(*) as cnt FROM employees GROUP BY department");
        $deptData = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
        $deptLabels = array_column($deptData,'department');
        $deptCounts = array_column($deptData,'cnt');
        // by role via users join
        $roleStmt = $db->query("SELECT u.role, COUNT(*) as cnt FROM users u
                               JOIN employees e ON e.user_id = u.id
                               GROUP BY u.role");
        $roleData = $roleStmt->fetchAll(PDO::FETCH_ASSOC);
        $roleLabels = array_column($roleData,'role');
        $roleCounts = array_column($roleData,'cnt');
        ?>

        <div style="display:flex;gap:20px;margin-bottom:20px;">
            <div class="card" style="flex:1;">
                <h3>Total Employees</h3>
                <p style="font-size:24px;color:#00c6ff;"><?= $count ?></p>
            </div>
            <div class="card" style="flex:1;border-left:4px solid #ff6b6b;">
                <h3>Pending Requests</h3>
                <p style="font-size:24px;color:#ff6b6b;"><?= $pendingCount ?></p>
            </div>
            <div class="card" style="flex:1;border-left:4px solid #28a745;">
                <h3>Approved Requests</h3>
                <p style="font-size:24px;color:#28a745;"><?= $approvedCount ?></p>
            </div>
        </div>

        <div class="card" style="margin-top:20px;">
            <h3>Employees by Department</h3>
            <canvas id="deptChart"></canvas>
        </div>

        <div class="card" style="margin-top:20px;">
            <h3>Employees by Role</h3>
            <canvas id="roleChart"></canvas>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function(){
            var ctx1 = document.getElementById('deptChart').getContext('2d');
            new Chart(ctx1, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($deptLabels); ?>,
                    datasets: [{
                        label: 'Employees',
                        data: <?= json_encode($deptCounts); ?>,
                        backgroundColor: 'rgba(0, 123, 255, 0.5)'
                    }]
                }
            });
            var ctx2 = document.getElementById('roleChart').getContext('2d');
            new Chart(ctx2, {
                type: 'pie',
                data: {
                    labels: <?= json_encode($roleLabels); ?>,
                    datasets: [{
                        data: <?= json_encode($roleCounts); ?>,
                        backgroundColor: ['#007bff','#28a745','#ffc107','#dc3545']
                    }]
                }
            });
        });
        </script>

    <?php endif; ?>

</div>

</body>
</html> 