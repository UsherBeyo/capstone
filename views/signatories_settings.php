<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/database.php';

if (!in_array($_SESSION['role'] ?? '', ['personnel','admin','hr'], true)) {
    die("Access denied");
}

$db = (new Database())->connect();

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$stmt = $db->query("SELECT id, key_name, name, position FROM system_signatories ORDER BY id ASC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$labels = [
    'certification' => '7.A Certification of Leave Credits',
    'final_approver' => '7.C Final Approver',
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Signatories Settings</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="content">
    <h2>Signatories Settings</h2>

    <div class="card">
        <p style="margin-bottom:16px;color:#6b7280;">
            These default signatories will auto-fill the print form modal for Personnel, HR, and Admin.
        </p>

        <form method="POST" action="../controllers/update_signatories.php">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? ''); ?>">

            <table width="100%">
                <tr>
                    <th>Section</th>
                    <th>Name</th>
                    <th>Position</th>
                </tr>

                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <?= e($labels[$r['key_name']] ?? $r['key_name']); ?>
                            <input type="hidden" name="id[]" value="<?= (int)$r['id']; ?>">
                        </td>
                        <td>
                            <input type="text" name="name[]" value="<?= e($r['name']); ?>" required>
                        </td>
                        <td>
                            <input type="text" name="position[]" value="<?= e($r['position']); ?>" required>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <div style="margin-top:18px;">
                <button type="submit">Save Changes</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>