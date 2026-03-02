<?php
// header.php - Application header with title and user info
if (!isset($_SESSION)) session_start();

// Get user display name, email, and profile picture
$displayName = $_SESSION['email'] ?? 'User';
$userEmail = $_SESSION['email'] ?? '';
$profilePic = null;
if (!empty($_SESSION['emp_id'])) {
    if (!isset($db)) {
        require_once __DIR__ . '/../../config/database.php';
        $db = (new Database())->connect();
    }
    $stmt = $db->prepare("SELECT first_name, last_name, profile_pic FROM employees WHERE id = ?");
    $stmt->execute([$_SESSION['emp_id']]);
    $empRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($empRecord) {
        $displayName = $empRecord['first_name'] . ' ' . $empRecord['last_name'];
        $profilePic = $empRecord['profile_pic'] ?? null;
    }
}
?>
<header class="app-header">
    <div class="header-container">
        <div class="header-left">
            <h1 class="app-title">Leave System</h1>
        </div>
        <div class="header-right">
            <div class="profile-section">
                <div class="profile-info">
                    <div class="profile-name"><?= htmlspecialchars($displayName); ?></div>
                    <div class="profile-email"><?= htmlspecialchars($userEmail); ?></div>
                </div>
                <button class="profile-button" id="profileButton" onclick="toggleProfileMenu()">
                    <?php if (!empty($profilePic)): ?>
                        <img src="<?= htmlspecialchars($profilePic); ?>" alt="Profile" class="profile-avatar">
                    <?php else: ?>
                        <div class="profile-avatar profile-avatar-placeholder">
                            <span><?= strtoupper(substr($displayName, 0, 1)); ?></span>
                        </div>
                    <?php endif; ?>
                </button>
                <div id="profileMenu" class="profile-menu" style="display: none;">
                    <a href="employee_profile.php?id=<?= $_SESSION['emp_id'] ?? ''; ?>" class="profile-menu-item">Profile</a>
                    <a href="change_password.php" class="profile-menu-item">Settings</a>
                    <hr style="margin: 4px 0; border: none; border-top: 1px solid var(--border);">
                    <a href="../controllers/logout.php" class="profile-menu-item">Logout</a>
                </div>
            </div>
        </div>
    </div>
</header>

<script>
function toggleProfileMenu() {
    var menu = document.getElementById('profileMenu');
    menu.style.display = menu.style.display === 'none' || menu.style.display === '' ? 'block' : 'none';
}

// Close menu when clicking outside
window.addEventListener('click', function(e){
    var menu = document.getElementById('profileMenu');
    var btn = document.getElementById('profileButton');
    var section = document.querySelector('.profile-section');
    if(menu && !section.contains(e.target)) {
        menu.style.display = 'none';
    }
});
</script>
