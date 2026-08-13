<?php
// backend/api/shifts.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$user_id = $_GET['user_id'] ?? 0;
$limit = $_GET['limit'] ?? 30;
$month = $_GET['month'] ?? null;

if (!$user_id) {
    echo json_encode(['error' => 'user_id required']);
    exit;
}

$shifts = getUserShifts($user_id, $month, null, $limit);

echo json_encode($shifts);