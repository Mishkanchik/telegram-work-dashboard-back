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

$achievements = getAchievements($user['id']);

jsonResponse(['achievements' => $achievements]);