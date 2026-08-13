<?php

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

// Викликаємо функцію. Назва змінної тепер логічна - $stats
$stats = getUserShifts($user_id, null, null, $limit);

// Якщо функція повернула null або false (помилка БД) - повертаємо порожній масив замість помилки
if ($stats === null || $stats === false) {
    $stats = [];
}

// Повертаємо статистику (або зміни, якщо функція getUserShifts повертає їх)
echo json_encode($stats);
?>
