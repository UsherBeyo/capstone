<?php
session_start();
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
</head>
<body style="display:flex;justify-content:center;align-items:center;height:100vh;">

<div class="card" style="width:350px;">
    <h2 style="text-align:center;">Login</h2>

    <?php if(isset($_GET['error'])): ?>
        <p style="color:red;text-align:center;">Invalid credentials</p>
    <?php endif; ?>

    <form action="../controllers/AuthController.php" method="POST" onsubmit="return validateLogin();" style="text-align:left;">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">

        <label>Email</label>
        <input type="email" name="email" required style="width:100%;padding:8px 12px;margin:8px 0;box-sizing:border-box;">

        <label>Password</label>
        <input type="password" name="password" required style="width:100%;padding:8px 12px;margin:8px 0;box-sizing:border-box;">

        <div style="margin:16px 0;display:flex;align-items:center;justify-content:flex-start;gap:8px;width:100%;">
            <input type="checkbox" id="agreePrivacy" name="agree_privacy" required style="margin:0;flex-shrink:0;vertical-align:middle;width:auto;">
            <label for="agreePrivacy" style="margin:0;font-size:14px;line-height:1;">I agree to the <a href="#" onclick="openPrivacyModal(event)" style="color:#00c6ff;text-decoration:underline;">Data Privacy and Terms</a></label>
        </div>

        <br>
        <button type="submit" style="padding:12px 24px;font-size:16px;width:100%;cursor:pointer;">Login</button>
    </form>
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