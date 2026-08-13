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

$limit = (int)($_GET['limit'] ?? 50);
$offset = (int)($_GET['offset'] ?? 0);

$shifts = getUserShifts($user['id'], $limit, $offset);

// Format for frontend
$formatted = [];
foreach ($shifts as $shift) {
    $formatted[] = [
        'id' => $shift['id'],
        'date' => $shift['date'],
        'shift_type' => $shift['shift_type'],
        'shift_type_label' => $shift['shift_type'] === 'morning' ? '🌅 Ранкова' : '🌇 Вечірня',
        'start_time' => date('H:i', strtotime($shift['start_time'])),
        'end_time' => $shift['end_time'] ? date('H:i', strtotime($shift['end_time'])) : null,
        'total_hours' => round($shift['total_hours'], 1)
    ];
}

jsonResponse([
    'shifts' => $formatted,
    'pagination' => [
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => count($shifts) === $limit
    ]
]);