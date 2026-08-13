<?php
require_once __DIR__ . '/config.php';

$url = "https://api.telegram.org/bot" . BOT_TOKEN . "/setWebhook";
$data = [
    'url' => WEBHOOK_URL,
    'allowed_updates' => json_encode(['message', 'callback_query']),
    'drop_pending_updates' => true
];

$response = file_get_contents($url . '?' . http_build_query($data));
$result = json_decode($response, true);

echo "<pre>";
print_r($result);
echo "</pre>";