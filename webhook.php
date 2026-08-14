<?php
// =============================================
// TELEGRAM BOT WEBHOOK HANDLER
// =============================================

require_once __DIR__ . '/functions.php';

// Отримання вхідних даних
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    http_response_code(200);
    exit('OK');
}

writeLog("Update received: " . json_encode($update));

// Обробка повідомлень
if (isset($update['message'])) {
    handleMessage($update['message']);
}

// Обробка callback-запитів (натискання кнопок)
if (isset($update['callback_query'])) {
    handleCallbackQuery($update['callback_query']);
}

http_response_code(200);
echo 'OK';

// =============================================
// ОБРОБКА ПОВІДОМЛЕНЬ
// =============================================

function handleMessage($message) {
    $chat_id = $message['chat']['id'];
    $telegram_id = $message['from']['id'];
    $username = $message['from']['username'] ?? '';
    $first_name = $message['from']['first_name'] ?? '';
    $last_name = $message['from']['last_name'] ?? '';
    $full_name = trim("$first_name $last_name");
    $text = $message['text'] ?? '';

    // Команда /start з можливим реферальним кодом
    if (strpos($text, '/start') === 0) {
        $referral_code = null;
        $parts = explode(' ', $text);
        if (count($parts) > 1) {
            $referral_code = $parts[1];
        }

        handleStart($chat_id, $telegram_id, $username, $full_name, $referral_code);
        return;
    }

    // Команда /stats
    if ($text === '/stats') {
        $user = getUserByTelegramId($telegram_id);
        if (!$user) {
            sendMessage($chat_id, "❌ Спочатку зареєструйтесь командою /start");
            return;
        }
        handleStatsCommand($chat_id, $user);
        return;
    }

    // Команда /help
    if ($text === '/help') {
        $helpText = "📖 <b>Довідка по боту WorkTracker</b>\n\n";
        $helpText .= "🔹 /start - Головне меню\n";
        $helpText .= "🔹 /stats - Моя статистика\n";
        $helpText .= "🔹 /help - Ця довідка\n\n";
        $helpText .= "📋 <b>Як працювати:</b>\n";
        $helpText .= "1. Оберіть тип зміни (ранкова/вечірня)\n";
        $helpText .= "2. Натисніть \"Почати зміну\"\n";
        $helpText .= "3. По завершенню натисніть \"Закінчити зміну\"\n";
        $helpText .= "4. Переглядайте статистику в розділі 📊\n\n";
        $helpText .= "⏰ Зміни автоматично закриваються о 23:59";
        sendMessage($chat_id, $helpText);
        return;
    }

    // Команда /admin (швидкий доступ)
    if ($text === '/admin') {
        if (!isAdmin($telegram_id)) {
            sendMessage($chat_id, "❌ Доступ заборонено.");
            return;
        }
        $adminText = "🔐 <b>Admin Panel</b>\n\n";
        $adminText .= "Відкрийте адмін-панель через кнопку в головному меню,\n";
        $adminText .= "або перейдіть за посиланням:\n";
        $adminText .= WEBAPP_URL . "/admin.html";
        sendMessage($chat_id, $adminText);
        return;
    }

    // Невідома команда — перевіряємо активну сесію
    $user = getUserByTelegramId($telegram_id);
    if ($user) {
        // Перевіряємо активну сесію
        $session = getActiveSession($user['id']);
        if ($session) {
            // Якщо є активна сесія — показуємо таймер
            $start = new DateTime($session['start_timestamp']);
            $now = new DateTime();
            $diff = $start->diff($now);
            $hours = $diff->h + ($diff->days * 24);
            $minutes = $diff->i;
            
            $shiftLabel = $session['shift_type'] === 'morning' ? '🌅 Ранкова (7-15)' : '🌇 Вечірня (15-23)';
            
            $text = "⏱ <b>Зміна в процесі</b>\n\n";
            $text .= "📋 Тип: {$shiftLabel}\n";
            $text .= "🕐 Початок: " . formatTime($session['start_timestamp']) . "\n";
            $text .= "⏱ Працюєте: <b>{$hours} год {$minutes} хв</b>\n\n";
            $text .= "🔄 Натисніть щоб оновити таймер";
            
            $keyboard = getTimerKeyboard($session);
            sendMessage($chat_id, $text, $keyboard);
            return;
        }
        
        // Якщо немає активної сесії — показуємо головне меню
        showMainMenu($chat_id, $user);
    } else {
        handleStart($chat_id, $telegram_id, $username, $full_name, null);
    }
}

// =============================================
// ОБРОБКА /START
// =============================================

function handleStart($chat_id, $telegram_id, $username, $full_name, $referral_code) {
    $user = getUserByTelegramId($telegram_id);

    if (!$user) {
        // Новий користувач
        $user = createUser($telegram_id, $username, $full_name, $referral_code);

        $welcomeText = "🎉 <b>Ласкаво просимо до WorkTracker!</b>\n\n";
        $welcomeText .= "Привіт, <b>{$full_name}</b>! 👋\n\n";
        $welcomeText .= "Цей бот допоможе вам відстежувати робочі зміни.\n\n";

        if ($referral_code) {
            $welcomeText .= "✅ Ви приєдналися за реферальним посиланням!\n\n";
        }

        $welcomeText .= "📋 <b>Що можна робити:</b>\n";
        $welcomeText .= "🔹 Обирати тип зміни\n";
        $welcomeText .= "🔹 Відмічати початок і кінець роботи\n";
        $welcomeText .= "🔹 Переглядати детальну статистику\n";
        $welcomeText .= "🔹 Запрошувати колег за реферальним посиланням\n";

        sendMessage($chat_id, $welcomeText);
    }

    showMainMenu($chat_id, $user);
}

// =============================================
// ГОЛОВНЕ МЕНЮ
// =============================================

function showMainMenu($chat_id, $user) {
    $session = getActiveSession($user['id']);

    $menuText = "🏠 <b>Головне меню</b>\n\n";
    $menuText .= "👤 <b>{$user['full_name']}</b>\n";

    if ($user['selected_shift']) {
        $shiftLabel = $user['selected_shift'] === 'morning' ? '🌅 Ранкова (7-15)' : '🌇 Вечірня (15-23)';
        $menuText .= "📋 Обрана зміна: {$shiftLabel}\n";
    } else {
        $menuText .= "📋 Зміна не обрана\n";
    }

    if ($session) {
        $start = new DateTime($session['start_timestamp']);
        $now = new DateTime();
        $diff = $start->diff($now);
        $hours = $diff->h + ($diff->days * 24);
        $minutes = $diff->i;
        $menuText .= "\n⏱ <b>Активна зміна:</b> {$hours} год {$minutes} хв\n";
        $menuText .= "🕐 Початок: " . formatTime($session['start_timestamp']) . "\n";
    }

    $keyboard = getMainMenuKeyboard($user);
    sendMessage($chat_id, $menuText, $keyboard);
}

// =============================================
// ОБРОБКА CALLBACK QUERY
// =============================================

function handleCallbackQuery($callback) {
    $callback_id = $callback['id'];
    $chat_id = $callback['message']['chat']['id'];
    $message_id = $callback['message']['message_id'];
    $telegram_id = $callback['from']['id'];
    $data = $callback['data'];

    $user = getUserByTelegramId($telegram_id);

    if (!$user) {
        answerCallbackQuery($callback_id, "❌ Спочатку натисніть /start", true);
        return;
    }

    switch ($data) {
        case 'select_morning':
            handleSelectShift($callback_id, $chat_id, $message_id, $user, 'morning');
            break;

        case 'select_evening':
            handleSelectShift($callback_id, $chat_id, $message_id, $user, 'evening');
            break;

        case 'start_shift':
            handleStartShift($callback_id, $chat_id, $message_id, $user);
            break;

        case 'end_shift':
            handleEndShift($callback_id, $chat_id, $message_id, $user);
            break;

        case 'timer_refresh':
            handleTimerRefresh($callback_id, $chat_id, $message_id, $user);
            break;

        case 'my_shifts':
            handleMyShifts($callback_id, $chat_id, $message_id, $user);
            break;

        case 'referral_link':
            handleReferralLink($callback_id, $chat_id, $user);
            break;

        case 'main_menu':
            answerCallbackQuery($callback_id);
            $user = getUserByTelegramId($telegram_id);
            $session = getActiveSession($user['id']);
            $menuText = "🏠 <b>Головне меню</b>\n\n";
            $menuText .= "👤 <b>{$user['full_name']}</b>\n";
            if ($user['selected_shift']) {
                $shiftLabel = $user['selected_shift'] === 'morning' ? '🌅 Ранкова (7-15)' : '🌇 Вечірня (15-23)';
                $menuText .= "📋 Обрана зміна: {$shiftLabel}\n";
            }
            if ($session) {
                $start = new DateTime($session['start_timestamp']);
                $now = new DateTime();
                $diff = $start->diff($now);
                $hours = $diff->h + ($diff->days * 24);
                $minutes = $diff->i;
                $menuText .= "\n⏱ <b>Активна зміна:</b> {$hours} год {$minutes} хв\n";
            }
            $keyboard = getMainMenuKeyboard($user);
            editMessageText($chat_id, $message_id, $menuText, $keyboard);
            break;

        case 'noop':
            answerCallbackQuery($callback_id);
            break;

        default:
            answerCallbackQuery($callback_id, "❓ Невідома дія");
            break;
    }
}

// =============================================
// ОБРОБНИКИ ДІЙ
// =============================================

function handleSelectShift($callback_id, $chat_id, $message_id, $user, $shift_type) {
    updateSelectedShift($user['telegram_id'], $shift_type);
    $user['selected_shift'] = $shift_type;

    $shiftLabel = $shift_type === 'morning' ? '🌅 Ранкова (7-15)' : '🌇 Вечірня (15-23)';
    answerCallbackQuery($callback_id, "✅ Обрано: $shiftLabel");

    logAction($user['telegram_id'], 'select_shift', "Type: $shift_type");

    $session = getActiveSession($user['id']);
    $menuText = "🏠 <b>Головне меню</b>\n\n";
    $menuText .= "👤 <b>{$user['full_name']}</b>\n";
    $menuText .= "📋 Обрана зміна: {$shiftLabel}\n";

    if ($session) {
        $start = new DateTime($session['start_timestamp']);
        $now = new DateTime();
        $diff = $start->diff($now);
        $hours = $diff->h + ($diff->days * 24);
        $minutes = $diff->i;
        $menuText .= "\n⏱ <b>Активна зміна:</b> {$hours} год {$minutes} хв\n";
    }

    $keyboard = getMainMenuKeyboard($user);
    editMessageText($chat_id, $message_id, $menuText, $keyboard);
}

function handleStartShift($callback_id, $chat_id, $message_id, $user) {
    if (!$user['selected_shift']) {
        answerCallbackQuery($callback_id, "⚠️ Спочатку оберіть тип зміни!", true);
        return;
    }

    $result = startSession($user['id'], $user['selected_shift']);

    if ($result === false) {
        answerCallbackQuery($callback_id, "⚠️ У вас вже є активна зміна!", true);
        return;
    }

    if ($result === 'time_limit') {
        answerCallbackQuery($callback_id, "⚠️ Не можна починати зміну після 23:00!", true);
        return;
    }

    answerCallbackQuery($callback_id, "✅ Зміну розпочато!");

    $session = getActiveSession($user['id']);
    $shiftLabel = $session['shift_type'] === 'morning' ? '🌅 Ранкова (7-15)' : '🌇 Вечірня (15-23)';

    $text = "⏱ <b>Зміна розпочата!</b>\n\n";
    $text .= "📋 Тип: {$shiftLabel}\n";
    $text .= "🕐 Початок: " . formatTime($session['start_timestamp']) . "\n\n";
    $text .= "Натисніть 🔄 щоб оновити таймер";

    $keyboard = getTimerKeyboard($session);
    editMessageText($chat_id, $message_id, $text, $keyboard);
}

// ✅ ВИПРАВЛЕНО: handleEndShift()
function handleEndShift($callback_id, $chat_id, $message_id, $user) {
    // Перевіряємо, чи є користувач
    if (!$user) {
        answerCallbackQuery($callback_id, "❌ Користувача не знайдено. Натисніть /start", true);
        return;
    }

    // Логуємо спробу завершення
    writeLog("End shift attempt for user: " . $user['id'] . " (" . $user['full_name'] . ")");

    $total_hours = endSession($user['id']);

    if ($total_hours === false) {
        // Логуємо помилку
        writeLog("End shift failed: no active session for user " . $user['id']);
        answerCallbackQuery($callback_id, "⚠️ У вас немає активної зміни!", true);
        return;
    }

    answerCallbackQuery($callback_id, "✅ Зміну завершено!");

    $text = "✅ <b>Зміну завершено!</b>\n\n";
    $text .= "⏱ Відпрацьовано: <b>" . formatHours($total_hours) . "</b>\n\n";
    $text .= "Дякуємо за роботу! 💪";

    $statsUrl = WEBAPP_URL . '/stats.html?user_id=' . $user['id'];

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📊 Моя статистика', 'url' => $statsUrl],
            ],
            [
                ['text' => '🏠 Головне меню', 'callback_data' => 'main_menu'],
            ],
        ],
    ];

    editMessageText($chat_id, $message_id, $text, $keyboard);
}

function handleTimerRefresh($callback_id, $chat_id, $message_id, $user) {
    // Перевіряємо, чи є користувач
    if (!$user) {
        answerCallbackQuery($callback_id, "❌ Користувача не знайдено. Натисніть /start", true);
        return;
    }

    $session = getActiveSession($user['id']);

    if (!$session) {
        answerCallbackQuery($callback_id, "ℹ️ Немає активної зміни");
        $menuText = "🏠 <b>Головне меню</b>\n\n👤 <b>{$user['full_name']}</b>";
        $keyboard = getMainMenuKeyboard($user);
        editMessageText($chat_id, $message_id, $menuText, $keyboard);
        return;
    }

    $start = new DateTime($session['start_timestamp']);
    $now = new DateTime();
    $diff = $start->diff($now);
    $hours = $diff->h + ($diff->days * 24);
    $minutes = $diff->i;

    answerCallbackQuery($callback_id, "⏱ {$hours} год {$minutes} хв");

    $shiftLabel = $session['shift_type'] === 'morning' ? '🌅 Ранкова (7-15)' : '🌇 Вечірня (15-23)';

    $text = "⏱ <b>Зміна в процесі</b>\n\n";
    $text .= "📋 Тип: {$shiftLabel}\n";
    $text .= "🕐 Початок: " . formatTime($session['start_timestamp']) . "\n";
    $text .= "⏱ Працюєте: <b>{$hours} год {$minutes} хв</b>\n\n";
    $text .= "🔄 Натисніть щоб оновити таймер";

    $keyboard = getTimerKeyboard($session);
    editMessageText($chat_id, $message_id, $text, $keyboard);
}

// ✅ ВИПРАВЛЕНО: handleMyShifts()
function handleMyShifts($callback_id, $chat_id, $message_id, $user) {
    // Перевіряємо, чи є користувач
    if (!$user) {
        answerCallbackQuery($callback_id, "❌ Користувача не знайдено. Натисніть /start", true);
        return;
    }

    answerCallbackQuery($callback_id);

    $shifts = getUserShifts($user['id'], null, null, 10);
    $stats = getUserStats($user['id']);

    $text = "📋 <b>Мої останні зміни</b>\n\n";

    if (empty($shifts)) {
        $text .= "❌ Змін поки немає.\n";
        $text .= "Натисніть ▶️ щоб почати першу зміну!";
    } else {
        $text .= "📊 Всього: <b>{$stats['total_shifts']}</b> змін, <b>" . round($stats['total_hours'], 1) . "</b> год\n";
        $text .= "🌅 Ранкових: {$stats['morning_shifts']} | 🌇 Вечірніх: {$stats['evening_shifts']}\n\n";

        foreach ($shifts as $shift) {
            $icon = $shift['shift_type'] === 'morning' ? '🌅' : '🌇';
            $date = formatDate($shift['date']);
            $start = formatTime($shift['start_time']);
            $end = formatTime($shift['end_time']);
            $hours = round($shift['total_hours'], 1);
            $text .= "{$icon} {$date} | {$start}-{$end} | <b>{$hours} год</b>\n";
        }
    }

    $statsUrl = WEBAPP_URL . '/stats.html?user_id=' . $user['id'];

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📊 Повна статистика', 'url' => $statsUrl],
            ],
            [
                ['text' => '🏠 Головне меню', 'callback_data' => 'main_menu'],
            ],
        ],
    ];

    editMessageText($chat_id, $message_id, $text, $keyboard);
}

function handleReferralLink($callback_id, $chat_id, $user) {
    // Перевіряємо, чи є користувач
    if (!$user) {
        answerCallbackQuery($callback_id, "❌ Користувача не знайдено. Натисніть /start", true);
        return;
    }

    answerCallbackQuery($callback_id);

    $referral_url = getReferralLink($user);
    $referral_count = getReferralCount($user['id']);

    $text = "📤 <b>Реферальне посилання</b>\n\n";
    $text .= "🔗 Ваше посилання:\n<code>{$referral_url}</code>\n\n";
    $text .= "Натисніть на посилання щоб скопіювати.\n\n";
    $text .= "👥 Запрошено користувачів: <b>{$referral_count}</b>";

    sendMessage($chat_id, $text);
}

// ✅ ВИПРАВЛЕНО: handleStatsCommand()
function handleStatsCommand($chat_id, $user) {
    // Перевіряємо, чи є користувач
    if (!$user) {
        sendMessage($chat_id, "❌ Користувача не знайдено. Натисніть /start");
        return;
    }

    $stats = getUserStats($user['id']);

    $text = "📊 <b>Ваша статистика</b>\n\n";
    $text .= "📋 Всього змін: <b>{$stats['total_shifts']}</b>\n";
    $text .= "⏱ Відпрацьовано: <b>" . round($stats['total_hours'], 1) . " год</b>\n";
    $text .= "📈 Середня зміна: <b>" . round($stats['avg_hours'], 1) . " год</b>\n";
    $text .= "🌅 Ранкових: <b>{$stats['morning_shifts']}</b>\n";
    $text .= "🌇 Вечірніх: <b>{$stats['evening_shifts']}</b>\n\n";
    $text .= "Для детальної статистики натисніть кнопку нижче:";

    $statsUrl = WEBAPP_URL . '/stats.html?user_id=' . $user['id'];

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📊 Детальна статистика', 'url' => $statsUrl],
            ],
            [
                ['text' => '🏠 Головне меню', 'callback_data' => 'main_menu'],
            ],
        ],
    ];

    sendMessage($chat_id, $text, $keyboard);
}
