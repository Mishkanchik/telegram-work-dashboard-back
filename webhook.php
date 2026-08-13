<?php
require_once __DIR__ . '/functions.php';

initDB();

// Get raw POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(200);
    exit;
}

// Handle callback queries (button presses)
if (isset($input['callback_query'])) {
    handleCallbackQuery($input['callback_query']);
    http_response_code(200);
    exit;
}

// Handle messages
if (isset($input['message'])) {
    handleMessage($input['message']);
    http_response_code(200);
    exit;
}

http_response_code(200);
exit;

function handleMessage($message) {
    $chatId = $message['chat']['id'];
    $telegramId = $message['from']['id'];
    $username = $message['from']['username'] ?? '';
    $fullName = trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? ''));
    $text = $message['text'] ?? '';
    
    // Check for deep linking (referral)
    $referredByCode = null;
    if (preg_match('/^\/start\s+(.+)$/', $text, $matches)) {
        $payload = $matches[1];
        if (strpos($payload, 'ref_') === 0) {
            $referredByCode = $payload;
        }
    }
    
    $user = getUserByTelegramId($telegramId);
    
    if (!$user) {
        // Create new user
        $userId = createUser($telegramId, $username, $fullName, $referredByCode);
        $user = getUserByTelegramId($telegramId);
        
        sendMessage($chatId, "✅ Вітаю! Ви зареєстровані в WorkTracker.\n\nВаш реферальний код: <code>{$user['referral_code']}</code>\nПосилання для запрошення: https://t.me/" . BOT_USERNAME . "?start={$user['referral_code']}", getMainKeyboard());
    } else {
        // Update last activity
        $db = getDB();
        $db->prepare("UPDATE users SET last_activity = datetime('now'), username = ? WHERE telegram_id = ?")
           ->execute([$username, $telegramId]);
        
        sendMessage($chatId, "👋 З поверненням, {$user['full_name']}!", getMainKeyboard());
    }
}

function handleCallbackQuery($callback) {
    $chatId = $callback['message']['chat']['id'];
    $telegramId = $callback['from']['id'];
    $data = $callback['data'];
    $callbackQueryId = $callback['id'];
    $messageId = $callback['message']['message_id'];
    
    $user = getUserByTelegramId($telegramId);
    if (!$user) {
        answerCallbackQuery($callbackQueryId, '❌ Користувача не знайдено');
        return;
    }
    
    switch ($data) {
        case 'shift_morning':
        case 'shift_evening':
            $shiftType = $data === 'shift_morning' ? 'morning' : 'evening';
            $label = $shiftType === 'morning' ? '🌅 Ранкова (7-15)' : '🌇 Вечірня (15-23)';
            
            // Save selected shift in user data (could use a simple file or DB)
            saveUserSelectedShift($user['id'], $shiftType);
            
            editMessageText($chatId, $messageId, "✅ Вибрано: <b>$label</b>\n\nНатисніть \"▶️ Почати зміну\" для старту.", getMainKeyboard($shiftType));
            answerCallbackQuery($callbackQueryId, "Вибрано: $label");
            break;
            
        case 'start_shift':
            $selectedShift = getUserSelectedShift($user['id']);
            if (!$selectedShift) {
                answerCallbackQuery($callbackQueryId, '❌ Спочатку оберіть тип зміни');
                break;
            }
            
            $result = startShift($user['id'], $selectedShift);
            if ($result['success']) {
                $label = $selectedShift === 'morning' ? '🌅 Ранкова' : '🌇 Вечірня';
                editMessageText($chatId, $messageId, "▶️ <b>Зміну розпочато!</b>\n\nТип: $label\nЧас: " . date('H:i') . "\n\nНатисніть ⏹ для завершення.", getMainKeyboard($selectedShift, true));
                answerCallbackQuery($callbackQueryId, '✅ Зміну розпочато!');
            } else {
                answerCallbackQuery($callbackQueryId, '❌ ' . $result['error'], true);
            }
            break;
            
        case 'end_shift':
            $active = getActiveSession($user['id']);
            if (!$active) {
                answerCallbackQuery($callbackQueryId, '❌ Немає активно зміни', true);
                break;
            }
            
            $result = endShift($user['id']);
            if ($result['success']) {
                editMessageText($chatId, $messageId, "⏹ <b>Зміну завершено!</b>\n\nВідпрацьовано: <b>{$result['hours']} год</b>\n\nДякуємо за роботу!", getMainKeyboard());
                answerCallbackQuery($callbackQueryId, "✅ Завершено! {$result['hours']} год");
            } else {
                answerCallbackQuery($callbackQueryId, '❌ ' . $result['error'], true);
            }
            break;
            
        case 'my_shifts':
            $shifts = getUserShifts($user['id'], 10);
            $text = "📋 <b>Ваші останні зміни:</b>\n\n";
            foreach ($shifts as $shift) {
                $label = $shift['shift_type'] === 'morning' ? '🌅' : '🌇';
                $text .= "$label {$shift['date']} | {$shift['start_time']} - " . ($shift['end_time'] ? date('H:i', strtotime($shift['end_time'])) : '—') . " | <b>{$shift['total_hours']} год</b>\n";
            }
            if (empty($shifts)) {
                $text .= "Змін ще немає.";
            }
            editMessageText($chatId, $messageId, $text, getBackKeyboard());
            answerCallbackQuery($callbackQueryId);
            break;
            
        case 'referral_link':
            $link = "https://t.me/" . BOT_USERNAME . "?start={$user['referral_code']}";
            editMessageText($chatId, $messageId, "📤 <b>Ваше реферальне посилання:</b>\n\n<code>$link</code>\n\nПоділіться з друзями! За кожного запрошеного отримуєте бонус.", getBackKeyboard());
            answerCallbackQuery($callbackQueryId);
            break;
            
        case 'stats_webapp':
            $url = WEBAPP_URL . "/stats.html?telegram_id={$user['telegram_id']}";
            answerCallbackQuery($callbackQueryId, 'Відкриваю статистику...', false, ['url' => $url]);
            break;
            
        case 'admin_panel':
            if (in_array($telegramId, explode(',', ADMIN_IDS))) {
                $url = WEBAPP_URL . "/admin.html";
                answerCallbackQuery($callbackQueryId, 'Відкриваю адмін-панель...', false, ['url' => $url]);
            } else {
                answerCallbackQuery($callbackQueryId, '❌ Немає доступу', true);
            }
            break;
            
        case 'back_to_main':
            editMessageText($chatId, $messageId, "🏠 <b>Головне меню</b>\n\nОберіть дію:", getMainKeyboard(getUserSelectedShift($user['id'])));
            answerCallbackQuery($callbackQueryId);
            break;
    }
}

function getMainKeyboard($selectedShift = null, $active = false) {
    $morningLabel = $selectedShift === 'morning' ? '✅ 🌅 Ранкова (7-15)' : '🌅 Ранкова (7-15)';
    $eveningLabel = $selectedShift === 'evening' ? '✅ 🌇 Вечірня (15-23)' : '🌇 Вечірня (15-23)';
    
    $inlineKeyboard = [
        [['text' => $morningLabel, 'callback_data' => 'shift_morning']],
        [['text' => $eveningLabel, 'callback_data' => 'shift_evening']]
    ];
    
    if ($active) {
        $inlineKeyboard[] = [['text' => '⏹ Закінчити зміну', 'callback_data' => 'end_shift']];
    } else {
        $inlineKeyboard[] = [['text' => '▶️ Почати зміну', 'callback_data' => 'start_shift']];
    }
    
    $inlineKeyboard[] = [['text' => '📊 Статистика', 'callback_data' => 'stats_webapp']];
    $inlineKeyboard[] = [['text' => '📋 Мої зміни', 'callback_data' => 'my_shifts']];
    $inlineKeyboard[] = [['text' => '📤 Реферальне посилання', 'callback_data' => 'referral_link']];
    
    if (in_array($GLOBALS['telegramId'] ?? 0, explode(',', ADMIN_IDS))) {
        $inlineKeyboard[] = [['text' => '🔐 Admin Panel', 'callback_data' => 'admin_panel']];
    }
    
    return ['inline_keyboard' => $inlineKeyboard];
}

function getBackKeyboard() {
    return ['inline_keyboard' => [
        [['text' => '🔙 Назад', 'callback_data' => 'back_to_main']]
    ]];
}

function saveUserSelectedShift($userId, $shiftType) {
    $file = __DIR__ . "/database/selected_shift_{$userId}.txt";
    file_put_contents($file, $shiftType);
}

function getUserSelectedShift($userId) {
    $file = __DIR__ . "/database/selected_shift_{$userId}.txt";
    return file_exists($file) ? trim(file_get_contents($file)) : null;
}

function sendMessage($chatId, $text, $replyMarkup = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($replyMarkup) {
        $data['reply_markup'] = json_encode($replyMarkup);
    }
    file_get_contents($url . '?' . http_build_query($data));
}

function editMessageText($chatId, $messageId, $text, $replyMarkup = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/editMessageText";
    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($replyMarkup) {
        $data['reply_markup'] = json_encode($replyMarkup);
    }
    file_get_contents($url . '?' . http_build_query($data));
}

function answerCallbackQuery($callbackQueryId, $text, $showAlert = false, $webApp = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/answerCallbackQuery";
    $data = [
        'callback_query_id' => $callbackQueryId,
        'text' => $text,
        'show_alert' => $showAlert
    ];
    if ($webApp) {
        $data['url'] = $webApp['url'];
    }
    file_get_contents($url . '?' . http_build_query($data));
}