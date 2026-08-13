<?php
// api/user.php
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

if (!$user_id) {
    echo json_encode(['error' => 'user_id required']);
    exit;
}

$user = getUserById($user_id);

if (!$user) {
    echo json_encode(['error' => 'User not found']);
    exit;
}

// Активна сесія
$activeSession = getActiveSession($user['id']);

echo json_encode([
    'user' => [
        'id' => $user['id'],
        'telegram_id' => $user['telegram_id'],
        'username' => $user['username'] ?? '',
        'full_name' => $user['full_name'] ?? 'Користувач',
        'role' => $user['role'] ?? 'worker',
        'referral_code' => $user['referral_code'] ?? '',
        'registered_at' => $user['registered_at'] ?? '',
    ],
    'active_session' => $activeSession ? [
        'id' => $activeSession['id'],
        'shift_type' => $activeSession['shift_type'],
        'start_timestamp' => $activeSession['start_timestamp'],
        'last_updated' => $activeSession['last_updated']
    ] : null
]);
