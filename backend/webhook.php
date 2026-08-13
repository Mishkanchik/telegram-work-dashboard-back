<?php
// backend/webhook.php - Telegram Webhook Handler

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Логування
function logWebhook($message) {
    $logFile = __DIR__ . '/logs/webhook.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Відправка повідомлення в Telegram
function sendMessage($chatId, $text, $keyboard = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}

// Створення клавіатури
function getMainKeyboard($isAdmin = false) {
    $buttons = [
        [
            ['text' => '🔄 Зміна (7-15)', 'callback_data' => 'shift_morning'],
            ['text' => '🔄 Зміна (15-23)', 'callback_data' => 'shift_evening']
        ],
        [
            ['text' => '▶️ Почати зміну', 'callback_data' => 'start_shift']
        ],
        [
            ['text' => '📊 Статистика', 'callback_data' => 'stats'],
            ['text' => '📋 Мої зміни', 'callback_data' => 'my_shifts']
        ],
        [
            ['text' => '📤 Реферальне посилання', 'callback_data' => 'referral']
        ]
    ];
    
    if ($isAdmin) {
        $buttons[] = [
            ['text' => '🔐 Admin Panel', 'callback_data' => 'admin_panel']
        ];
    }
    
    return ['inline_keyboard' => $buttons];
}

// Отримання тіла запиту
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    logWebhook('Invalid update');
    exit;
}

logWebhook('Received: ' . $input);

// Обробка повідомлень
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    $username = $message['from']['username'] ?? '';
    $fullName = trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? ''));
    
    // Команда /start
    if (isset($message['text']) && strpos($message['text'], '/start') === 0) {
        $parts = explode(' ', $message['text']);
        $referralCode = $parts[1] ?? null;
        
        // Перевіряємо чи існує користувач
        $user = getUserByTelegramId($userId);
        
        if (!$user) {
            // Створюємо нового користувача
            $isAdmin = false;
            $adminIds = explode(',', getenv('ADMIN_IDS') ?: '');
            if (in_array($userId, $adminIds)) {
                $isAdmin = true;
            }
            
            $newUserId = createUser($userId, $username, $fullName, $referralCode);
            $user = getUserById($newUserId);
            
            if ($isAdmin) {
                $db = getDB();
                $stmt = $db->prepare('UPDATE users SET role = "admin" WHERE id = ?');
                $stmt->execute([$newUserId]);
                $user['role'] = 'admin';
            }
            
            sendMessage($chatId, "👋 Вітаю, <b>{$fullName}</b>!\n\nВи успішно зареєстровані в WorkTracker.\n\nОберіть дію:", getMainKeyboard($user['role'] === 'admin'));
        } else {
            sendMessage($chatId, "👋 Привіт, <b>{$fullName}</b>!\n\nОберіть дію:", getMainKeyboard($user['role'] === 'admin'));
        }
    }
}

// Обробка callback запитів
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chatId = $callback['message']['chat']['id'];
    $userId = $callback['from']['id'];
    $data = $callback['data'];
    
    $user = getUserByTelegramId($userId);
    
    if (!$user) {
        sendMessage($chatId, '❌ Спочатку натисніть /start');
        exit;
    }
    
    $response = '';
    $keyboard = null;
    
    switch ($data) {
        case 'shift_morning':
            $response = '🌅 Обрано ранкову зміну (7-15). Натисніть "Почати зміну".';
            break;
            
        case 'shift_evening':
            $response = '🌇 Обрано вечірню зміну (15-23). Натисніть "Почати зміну".';
            break;
            
        case 'start_shift':
            $session = getActiveSession($user['id']);
            if ($session) {
                $startTime = new DateTime($session['start_timestamp']);
                $now = new DateTime();
                $elapsed = $now->getTimestamp() - $startTime->getTimestamp();
                $hours = floor($elapsed / 3600);
                $minutes = floor(($elapsed % 3600) / 60);
                
                $response = "⏱ Ви вже працюєте: {$hours} год {$minutes} хв\n\n";
                $response .= "Натисніть щоб завершити:";
                
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '⏹ Закінчити зміну', 'callback_data' => 'end_shift']]
                    ]
                ];
            } else {
                // Визначаємо тип зміни за часом
                $hour = (int)date('H');
                $shiftType = ($hour >= 7 && $hour < 15) ? 'morning' : 'evening';
                
                $result = startShift($user['id'], $shiftType);
                
                if (isset($result['success'])) {
                    $response = "✅ Зміну почато!\n\nТип: " . ($shiftType === 'morning' ? '🌅 Ранкова' : '🌇 Вечірня') . "\n\n";
                    $response .= "Не забудьте завершити зміну!";
                } else {
                    $response = '❌ ' . ($result['error'] ?? 'Помилка');
                }
            }
            break;
            
        case 'end_shift':
            $result = endShift($user['id']);
            
            if (isset($result['success'])) {
                $response = "✅ Зміну завершено!\n\n⏱ Відпрацьовано: {$result['total_hours']} годин";
            } else {
                $response = '❌ ' . ($result['error'] ?? 'Помилка');
            }
            break;
            
        case 'stats':
            $stats = getUserStats($user['id']);
            $response = "📊 <b>Ваша статистика</b>\n\n";
            $response .= "📋 Змін: {$stats['total_shifts']}\n";
            $response .= "⏱ Годин: " . round($stats['total_hours'], 1) . "\n";
            $response .= "📈 Середня зміна: " . round($stats['avg_hours'], 1) . " год\n";
            $response .= "🌅 Ранкових: {$stats['morning_shifts']}\n";
            $response .= "🌇 Вечірніх: {$stats['evening_shifts']}\n\n";
            $response .= "<a href=\"" . WEBAPP_URL . "/stats.html?user_id={$user['id']}\">🔍 Детальна статистика</a>";
            break;
            
        case 'my_shifts':
            $shifts = getUserShifts($user['id'], null, 5);
            $response = "📋 <b>Останні зміни:</b>\n\n";
            
            if (empty($shifts)) {
                $response .= "Змін поки немає";
            } else {
                foreach ($shifts as $shift) {
                    $type = $shift['shift_type'] === 'morning' ? '🌅' : '🌇';
                    $hours = round($shift['total_hours'], 1);
                    $response .= "{$type} {$shift['date']} - {$hours} год\n";
                }
            }
            break;
            
        case 'referral':
            $refCode = $user['referral_code'] ?: generateReferralCode($user['id']);
            $refLink = "https://t.me/" . BOT_USERNAME . "?start={$refCode}";
            $response = "📤 <b>Ваше реферальне посилання:</b>\n\n{$refLink}\n\n";
            $response .= "Запрошуйте колег приєднатися!";
            break;
            
        case 'admin_panel':
            if ($user['role'] === 'admin') {
                $response = "🔐 <b>Admin Panel</b>\n\n";
                $response .= "<a href=\"" . WEBAPP_URL . "/admin.html\">Відкрити панель</a>";
            } else {
                $response = "❌ Доступ заборонено";
            }
            break;
    }
    
    if ($response) {
        sendMessage($chatId, $response, $keyboard);
    }
}

echo 'OK';