<?php
// backend/api/user.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

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

echo json_encode([
    'user' => [
        'id' => $user['id'],
        'full_name' => $user['full_name'],
        'username' => $user['username'],
        'role' => $user['role'],
        'referral_code' => $user['referral_code'],
        'registered_at' => $user['registered_at']
    ]
]);