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
<?php include __DIR__ . '/../layout/header.php'; ?>

<div class="sidebar">
    <nav class="sidebar-nav">
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
    </nav>
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
    var cleaned = false;
    if (params.has('toast_success')) {
        showToast(decodeURIComponent(params.get('toast_success')), 'success');
        cleaned = true;
    }
    if (params.has('toast_error')) {
        showToast(decodeURIComponent(params.get('toast_error')), 'error');
        cleaned = true;
    }
    if (params.has('toast_warning')) {
        showToast(decodeURIComponent(params.get('toast_warning')), 'warning');
        cleaned = true;
    }
    if (params.has('added_history')) {
        showToast('Historical entry added successfully!', 'success');
        cleaned = true;
    }
    if (params.has('undertime')) {
        showToast('Undertime recorded successfully!', 'success');
        cleaned = true;
    }
    if (cleaned) {
        window.history.replaceState({}, document.title, window.location.pathname);
    }
}

document.addEventListener('DOMContentLoaded', checkFlashMessage);
</script>

<script>
// Ensure site favicon uses pictures/DEPED.jpg when sidebar is present
(function(){
    try {
        var href = '../pictures/DEPED.jpg';
        var existing = document.querySelector("link[rel~='icon']");
        if (existing) {
            existing.href = href;
        } else {
            var l = document.createElement('link');
            l.rel = 'icon';
            l.type = 'image/jpeg';
            l.href = href;
            document.getElementsByTagName('head')[0].appendChild(l);
        }
    } catch (e) {
        // silent
    }
})();
</script>