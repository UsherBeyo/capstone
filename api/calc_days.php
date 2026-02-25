<?php
// returns JSON with deductible days between two dates excluding weekends and holidays
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
$start = $_GET['start'] ?? null;
$end = $_GET['end'] ?? null;
if (!$start || !$end) {
    echo json_encode(['error' => 'missing parameters']);
    exit;
}

$db = (new Database())->connect();

// reuse logic from Leave model by including it
require_once __DIR__ . '/../models/Leave.php';
$leave = new Leave($db);
$days = $leave->calculateDays($start, $end);

echo json_encode(['days' => $days]);
