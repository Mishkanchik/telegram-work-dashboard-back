<?php
require_once __DIR__ . '/../../functions.php';

corsHeaders();
initDB();

$telegramId = $_GET['telegram_id'] ?? null;
if (!$telegramId) {
    jsonResponse(['error' => 'telegram_id required'], 400);
}

$user = getUserByTelegramId($telegramId);
if (!$user) {
    jsonResponse(['error' => 'User not found'], 404);
}

$activeSession = getActiveSession($user['id']);

jsonResponse([
    'user' => [
        'id' => $user['id'],
        'telegram_id' => $user['telegram_id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
        'referral_code' => $user['referral_code'],
        'registered_at' => $user['registered_at'],
        'last_activity' => $user['last_activity']
    ],
    'active_session' => $activeSession ? [
        'id' => $activeSession['id'],
        'shift_type' => $activeSession['shift_type'],
        'start_timestamp' => $activeSession['start_timestamp'],
        'last_updated' => $activeSession['last_updated']
    ] : null
]);