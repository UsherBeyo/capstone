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
?>
<div class="topbar">
    <div class="topbar-left">Leave System</div>
    <div class="topbar-right">
        <?php
        // show small profile picture with dropdown
        $empRecord = null;
        if (!empty($_SESSION['emp_id'])) {
            $stmt = $db->prepare("SELECT id, first_name, last_name, profile_pic FROM employees WHERE id = ?");
            $stmt->execute([$_SESSION['emp_id']]);
            $empRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $displayPic = $empRecord['profile_pic'] ?? '';
        $displayName = ($empRecord['first_name'] ?? $_SESSION['email']) . ' ' . ($empRecord['last_name'] ?? '');
        ?>
        <div class="profile-dropdown">
            <img src="<?= htmlspecialchars($displayPic ?: '../assets/images/default-avatar.png'); ?>" class="topbar-avatar" onclick="toggleProfileMenu()" alt="Profile">
            <button class="theme-toggle" id="themeToggle" title="Toggle theme">🌓</button>
            <div id="profileMenu" class="profile-menu" style="display:none;">
                <div class="profile-menu-header"><?= htmlspecialchars($displayName); ?></div>
                <a href="employee_profile.php?id=<?= htmlspecialchars($empRecord['id'] ?? ''); ?>">My Profile</a>
                <a href="#" onclick="openSettings();return false;">Settings</a>
                <a href="../controllers/logout.php">Logout</a>
            </div>
        </div>
    </div>
</div>

<div class="sidebar">
    <h3>Menu</h3>
    <a href="dashboard.php">Dashboard</a>
    <?php if(in_array($role,['employee','manager','hr'])): ?>
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
    var avatar = document.querySelector('.topbar-avatar');
    if(menu && avatar && !menu.contains(e.target) && !avatar.contains(e.target)) {
        menu.style.display = 'none';
    }
});
</script>