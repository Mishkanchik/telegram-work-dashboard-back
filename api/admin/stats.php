<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';

// ✅ ПЕРЕВІРКА ПАРОЛЯ
$password = $_GET['password'] ?? '';
if ($password !== ADMIN_PASSWORD) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Отримуємо всіх користувачів
$users = getAllUsers();
$workers = [];

foreach ($users as $user) {
    $stats = getUserStats($user['id']);
    $workers[] = [
        'id' => $user['id'],
        'telegram_id' => $user['telegram_id'],
        'username' => $user['username'] ?? '',
        'full_name' => $user['full_name'] ?? 'Користувач',
        'role' => $user['role'] ?? 'worker',
        'registered_at' => $user['registered_at'] ?? '',
        'total_shifts' => (int)($stats['total_shifts'] ?? 0),
        'total_hours' => round($stats['total_hours'] ?? 0, 1),
        'avg_hours' => round($stats['avg_hours'] ?? 0, 1),
        'morning_shifts' => (int)($stats['morning_shifts'] ?? 0),
        'evening_shifts' => (int)($stats['evening_shifts'] ?? 0),
    ];
}

// Активні зміни
$activeSessions = getAllActiveSessions();
$activeShifts = [];
foreach ($activeSessions as $session) {
    $start = new DateTime($session['start_timestamp']);
    $now = new DateTime();
    $diff = $start->diff($now);
    $hours = $diff->h + ($diff->days * 24) + round($diff->i / 60, 1);
    $activeShifts[] = [
        'user_id' => $session['user_id'],
        'telegram_id' => $session['telegram_id'],
        'username' => $session['username'] ?? '',
        'full_name' => $session['full_name'] ?? 'Користувач',
        'shift_type' => $session['shift_type'],
        'start_timestamp' => $session['start_timestamp'],
        'current_hours' => round($hours, 1)
    ];
}

// Загальна статистика
$totalWorkers = count($users);
$totalShifts = 0;
$totalHours = 0;

foreach ($workers as $w) {
    $totalShifts += $w['total_shifts'];
    $totalHours += $w['total_hours'];
}

// Топ-працівники
$topWorkers = $workers;
usort($topWorkers, function($a, $b) {
    return $b['total_hours'] <=> $a['total_hours'];
});
$topWorkers = array_slice($topWorkers, 0, 5);

echo json_encode([
    'summary' => [
        'total_workers' => $totalWorkers,
        'active_today' => count($activeShifts),
        'total_hours' => round($totalHours, 1),
        'avg_hours' => $totalWorkers > 0 ? round($totalHours / $totalWorkers, 1) : 0,
        'total_shifts' => $totalShifts,
        'month_shifts' => getMonthShiftsCount(),
    ],
    'workers' => $workers,
    'active_shifts' => $activeShifts,
    'top_workers' => $topWorkers,
]);
