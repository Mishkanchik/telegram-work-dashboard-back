<?php
// backend/api/achievements.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$user_id = $_GET['user_id'] ?? 0;

if (!$user_id) {
    echo json_encode(['error' => 'user_id required']);
    exit;
}

$achievements = getUserAchievements($user_id);

echo json_encode($achievements);