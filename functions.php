<?php
// =============================================
// ФУНКЦІЇ РОБОТИ З БАЗОЮ ДАНИХ ТА БОТОМ
// =============================================

require_once __DIR__ . '/config.php';

// =============================================
// ІНІЦІАЛІЗАЦІЯ БАЗИ ДАНИХ
// =============================================

function getDB() {
    static $db = null;
    if ($db === null) {
        $dbDir = dirname(DB_PATH);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }
        $db = new SQLite3(DB_PATH);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');
        initDB($db);
    }
    return $db;
}

function initDB($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            telegram_id INTEGER UNIQUE NOT NULL,
            username TEXT DEFAULT '',
            full_name TEXT DEFAULT '',
            role TEXT DEFAULT 'worker' CHECK(role IN ('worker','admin')),
            is_active INTEGER DEFAULT 1,
            registered_at DATETIME DEFAULT (datetime('now')),
            referral_code TEXT UNIQUE,
            referred_by_id INTEGER DEFAULT NULL,
            selected_shift TEXT DEFAULT NULL,
            FOREIGN KEY (referred_by_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS shifts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            shift_type TEXT NOT NULL CHECK(shift_type IN ('morning','evening')),
            start_time DATETIME NOT NULL,
            end_time DATETIME NOT NULL,
            total_hours REAL NOT NULL DEFAULT 0,
            date DATE NOT NULL,
            created_at DATETIME DEFAULT (datetime('now')),
            FOREIGN KEY (user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS work_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            shift_type TEXT NOT NULL CHECK(shift_type IN ('morning','evening')),
            start_timestamp DATETIME NOT NULL,
            end_timestamp DATETIME DEFAULT NULL,
            is_active INTEGER DEFAULT 1,
            last_updated DATETIME DEFAULT (datetime('now')),
            FOREIGN KEY (user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS action_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            telegram_id INTEGER,
            action TEXT NOT NULL,
            details TEXT DEFAULT '',
            created_at DATETIME DEFAULT (datetime('now'))
        );

        CREATE INDEX IF NOT EXISTS idx_users_telegram_id ON users(telegram_id);
        CREATE INDEX IF NOT EXISTS idx_users_referral_code ON users(referral_code);
        CREATE INDEX IF NOT EXISTS idx_shifts_user_id ON shifts(user_id);
        CREATE INDEX IF NOT EXISTS idx_shifts_date ON shifts(date);
        CREATE INDEX IF NOT EXISTS idx_work_sessions_user_id ON work_sessions(user_id);
        CREATE INDEX IF NOT EXISTS idx_work_sessions_active ON work_sessions(is_active);
    ");
}

// =============================================
// ЛОГУВАННЯ
// =============================================

function writeLog($message) {
    $logDir = dirname(LOG_PATH);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(LOG_PATH, "[$timestamp] $message\n", FILE_APPEND);
}

function logAction($telegram_id, $action, $details = '') {
    $db = getDB();
    $user = getUserByTelegramId($telegram_id);
    $user_id = $user ? $user['id'] : null;

    $stmt = $db->prepare("INSERT INTO action_logs (user_id, telegram_id, action, details) VALUES (:uid, :tid, :action, :details)");
    $stmt->bindValue(':uid', $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(':tid', $telegram_id, SQLITE3_INTEGER);
    $stmt->bindValue(':action', $action, SQLITE3_TEXT);
    $stmt->bindValue(':details', $details, SQLITE3_TEXT);
    $stmt->execute();
}

// =============================================
// КОРИСТУВАЧІ
// =============================================

function getUserByTelegramId($telegram_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE telegram_id = :tid");
    $stmt->bindValue(':tid', $telegram_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    return $result->fetchArray(SQLITE3_ASSOC);
}

function getUserById($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    return $result->fetchArray(SQLITE3_ASSOC);
}

function getAllUsers() {
    $db = getDB();
    $result = $db->query("SELECT * FROM users WHERE is_active = 1 ORDER BY registered_at DESC");
    $users = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $users[] = $row;
    }
    return $users;
}

function updateUserRole($user_id, $role) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET role = :role WHERE id = :id");
    $stmt->bindValue(':role', $role, SQLITE3_TEXT);
    $stmt->bindValue(':id', $user_id, SQLITE3_INTEGER);
    $stmt->execute();
}

function updateSelectedShift($telegram_id, $shift_type) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET selected_shift = :shift WHERE telegram_id = :tid");
    $stmt->bindValue(':shift', $shift_type, SQLITE3_TEXT);
    $stmt->bindValue(':tid', $telegram_id, SQLITE3_INTEGER);
    $stmt->execute();
}

function getReferralCount($user_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM users WHERE referred_by_id = :uid");
    $stmt->bindValue(':uid', $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row['cnt'] ?? 0;
}

function isAdmin($telegram_id) {
    $admins = array_map('trim', explode(',', ADMIN_IDS));
    return in_array((string)$telegram_id, $admins);
}

function createUser($telegram_id, $username, $full_name, $referral_code = null) {
    $db = getDB();

    $existing = getUserByTelegramId($telegram_id);
    if ($existing) {
        return $existing;
    }

    $refCode = 'ref_' . $telegram_id . '_' . substr(md5(uniqid()), 0, 6);

    $referred_by_id = null;
    if ($referral_code) {
        $stmt = $db->prepare("SELECT id FROM users WHERE referral_code = :code");
        $stmt->bindValue(':code', $referral_code, SQLITE3_TEXT);
        $result = $stmt->execute();
        $referrer = $result->fetchArray(SQLITE3_ASSOC);
        if ($referrer) {
            $referred_by_id = $referrer['id'];
        }
    }

    $role = isAdmin($telegram_id) ? 'admin' : 'worker';

    $stmt = $db->prepare("INSERT INTO users (telegram_id, username, full_name, role, referral_code, referred_by_id)
                          VALUES (:tid, :uname, :fname, :role, :rcode, :rbid)");
    $stmt->bindValue(':tid', $telegram_id, SQLITE3_INTEGER);
    $stmt->bindValue(':uname', $username, SQLITE3_TEXT);
    $stmt->bindValue(':fname', $full_name, SQLITE3_TEXT);
    $stmt->bindValue(':role', $role, SQLITE3_TEXT);
    $stmt->bindValue(':rcode', $refCode, SQLITE3_TEXT);
    $stmt->bindValue(':rbid', $referred_by_id, $referred_by_id ? SQLITE3_INTEGER : SQLITE3_NULL);
    $stmt->execute();

    logAction($telegram_id, 'register', "Referred by: " . ($referral_code ?? 'none'));

    $newUser = getUserByTelegramId($telegram_id);
    
    if ($referral_code && $referred_by_id) {
        notifyReferrer($referral_code, $full_name);
    }

    return $newUser;
}

function notifyReferrer($referral_code, $new_user_name) {
    $db = getDB();
    $stmt = $db->prepare("SELECT telegram_id, full_name FROM users WHERE referral_code = :code");
    $stmt->bindValue(':code', $referral_code, SQLITE3_TEXT);
    $result = $stmt->execute();
    $referrer = $result->fetchArray(SQLITE3_ASSOC);

    if ($referrer) {
        $text = "🎉 <b>Новий реферал!</b>\n\n";
        $text .= "Користувач <b>{$new_user_name}</b> приєднався за вашим посиланням!\n\n";
        $text .= "👥 Всього запрошених: " . getReferralCount($referrer['id']);
        sendMessage($referrer['telegram_id'], $text);
    }
}

// =============================================
// РОБОЧІ СЕСІЇ
// =============================================

function getActiveSession($user_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM work_sessions WHERE user_id = :uid AND is_active = 1 LIMIT 1");
    $stmt->bindValue(':uid', $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    return $result->fetchArray(SQLITE3_ASSOC);
}

function startSession($user_id, $shift_type) {
    $db = getDB();

    $existing = getActiveSession($user_id);
    if ($existing) {
        return false;
    }

    $hour = (int)date('H');
    if ($hour >= 23) {
        return 'time_limit';
    }

    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("INSERT INTO work_sessions (user_id, shift_type, start_timestamp, last_updated) VALUES (:uid, :st, :now, :now)");
    $stmt->bindValue(':uid', $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(':st', $shift_type, SQLITE3_TEXT);
    $stmt->bindValue(':now', $now, SQLITE3_TEXT);
    $stmt->execute();

    $user = getUserById($user_id);
    logAction($user['telegram_id'], 'start_shift', "Shift type: $shift_type");

    return true;
}

function endSession($user_id) {
    $db = getDB();

    $session = getActiveSession($user_id);
    if (!$session) {
        return false;
    }

    $now = date('Y-m-d H:i:s');
    $start = new DateTime($session['start_timestamp']);
    $end = new DateTime($now);
    $diff = $start->diff($end);
    $total_hours = round($diff->h + ($diff->i / 60) + ($diff->days * 24), 2);

    $stmt = $db->prepare("UPDATE work_sessions SET end_timestamp = :end, is_active = 0, last_updated = :end WHERE id = :id");
    $stmt->bindValue(':end', $now, SQLITE3_TEXT);
    $stmt->bindValue(':id', $session['id'], SQLITE3_INTEGER);
    $stmt->execute();

    $date = date('Y-m-d', strtotime($session['start_timestamp']));
    $stmt = $db->prepare("INSERT INTO shifts (user_id, shift_type, start_time, end_time, total_hours, date) VALUES (:uid, :st, :start, :end, :hours, :date)");
    $stmt->bindValue(':uid', $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(':st', $session['shift_type'], SQLITE3_TEXT);
    $stmt->bindValue(':start', $session['start_timestamp'], SQLITE3_TEXT);
    $stmt->bindValue(':end', $now, SQLITE3_TEXT);
    $stmt->bindValue(':hours', $total_hours, SQLITE3_FLOAT);
    $stmt->bindValue(':date', $date, SQLITE3_TEXT);
    $stmt->execute();

    $user = getUserById($user_id);
    logAction($user['telegram_id'], 'end_shift', "Hours: $total_hours, Type: {$session['shift_type']}");

    return $total_hours;
}

function autoCloseExpiredSessions() {
    $db = getDB();
    $result = $db->query("SELECT * FROM work_sessions WHERE is_active = 1");
    $closed = 0;
    while ($session = $result->fetchArray(SQLITE3_ASSOC)) {
        $start = new DateTime($session['start_timestamp']);
        $now = new DateTime();
        $diff = $start->diff($now);
        $hours = $diff->h + ($diff->days * 24);

        if ($hours >= 12 || (date('H') == 23 && date('i') >= 59)) {
            endSession($session['user_id']);
            $closed++;
        }
    }
    return $closed;
}

function getAllActiveSessions() {
    $db = getDB();
    $result = $db->query("
        SELECT ws.*, u.full_name, u.username, u.telegram_id
        FROM work_sessions ws
        JOIN users u ON ws.user_id = u.id
        WHERE ws.is_active = 1
        ORDER BY ws.start_timestamp ASC
    ");
    $sessions = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $sessions[] = $row;
    }
    return $sessions;
}

// =============================================
// ЗМІНИ (SHIFTS)
// =============================================

function getUserShifts($user_id, $month = null, $shift_type = null, $limit = 50, $offset = 0) {
    $db = getDB();
    $where = "WHERE user_id = :uid";
    $params = [':uid' => $user_id];

    if ($month) {
        $where .= " AND strftime('%Y-%m', date) = :month";
        $params[':month'] = $month;
    }
    if ($shift_type) {
        $where .= " AND shift_type = :st";
        $params[':st'] = $shift_type;
    }

    $stmt = $db->prepare("SELECT * FROM shifts $where ORDER BY date DESC, start_time DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $shifts = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $shifts[] = $row;
    }
    return $shifts;
}

function getUserStats($user_id, $month = null) {
    $db = getDB();

    $monthFilter = '';
    $params = [':uid' => $user_id];
    if ($month) {
        $monthFilter = " AND strftime('%Y-%m', date) = :month";
        $params[':month'] = $month;
    }

    $stmt = $db->prepare("SELECT
        COUNT(*) as total_shifts,
        COALESCE(SUM(total_hours), 0) as total_hours,
        COALESCE(AVG(total_hours), 0) as avg_hours,
        COALESCE(SUM(CASE WHEN shift_type='morning' THEN 1 ELSE 0 END), 0) as morning_shifts,
        COALESCE(SUM(CASE WHEN shift_type='evening' THEN 1 ELSE 0 END), 0) as evening_shifts
        FROM shifts WHERE user_id = :uid $monthFilter");
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $result = $stmt->execute();
    return $result->fetchArray(SQLITE3_ASSOC);
}

function getUserDailyHours($user_id, $days = 30) {
    $db = getDB();
    $dateFrom = date('Y-m-d', strtotime("-{$days} days"));
    $stmt = $db->prepare("SELECT date, SUM(total_hours) as hours FROM shifts WHERE user_id = :uid AND date >= :from GROUP BY date ORDER BY date ASC");
    $stmt->bindValue(':uid', $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(':from', $dateFrom, SQLITE3_TEXT);
    $result = $stmt->execute();

    $data = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $data[] = $row;
    }
    return $data;
}

function getShiftCountByUser($user_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM shifts WHERE user_id = :uid");
    $stmt->bindValue(':uid', $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row['cnt'];
}

// =============================================
// ЗАГАЛЬНА СТАТИСТИКА (АДМІН)
// =============================================

function getGlobalStats($month = null) {
    $db = getDB();
    $monthFilter = '';
    if ($month) {
        $monthFilter = " WHERE strftime('%Y-%m', date) = '$month'";
    }

    $result = $db->querySingle("SELECT
        COUNT(*) as total_shifts,
        COALESCE(SUM(total_hours), 0) as total_hours,
        COALESCE(AVG(total_hours), 0) as avg_hours,
        COUNT(DISTINCT user_id) as active_workers
        FROM shifts $monthFilter", true);

    return $result;
}

function getTopWorkers($limit = 10, $month = null) {
    $db = getDB();
    $monthFilter = '';
    if ($month) {
        $monthFilter = " AND strftime('%Y-%m', s.date) = '$month'";
    }

    $result = $db->query("
        SELECT u.id, u.full_name, u.username, u.telegram_id,
            COUNT(s.id) as total_shifts,
            COALESCE(SUM(s.total_hours), 0) as total_hours,
            COALESCE(AVG(s.total_hours), 0) as avg_hours,
            SUM(CASE WHEN s.shift_type='morning' THEN 1 ELSE 0 END) as morning_shifts,
            SUM(CASE WHEN s.shift_type='evening' THEN 1 ELSE 0 END) as evening_shifts
        FROM users u
        LEFT JOIN shifts s ON u.id = s.user_id $monthFilter
        WHERE u.is_active = 1
        GROUP BY u.id
        ORDER BY total_hours DESC
        LIMIT $limit
    ");

    $workers = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $workers[] = $row;
    }
    return $workers;
}

function getAllWorkersStats($month = null, $search = '', $shift_filter = '') {
    $db = getDB();

    $monthFilter = '';
    if ($month) {
        $monthFilter = " AND strftime('%Y-%m', s.date) = '$month'";
    }
    $shiftFilter = '';
    if ($shift_filter && in_array($shift_filter, ['morning', 'evening'])) {
        $shiftFilter = " AND s.shift_type = '$shift_filter'";
    }

    $searchFilter = '';
    if ($search) {
        $search = SQLite3::escapeString($search);
        $searchFilter = " AND (u.full_name LIKE '%$search%' OR u.username LIKE '%$search%')";
    }

    $result = $db->query("
        SELECT u.id, u.telegram_id, u.full_name, u.username, u.role, u.registered_at,
            COUNT(s.id) as total_shifts,
            COALESCE(SUM(s.total_hours), 0) as total_hours,
            COALESCE(AVG(s.total_hours), 0) as avg_hours,
            COALESCE(SUM(CASE WHEN s.shift_type='morning' THEN 1 ELSE 0 END), 0) as morning_shifts,
            COALESCE(SUM(CASE WHEN s.shift_type='evening' THEN 1 ELSE 0 END), 0) as evening_shifts
        FROM users u
        LEFT JOIN shifts s ON u.id = s.user_id $monthFilter $shiftFilter
        WHERE u.is_active = 1 $searchFilter
        GROUP BY u.id
        ORDER BY total_hours DESC
    ");

    $workers = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $workers[] = $row;
    }
    return $workers;
}

function getHourlyActivity($month = null) {
    $db = getDB();
    $monthFilter = '';
    if ($month) {
        $monthFilter = " WHERE strftime('%Y-%m', date) = '$month'";
    }

    $result = $db->query("
        SELECT CAST(strftime('%H', start_time) AS INTEGER) as hour, COUNT(*) as count
        FROM shifts $monthFilter
        GROUP BY hour
        ORDER BY hour
    ");

    $data = array_fill(0, 24, 0);
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $data[(int)$row['hour']] = (int)$row['count'];
    }
    return $data;
}

function getDailyShiftComparison($days = 30) {
    $db = getDB();
    $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

    $result = $db->query("
        SELECT date,
            SUM(CASE WHEN shift_type='morning' THEN total_hours ELSE 0 END) as morning_hours,
            SUM(CASE WHEN shift_type='evening' THEN total_hours ELSE 0 END) as evening_hours
        FROM shifts
        WHERE date >= '$dateFrom'
        GROUP BY date
        ORDER BY date ASC
    ");

    $data = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $data[] = $row;
    }
    return $data;
}

// =============================================
// TELEGRAM API
// =============================================

function sendTelegramRequest($method, $params = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/$method";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        writeLog("Telegram API Error: $error");
        return false;
    }

    return json_decode($response, true);
}

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = 'HTML') {
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $parse_mode,
    ];
    if ($reply_markup) {
        $params['reply_markup'] = $reply_markup;
    }
    return sendTelegramRequest('sendMessage', $params);
}

function editMessageText($chat_id, $message_id, $text, $reply_markup = null, $parse_mode = 'HTML') {
    $params = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => $parse_mode,
    ];
    if ($reply_markup) {
        $params['reply_markup'] = $reply_markup;
    }
    return sendTelegramRequest('editMessageText', $params);
}

function answerCallbackQuery($callback_query_id, $text = '', $show_alert = false) {
    return sendTelegramRequest('answerCallbackQuery', [
        'callback_query_id' => $callback_query_id,
        'text' => $text,
        'show_alert' => $show_alert,
    ]);
}

function setWebhook() {
    return sendTelegramRequest('setWebhook', [
        'url' => WEBHOOK_URL,
        'allowed_updates' => ['message', 'callback_query'],
    ]);
}

function deleteWebhook() {
    return sendTelegramRequest('deleteWebhook');
}

function getReferralLink($user) {
    return "https://t.me/" . BOT_USERNAME . "?start=" . $user['referral_code'];
}

// =============================================
// КЛАВІАТУРИ (ВИПРАВЛЕНО!)
// =============================================

function getMainMenuKeyboard($user) {
    $shift_morning_text = "🔄 Зміна (7-15)";
    $shift_evening_text = "🔄 Зміна (15-23)";

    if ($user['selected_shift'] === 'morning') {
        $shift_morning_text = "✅ Зміна (7-15)";
    } elseif ($user['selected_shift'] === 'evening') {
        $shift_evening_text = "✅ Зміна (15-23)";
    }

    $session = getActiveSession($user['id']);
    $shift_button_text = $session ? "⏹ Закінчити зміну" : "▶️ Почати зміну";
    $shift_callback = $session ? "end_shift" : "start_shift";

    // ✅ ВИПРАВЛЕНО: використовуємо id з БД (НЕ telegram_id!)
    $statsUrl = WEBAPP_URL . '/stats.html?user_id=' . $user['id'];
    $adminUrl = WEBAPP_URL . '/admin.html';

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => $shift_morning_text, 'callback_data' => 'select_morning'],
                ['text' => $shift_evening_text, 'callback_data' => 'select_evening'],
            ],
            [
                ['text' => $shift_button_text, 'callback_data' => $shift_callback],
            ],
            [
                ['text' => '📊 Статистика', 'url' => $statsUrl],
                ['text' => '📋 Мої зміни', 'callback_data' => 'my_shifts'],
            ],
            [
                ['text' => '📤 Реферальне посилання', 'callback_data' => 'referral_link'],
            ],
        ],
    ];

    if ($user['role'] === 'admin' || isAdmin($user['telegram_id'])) {
        $keyboard['inline_keyboard'][] = [
            ['text' => '🔐 Admin Panel', 'url' => $adminUrl],
        ];
    }

    return $keyboard;
}

function getTimerKeyboard($session) {
    $start = new DateTime($session['start_timestamp']);
    $now = new DateTime();
    $diff = $start->diff($now);
    $hours = $diff->h + ($diff->days * 24);
    $minutes = $diff->i;

    $shiftLabel = $session['shift_type'] === 'morning' ? '🌅 Ранкова' : '🌇 Вечірня';

    return [
        'inline_keyboard' => [
            [
                ['text' => "⏱ Працюєте: {$hours} год {$minutes} хв", 'callback_data' => 'timer_refresh'],
            ],
            [
                ['text' => "📋 Зміна: $shiftLabel", 'callback_data' => 'noop'],
            ],
            [
                ['text' => '⏹ Закінчити зміну', 'callback_data' => 'end_shift'],
            ],
            [
                ['text' => '🔄 Оновити таймер', 'callback_data' => 'timer_refresh'],
            ],
            [
                ['text' => '🏠 Головне меню', 'callback_data' => 'main_menu'],
            ],
        ],
    ];
}

// =============================================
// ДОПОМІЖНІ ФУНКЦІЇ
// =============================================

function formatHours($hours) {
    $h = floor($hours);
    $m = round(($hours - $h) * 60);
    return "{$h} год {$m} хв";
}

function formatDate($date) {
    return date('d.m.Y', strtotime($date));
}

function formatTime($datetime) {
    return date('H:i', strtotime($datetime));
}

function getShiftTypeLabel($type) {
    return $type === 'morning' ? '🌅 Ранкова' : '🌇 Вечірня';
}

function getMonthsList() {
    $months = [];
    for ($i = 0; $i < 12; $i++) {
        $date = date('Y-m', strtotime("-$i months"));
        $label = date('F Y', strtotime("-$i months"));
        $months[$date] = $label;
    }
    return $months;
}

function generateCSV($data, $headers) {
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, $headers);
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
}

function getUserAchievements($user_id) {
    $stats = getUserStats($user_id);
    $achievements = [];

    if ($stats['total_shifts'] >= 1) {
        $achievements[] = ['icon' => '🎯', 'title' => 'Перша зміна', 'desc' => 'Завершено першу зміну', 'earned' => true];
    } else {
        $achievements[] = ['icon' => '🎯', 'title' => 'Перша зміна', 'desc' => 'Завершіть першу зміну', 'earned' => false];
    }

    if ($stats['total_shifts'] >= 10) {
        $achievements[] = ['icon' => '⭐', 'title' => '10 змін', 'desc' => 'Завершено 10 змін', 'earned' => true];
    } else {
        $achievements[] = ['icon' => '⭐', 'title' => '10 змін', 'desc' => "Прогрес: {$stats['total_shifts']}/10", 'earned' => false, 'progress' => min(100, $stats['total_shifts'] * 10)];
    }

    if ($stats['total_hours'] >= 50) {
        $achievements[] = ['icon' => '🏆', 'title' => '50 годин', 'desc' => 'Відпрацьовано 50 годин', 'earned' => true];
    } else {
        $progress = min(100, round($stats['total_hours'] / 50 * 100));
        $achievements[] = ['icon' => '🏆', 'title' => '50 годин', 'desc' => "Прогрес: " . round($stats['total_hours']) . "/50 год", 'earned' => false, 'progress' => $progress];
    }

    if ($stats['total_hours'] >= 100) {
        $achievements[] = ['icon' => '💎', 'title' => '100 годин', 'desc' => 'Відпрацьовано 100 годин', 'earned' => true];
    } else {
        $progress = min(100, round($stats['total_hours'] / 100 * 100));
        $achievements[] = ['icon' => '💎', 'title' => '100 годин', 'desc' => "Прогрес: " . round($stats['total_hours']) . "/100 год", 'earned' => false, 'progress' => $progress];
    }

    if ($stats['total_shifts'] >= 50) {
        $achievements[] = ['icon' => '🔥', 'title' => '50 змін', 'desc' => 'Завершено 50 змін!', 'earned' => true];
    } else {
        $progress = min(100, round($stats['total_shifts'] / 50 * 100));
        $achievements[] = ['icon' => '🔥', 'title' => '50 змін', 'desc' => "Прогрес: {$stats['total_shifts']}/50", 'earned' => false, 'progress' => $progress];
    }

    return $achievements;
}

// =============================================
// ДОДАТКОВІ ФУНКЦІЇ ДЛЯ АДМІН-ПАНЕЛІ
// =============================================

function getActiveUsersCount() {
    $db = getDB();
    $result = $db->querySingle("SELECT COUNT(DISTINCT user_id) as cnt FROM work_sessions WHERE is_active = 1");
    return $result ?? 0;
}

function getTodayShiftsCount() {
    $db = getDB();
    $today = date('Y-m-d');
    $result = $db->querySingle("SELECT COUNT(*) as cnt FROM shifts WHERE date = '$today'");
    return $result ?? 0;
}

function getMonthShiftsCount() {
    $db = getDB();
    $month = date('Y-m');
    $result = $db->querySingle("SELECT COUNT(*) as cnt FROM shifts WHERE strftime('%Y-%m', date) = '$month'");
    return $result ?? 0;
}

function getAverageShiftsPerDay($days = 30) {
    $db = getDB();
    $dateFrom = date('Y-m-d', strtotime("-{$days} days"));
    $result = $db->querySingle("
        SELECT ROUND(COUNT(*) / $days, 1) as avg 
        FROM shifts 
        WHERE date >= '$dateFrom'
    ");
    return $result ?? 0;
}

function exportShiftsToCSV($user_id = null, $month = null) {
    $db = getDB();
    
    $where = "1=1";
    $params = [];
    
    if ($user_id) {
        $where .= " AND user_id = :uid";
        $params[':uid'] = $user_id;
    }
    
    if ($month) {
        $where .= " AND strftime('%Y-%m', date) = :month";
        $params[':month'] = $month;
    }
    
    $sql = "SELECT * FROM shifts WHERE $where ORDER BY date DESC, start_time DESC";
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $result = $stmt->execute();
    
    $data = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $user = getUserById($row['user_id']);
        $data[] = [
            'user' => $user['full_name'] ?? 'Unknown',
            'date' => $row['date'],
            'shift_type' => $row['shift_type'] == 'morning' ? 'Ранкова' : 'Вечірня',
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'total_hours' => $row['total_hours'],
        ];
    }
    
    return $data;
}
