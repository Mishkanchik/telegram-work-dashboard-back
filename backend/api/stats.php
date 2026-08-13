<?php
// backend/api/stats.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$user_id = $_GET['user_id'] ?? 0;
$month = $_GET['month'] ?? null;

if (!$user_id) {
    echo json_encode(['error' => 'user_id required']);
    exit;
}

// Отримуємо дані
$stats = getUserStats($user_id, $month);
$user = getUserById($user_id);
$dailyHours = getUserDailyHours($user_id, 30, $month);
$shifts = getUserShifts($user_id, $month, null, 20);
$achievements = getUserAchievements($user_id);

echo json_encode([
    'stats' => [
        'total_shifts' => (int)($stats['total_shifts'] ?? 0),
        'total_hours' => (float)($stats['total_hours'] ?? 0),
        'avg_hours' => (float)($stats['avg_hours'] ?? 0),
        'morning_shifts' => (int)($stats['morning_shifts'] ?? 0),
        'evening_shifts' => (int)($stats['evening_shifts'] ?? 0),
    ],
    'user' => [
        'full_name' => $user['full_name'] ?? 'Користувач',
        'role' => $user['role'] ?? 'worker',
        'referral_code' => $user['referral_code'] ?? '',
    ],
    'daily_hours' => $dailyHours,
    'shifts' => $shifts,
    'achievements' => $achievements
]);