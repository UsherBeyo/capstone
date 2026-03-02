<?php
// sidebar.php - include this at the top of any view needing navigation
if (!isset($_SESSION)) session_start();
// prevent cached pages after logout
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
// require authentication
if (empty($_SESSION['user_id'])) {
    header('Location: ../views/login.php');
    exit();
}
$role = $_SESSION['role'] ?? '';

// establish database connection if not already available
if (!isset($db)) {
    require_once __DIR__ . '/../../config/database.php';
    $db = (new Database())->connect();
}
?>
<div class="topbar">
    <div class="topbar-left"></div> <!-- title moved to sidebar -->
    <div class="topbar-right">
        <?php
        // show profile icon with dropdown
        $empRecord = null;
        $displayName = $_SESSION['email'] ?? 'User';
        if (!empty($_SESSION['emp_id'])) {
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE id = ?");
            $stmt->execute([$_SESSION['emp_id']]);
            $empRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($empRecord) {
                $displayName = $empRecord['first_name'] . ' ' . $empRecord['last_name'];
            }
        }
        ?>
        <button class="theme-toggle" id="themeToggle" title="Toggle theme">🌓</button>
        <div class="profile-dropdown">
            <button class="topbar-profile-btn" onclick="toggleProfileMenu()" title="Profile">👤</button>
            <div id="profileMenu" class="profile-menu" style="display:none;">
                <div class="profile-menu-header"><?= htmlspecialchars($displayName); ?></div>
                <?php if ($empRecord): ?>
                    <a href="employee_profile.php?id=<?= htmlspecialchars($empRecord['id']); ?>">My Profile</a>
                <?php endif; ?>
                <a href="#" onclick="openSettings();return false;">Settings</a>
                <a href="../controllers/logout.php">Logout</a>
            </div>
        </div>
    </div>
</div>

<div class="sidebar">
    <h2 class="sidebar-title">Leave System</h2>
     
    <a href="dashboard.php">Dashboard</a>
    <?php if(in_array($role,['employee','manager','hr','admin'])): ?>
        <a href="calendar.php">Leave Calendar</a>
    <?php endif; ?>
    <?php if($role == 'employee'): ?>
        <a href="apply_leave.php">Apply Leave</a>
    <?php endif; ?>
    <?php if($role == 'admin'): ?>
        <a href="manage_employees.php">Manage Employees</a>
    <?php endif; ?>
    <?php if(in_array($role,['admin','manager','hr'])): ?>
        <a href="holidays.php">Manage Holidays</a>
    <?php endif; ?>
    <?php if(in_array($role,['admin','hr'])): ?>
        <a href="reports.php">Reports</a>
    <?php endif; ?>
    <?php if($role == 'admin'): ?>
        <a href="manage_accruals.php">Manage Accruals</a>
        <a href="leave_requests.php">Leave Requests</a>
        <?php if(in_array(
            $_SESSION['role'], ['admin','hr']
        )): ?>
            <a href="manage_leave_types.php">Leave Types</a>
        <?php endif; ?>
    <?php endif; ?>
    <a href="../controllers/logout.php">Logout</a>
</div>

<script>
function toggleProfileMenu() {
    var menu = document.getElementById('profileMenu');
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
}
function openSettings() {
    // redirect to settings or change-password placeholder
    window.location.href = 'change_password.php';
}

// theme toggle
(function(){
    var btn = document.getElementById('themeToggle');
    function setTheme(theme) {
        if(theme === 'light') {
            document.body.classList.add('light-theme');
        } else {
            document.body.classList.remove('light-theme');
        }
        localStorage.setItem('theme', theme);
    }
    var saved = localStorage.getItem('theme') || 'dark';
    setTheme(saved);
    if(btn) btn.addEventListener('click', function(){
        var cur = document.body.classList.contains('light-theme') ? 'light' : 'dark';
        setTheme(cur === 'light' ? 'dark' : 'light');
    });
})();

// close menu if clicked outside
window.addEventListener('click', function(e){
    var menu = document.getElementById('profileMenu');
    var btn = document.querySelector('.topbar-profile-btn');
    if(menu && btn && !menu.contains(e.target) && !btn.contains(e.target)) {
        menu.style.display = 'none';
    }
});

// Toast notification function - global for all pages
function showToast(message, type = 'info', duration = 3000) {
    var container = document.getElementById('notificationContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'notificationContainer';
        document.body.appendChild(container);
    }
    
    var toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.textContent = message;
    container.appendChild(toast);
    
    // Auto remove after duration
    setTimeout(function() {
        toast.classList.add('removing');
        setTimeout(function() {
            toast.remove();
        }, 300);
    }, duration);
}

// Check for flash messages from query parameters
function checkFlashMessage() {
    var params = new URLSearchParams(window.location.search);
    if (params.has('toast_success')) {
        showToast(decodeURIComponent(params.get('toast_success')), 'success');
        window.history.replaceState({}, document.title, window.location.pathname);
    } else if (params.has('toast_error')) {
        showToast(decodeURIComponent(params.get('toast_error')), 'error');
        window.history.replaceState({}, document.title, window.location.pathname);
    } else if (params.has('toast_warning')) {
        showToast(decodeURIComponent(params.get('toast_warning')), 'warning');
        window.history.replaceState({}, document.title, window.location.pathname);
    } else if (params.has('added_history')) {
        showToast('Historical entry added successfully!', 'success');
        window.history.replaceState({}, document.title, window.location.pathname);
    } else if (params.has('undertime')) {
        showToast('Undertime recorded successfully!', 'success');
        window.history.replaceState({}, document.title, window.location.pathname);
    }
}

document.addEventListener('DOMContentLoaded', checkFlashMessage);
</script>