<?php
session_start();
require_once '../helpers/Flash.php';
$flashMessages = flash_get_all();
// prevent caching of login page
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!empty($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - Leave System</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="icon" type="image/jpeg" href="../pictures/DEPED.jpg">

<script>
    const sessionFlashMessages = <?= json_encode($flashMessages, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    function showToast(message, type = 'info', duration = 3500) {
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

        setTimeout(function() {
            toast.classList.add('removing');
            setTimeout(function() {
                toast.remove();
            }, 300);
        }, duration);
    }

    function renderFlashMessages() {
        var params = new URLSearchParams(window.location.search);
        var cleaned = false;

        if (Array.isArray(sessionFlashMessages)) {
            sessionFlashMessages.forEach(function(item) {
                if (item && item.message) {
                    showToast(item.message, item.type || 'info');
                }
            });
        }

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
        if (params.has('toast_info')) {
            showToast(decodeURIComponent(params.get('toast_info')), 'info');
            cleaned = true;
        }

        if (cleaned) {
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    }
</script>

</head>
<body class="login-page">

<div class="login-shell">
    <div class="login-brand-panel">
        <div class="login-brand-badge">Leave Management System</div>
        <h1 class="login-brand-title">Welcome back</h1>
        <p class="login-brand-text">Sign in to manage leave requests, balances, approvals, and employee records with clear transaction feedback.</p>
    </div>

    <div class="ui-card login-card">
        <div class="login-card-head">
            <h2>Login</h2>
            <p>Use your work account to continue.</p>
        </div>

        <?php if(isset($_GET['error'])): ?>
            <div class="login-inline-alert error">
                <div class="login-inline-alert-icon">!</div>
                <div>
                    <strong>Login failed</strong>
                    <span>Invalid credentials. Please check your email and password, then try again.</span>
                </div>
            </div>
        <?php endif; ?>

        <form action="../controllers/AuthController.php" method="POST" onsubmit="return validateLogin();" class="login-form">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">

        <label>Email</label>
        <input type="email" name="email" required class="login-input" placeholder="Enter your email">

        <label>Password</label>
        <input type="password" name="password" required class="login-input" placeholder="Enter your password">

        <div class="login-privacy-row">
            <input type="checkbox" id="agreePrivacy" name="agree_privacy" required>
            <label for="agreePrivacy">I agree to the <a href="#" onclick="openPrivacyModal(event)">Data Privacy and Terms</a></label>
        </div>

        <button type="submit" class="login-submit-btn">Login</button>
    </form>
    </div>
</div>

<!-- Privacy/Terms Modal -->
<div id="privacyModal" class="modal" style="display:none;">
    <div class="modal-content">
        <span class="modal-close" id="closePrivacyModal">&times;</span>
        <h3>Data Privacy & Terms of Service</h3>
        <div style="max-height:400px;overflow-y:auto;font-size:13px;line-height:1.6;">
            <h4>1. Data Privacy Notice</h4>
            <p>We collect and process personal information including your name, email, and employment details. This information is used solely for leave management and HR administration purposes.</p>
            
            <h4>2. Data Protection</h4>
            <p>Your data is protected with industry-standard security measures. We do not share your personal information with third parties without your consent, except as required by law.</p>
            
            <h4>3. Use of Information</h4>
            <p>Leave records, including dates and reasons, are maintained for business and regulatory compliance purposes. Leave balances and history are accessible to authorized HR and management personnel only.</p>
            
            <h4>4. Retention</h4>
            <p>Employment and leave records are retained for the duration of your employment and for a period thereafter as required by applicable laws.</p>
            
            <h4>5. Your Rights</h4>
            <p>You have the right to access, correct, or request deletion of your personal data, subject to legal and contractual obligations.</p>
            
            <h4>6. Terms of Use</h4>
            <p>By logging in, you agree to use this system in accordance with company policies and applicable laws. Unauthorized access, data tampering, or misuse is prohibited.</p>
            
            <h4>7. Disclaimer</h4>
            <p>The leave management system is provided on an "as-is" basis. We are not liable for any data loss or system downtime beyond our control.</p>
            
            <h4>8. Changes to Policy</h4>
            <p>We reserve the right to update this policy. Continued use of the system constitutes acceptance of any changes.</p>
        </div>
        <div style="text-align:right;margin-top:16px;">
            <button type="button" id="closePrivacyBtn" style="padding:8px 16px;">Close</button>
        </div>
    </div>
</div>

<script>
    function openPrivacyModal(e) {
        e.preventDefault();
        document.getElementById('privacyModal').style.display = 'flex';
    }
    document.getElementById('closePrivacyModal').addEventListener('click', function(){
        document.getElementById('privacyModal').style.display = 'none';
    });
    document.getElementById('closePrivacyBtn').addEventListener('click', function(){
        document.getElementById('privacyModal').style.display = 'none';
    });
    window.addEventListener('click', function(e){
        var modal = document.getElementById('privacyModal');
        if(e.target === modal) modal.style.display = 'none';
    });

    document.addEventListener('DOMContentLoaded', renderFlashMessages);

    function validateLogin() {
        if (!document.getElementById('agreePrivacy').checked) {
            alert('You must agree to the Data Privacy and Terms to login.');
            return false;
        }
        return true;
    }
</script>

</body>
</html>
