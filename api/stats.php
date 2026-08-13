<?php
// api/stats.php
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

$stats = getUserStats($user['id']);
$dailyHours = getUserDailyHours($user['id'], 30);
$shifts = getUserShifts($user['id'], null, null, 20);
$achievements = getUserAchievements($user['id']);

// Отримуємо статистику за поточний місяць
$month = date('Y-m');
$monthStats = getUserStats($user['id'], $month);

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
    'stats' => [
        'total_shifts' => (int)($stats['total_shifts'] ?? 0),
        'total_hours' => round($stats['total_hours'] ?? 0, 1),
        'avg_hours' => round($stats['avg_hours'] ?? 0, 1),
        'morning_shifts' => (int)($stats['morning_shifts'] ?? 0),
        'evening_shifts' => (int)($stats['evening_shifts'] ?? 0),
        'month_shifts' => (int)($monthStats['total_shifts'] ?? 0),
        'month_hours' => round($monthStats['total_hours'] ?? 0, 1),
    ],
    'daily_hours' => $dailyHours,
    'shifts' => $shifts,
    'achievements' => $achievements
]);
