<?php
// api/shifts.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$user_id = $_GET['user_id'] ?? 0;
$limit = $_GET['limit'] ?? 30;

if (!$user_id) {
    echo json_encode(['error' => 'user_id required']);
    exit;
}

$shifts = getUserShifts($user_id, null, null, $limit);

echo json_encode($shifts);
