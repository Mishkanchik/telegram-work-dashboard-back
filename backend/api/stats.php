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

$stats = getUserStats($user['id']);
$achievements = getAchievements($user['id']);

jsonResponse([
    'user' => [
        'id' => $user['id'],
        'telegram_id' => $user['telegram_id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'referral_code' => $user['referral_code'],
        'registered_at' => $user['registered_at']
    ],
    'stats' => [
        'total_shifts' => (int)($stats['total_shifts'] ?? 0),
        'total_hours' => round($stats['total_hours'] ?? 0, 1),
        'avg_hours' => round($stats['avg_hours'] ?? 0, 1),
        'morning_shifts' => (int)($stats['morning_shifts'] ?? 0),
        'evening_shifts' => (int)($stats['evening_shifts'] ?? 0),
        'month_shifts' => (int)($stats['month_shifts'] ?? 0),
        'month_hours' => round($stats['month_hours'] ?? 0, 1)
    ],
    'achievements' => $achievements
]);