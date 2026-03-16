<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/database.php';

if (!in_array($_SESSION['role'], ['admin','manager','hr'])) {
    die("Access denied");
}

$db = (new Database())->connect();
$hols = $db->query("SELECT * FROM holidays ORDER BY holiday_date")->fetchAll(PDO::FETCH_ASSOC);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Holidays</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="app-main">
    <?php
    $title = 'Manage Holidays';
    include __DIR__ . '/partials/ui/page-header.php';
    ?>
    <div class="ui-card">
        <h2>Manage Holidays</h2>
        <form method="POST" action="../controllers/HolidayController.php">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
            <div class="form-group">
                <label>Date</label>
                <input type="date" name="date" required class="form-control">
            </div>
            <div class="form-group">
                <label>Type</label>
                <select name="type" class="form-select">
                    <option value="Non-working Holiday">Non-working Holiday</option>
                    <option value="Special Working Holiday">Special Working Holiday</option>
                    <option value="Company Event">Company Event</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" name="description" class="form-control">
            </div>
            <button type="submit" name="add" class="btn btn-primary">Add</button>
        </form>
        <div class="table-wrap" style="margin-top:24px;">
            <table class="ui-table">
                <thead>
                <tr><th>Date</th><th>Description</th><th>Type</th><th>Action</th></tr>
                </thead>
                <tbody>
            <?php foreach($hols as $h): ?>
            <tr>
                <td><?= $h['holiday_date']; ?></td>
                <td><?= htmlspecialchars($h['description'] ?? ''); ?></td>
                <td><?= htmlspecialchars($h['type'] ?? 'Other'); ?></td>
                <td>
                    <form method="POST" action="../controllers/HolidayController.php" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="id" value="<?= $h['id']; ?>">
                        <input type="hidden" name="update" value="1">
                        <input type="date" name="date" value="<?= $h['holiday_date']; ?>" style="width:120px;padding:4px;" required>
                        <input type="text" name="description" value="<?= htmlspecialchars($h['description'] ?? ''); ?>" style="width:160px;padding:4px;">
                        <select name="type" style="width:140px;padding:4px;">
                            <option value="Non-working Holiday" <?= ($h['type'] === 'Non-working Holiday' ? 'selected' : ''); ?>>Non-working Holiday</option>
                            <option value="Special Working Holiday" <?= ($h['type'] === 'Special Working Holiday' ? 'selected' : ''); ?>>Special Working Holiday</option>
                            <option value="Company Event" <?= ($h['type'] === 'Company Event' ? 'selected' : ''); ?>>Company Event</option>
                            <option value="Other" <?= ($h['type'] === 'Other' || empty($h['type']) ? 'selected' : ''); ?>>Other</option>
                        </select>
                        <button type="submit" style="padding:4px 8px;">Update</button>
                    </form>
                    <form method="POST" action="../controllers/HolidayController.php" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="id" value="<?= $h['id']; ?>">
                        <button type="submit" name="delete" style="padding:4px 8px;">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
