<?php
require_once __DIR__ . '/../../../functions.php';

corsHeaders();
initDB();
requireAuth();

$workers = getAllWorkersStats();
$totalStats = getTotalStats();
$activeShifts = getActiveShiftsNow();
$hourlyActivity = getHourlyActivity();
$shiftComparison = getShiftComparison();

// Format workers for frontend
$formattedWorkers = [];
foreach ($workers as $w) {
    $formattedWorkers[] = [
        'id' => $w['id'],
        'telegram_id' => $w['telegram_id'],
        'username' => $w['username'],
        'full_name' => $w['full_name'],
        'registered_at' => $w['registered_at'],
        'last_activity' => $w['last_activity'],
        'total_shifts' => (int)($w['total_shifts'] ?? 0),
        'total_hours' => round($w['total_hours'] ?? 0, 1),
        'avg_hours' => round($w['avg_hours'] ?? 0, 1),
        'morning_shifts' => (int)($w['morning_shifts'] ?? 0),
        'evening_shifts' => (int)($w['evening_shifts'] ?? 0),
        'productivity' => $w['total_shifts'] > 0 ? round(($w['total_hours'] / $w['total_shifts']) * 10, 1) : 0
    ];
}

// Format active shifts
$formattedActive = [];
foreach ($activeShifts as $s) {
    $start = new DateTime($s['start_timestamp']);
    $now = new DateTime();
    $hours = $start->diff($now)->h + ($start->diff($now)->i / 60);
    $formattedActive[] = [
        'user_id' => $s['user_id'],
        'telegram_id' => $s['telegram_id'],
        'username' => $s['username'],
        'full_name' => $s['full_name'],
        'shift_type' => $s['shift_type'],
        'shift_type_label' => $s['shift_type'] === 'morning' ? '🌅 Ранкова' : '🌇 Вечірня',
        'start_timestamp' => $s['start_timestamp'],
        'current_hours' => round($hours, 1)
    ];
}

jsonResponse([
    'summary' => [
        'total_workers' => $totalStats['total_workers'],
        'active_today' => $totalStats['active_today'],
        'total_hours' => $totalStats['total_hours'],
        'top_worker' => $totalStats['top_worker']
    ],
    'workers' => $formattedWorkers,
    'active_shifts' => $formattedActive,
    'hourly_activity' => $hourlyActivity,
    'shift_comparison' => $shiftComparison
]);