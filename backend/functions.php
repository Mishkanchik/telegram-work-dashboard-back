<?php
// backend/functions.php - Функції БД та API

// =============================================
// ПІДКЛЮЧЕННЯ ДО БАЗИ ДАНИХ
// =============================================

function getDB() {
    static $db = null;
    if ($db === null) {
        $dbPath = defined('DB_PATH') ? DB_PATH : __DIR__ . '/database/workbot.db';
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA foreign_keys = ON');
        initDatabase($db);
    }
    return $db;
}

function initDatabase($db) {
    $db->exec('
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            telegram_id INTEGER UNIQUE NOT NULL,
            username TEXT,
            full_name TEXT NOT NULL,
            role TEXT DEFAULT "worker",
            is_active INTEGER DEFAULT 1,
            registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            referral_code TEXT UNIQUE,
            referred_by_id INTEGER
        );
        
        CREATE TABLE IF NOT EXISTS shifts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            shift_type TEXT NOT NULL,
            start_time DATETIME NOT NULL,
            end_time DATETIME,
            total_hours REAL,
            date DATE NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
        
        CREATE TABLE IF NOT EXISTS work_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            shift_type TEXT NOT NULL,
            start_timestamp DATETIME NOT NULL,
            end_timestamp DATETIME,
            is_active INTEGER DEFAULT 1,
            last_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
    ');
}

// =============================================
// КОРИСТУВАЧІ
// =============================================

function getUserById($userId) {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getUserByTelegramId($telegramId) {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE telegram_id = ?');
    $stmt->execute([$telegramId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function createUser($telegramId, $username, $fullName, $referredByCode = null) {
    $db = getDB();
    $referralCode = 'ref_' . substr(md5($telegramId . time()), 0, 8);
    
    $referredById = null;
    if ($referredByCode) {
        $stmt = $db->prepare('SELECT id FROM users WHERE referral_code = ?');
        $stmt->execute([$referredByCode]);
        $referrer = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($referrer) {
            $referredById = $referrer['id'];
        }
    }
    
    $stmt = $db->prepare('
        INSERT INTO users (telegram_id, username, full_name, referral_code, referred_by_id)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$telegramId, $username, $fullName, $referralCode, $referredById]);
    
    return $db->lastInsertId();
}

function generateReferralCode($userId) {
    $db = getDB();
    $code = 'ref_' . substr(md5($userId . time()), 0, 8);
    $stmt = $db->prepare('UPDATE users SET referral_code = ? WHERE id = ?');
    $stmt->execute([$code, $userId]);
    return $code;
}

// =============================================
// СТАТИСТИКА
// =============================================

function getUserStats($userId, $month = null) {
    $db = getDB();
    
    $whereClause = 'user_id = ?';
    $params = [$userId];
    
    if ($month) {
        $whereClause .= ' AND strftime("%Y-%m", date) = ?';
        $params[] = $month;
    }
    
    $stmt = $db->prepare('
        SELECT 
            COUNT(*) as total_shifts,
            COALESCE(SUM(total_hours), 0) as total_hours,
            COALESCE(AVG(total_hours), 0) as avg_hours,
            SUM(CASE WHEN shift_type = "morning" THEN 1 ELSE 0 END) as morning_shifts,
            SUM(CASE WHEN shift_type = "evening" THEN 1 ELSE 0 END) as evening_shifts
        FROM shifts 
        WHERE ' . $whereClause
    );
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getUserDailyHours($userId, $days = 30, $month = null) {
    $db = getDB();
    
    $whereClause = 'user_id = ?';
    $params = [$userId];
    
    if ($month) {
        $whereClause .= ' AND strftime("%Y-%m", date) = ?';
        $params[] = $month;
    } else {
        $whereClause .= ' AND date >= date("now", "-' . $days . ' days")';
    }
    
    $stmt = $db->prepare('
        SELECT date, SUM(total_hours) as hours
        FROM shifts 
        WHERE ' . $whereClause . '
        GROUP BY date
        ORDER BY date ASC
    ');
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getUserShifts($userId, $month = null, $limit = null, $offset = 0) {
    $db = getDB();
    
    $whereClause = 'user_id = ?';
    $params = [$userId];
    
    if ($month) {
        $whereClause .= ' AND strftime("%Y-%m", date) = ?';
        $params[] = $month;
    }
    
    $sql = 'SELECT * FROM shifts WHERE ' . $whereClause . ' ORDER BY date DESC';
    
    if ($limit) {
        $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getUserAchievements($userId) {
    $stats = getUserStats($userId);
    $totalShifts = (int)($stats['total_shifts'] ?? 0);
    $totalHours = (float)($stats['total_hours'] ?? 0);
    
    $achievements = [
        ['icon' => '🎯', 'title' => 'Перша зміна', 'desc' => 'Завершіть першу зміну', 'earned' => $totalShifts >= 1],
        ['icon' => '⭐', 'title' => '10 змін', 'desc' => 'Завершіть 10 змін', 'earned' => $totalShifts >= 10],
        ['icon' => '🌟', 'title' => '25 змін', 'desc' => 'Завершіть 25 змін', 'earned' => $totalShifts >= 25],
        ['icon' => '🏆', 'title' => '50 змін', 'desc' => 'Завершіть 50 змін', 'earned' => $totalShifts >= 50],
        ['icon' => '⏱', 'title' => '50 годин', 'desc' => 'Відпрацюйте 50 годин', 'earned' => $totalHours >= 50],
        ['icon' => '⏰', 'title' => '100 годин', 'desc' => 'Відпрацюйте 100 годин', 'earned' => $totalHours >= 100],
        ['icon' => '🔥', 'title' => '200 годин', 'desc' => 'Відпрацюйте 200 годин', 'earned' => $totalHours >= 200],
        ['icon' => '💪', 'title' => '500 годин', 'desc' => 'Відпрацюйте 500 годин', 'earned' => $totalHours >= 500],
    ];
    
    return $achievements;
}

// =============================================
// ЗМІНИ ТА СЕСІЇ
// =============================================

function startShift($userId, $shiftType) {
    $db = getDB();
    
    // Перевірка активної сесії
    $stmt = $db->prepare('SELECT id FROM work_sessions WHERE user_id = ? AND is_active = 1');
    $stmt->execute([$userId]);
    if ($stmt->fetch()) {
        return ['error' => 'Вже є активна зміна'];
    }
    
    $stmt = $db->prepare('
        INSERT INTO work_sessions (user_id, shift_type, start_timestamp)
        VALUES (?, ?, datetime("now"))
    ');
    $stmt->execute([$userId, $shiftType]);
    
    return ['success' => true, 'session_id' => $db->lastInsertId()];
}

function endShift($userId) {
    $db = getDB();
    
    $stmt = $db->prepare('SELECT * FROM work_sessions WHERE user_id = ? AND is_active = 1');
    $stmt->execute([$userId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        return ['error' => 'Немає активної зміни'];
    }
    
    $endTime = date('Y-m-d H:i:s');
    $startTime = new DateTime($session['start_timestamp']);
    $end = new DateTime($endTime);
    $totalHours = ($end->getTimestamp() - $startTime->getTimestamp()) / 3600;
    
    // Оновлюємо сесію
    $stmt = $db->prepare('UPDATE work_sessions SET end_timestamp = ?, is_active = 0 WHERE id = ?');
    $stmt->execute([$endTime, $session['id']]);
    
    // Створюємо запис про зміну
    $stmt = $db->prepare('
        INSERT INTO shifts (user_id, shift_type, start_time, end_time, total_hours, date)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $userId,
        $session['shift_type'],
        $session['start_timestamp'],
        $endTime,
        $totalHours,
        date('Y-m-d')
    ]);
    
    return ['success' => true, 'total_hours' => round($totalHours, 2)];
}

function getActiveSession($userId) {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM work_sessions WHERE user_id = ? AND is_active = 1');
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAllActiveSessions() {
    $db = getDB();
    $stmt = $db->query('
        SELECT ws.*, u.full_name, u.username 
        FROM work_sessions ws 
        JOIN users u ON ws.user_id = u.id 
        WHERE ws.is_active = 1
    ');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// =============================================
// АДМІН ФУНКЦІЇ
// =============================================

function getAdminStats($month = null) {
    $db = getDB();
    
    $whereClause = '1=1';
    $params = [];
    
    if ($month) {
        $whereClause = 'strftime("%Y-%m", date) = ?';
        $params = [$month];
    }
    
    // Загальна статистика
    $stmt = $db->prepare('
        SELECT 
            COUNT(DISTINCT user_id) as active_workers,
            COUNT(*) as total_shifts,
            COALESCE(SUM(total_hours), 0) as total_hours,
            COALESCE(AVG(total_hours), 0) as avg_hours
        FROM shifts 
        WHERE ' . $whereClause
    );
    $stmt->execute($params);
    $globalStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Топ працівників
    $stmt = $db->prepare('
        SELECT 
            u.id, u.full_name, u.username, u.role,
            COUNT(s.id) as total_shifts,
            SUM(CASE WHEN s.shift_type = "morning" THEN 1 ELSE 0 END) as morning_shifts,
            SUM(CASE WHEN s.shift_type = "evening" THEN 1 ELSE 0 END) as evening_shifts,
            COALESCE(SUM(s.total_hours), 0) as total_hours,
            COALESCE(AVG(s.total_hours), 0) as avg_hours
        FROM users u
        LEFT JOIN shifts s ON u.id = s.user_id AND ' . $whereClause . '
        GROUP BY u.id
        ORDER BY total_hours DESC
        LIMIT 10
    ');
    $stmt->execute($params);
    $topWorkers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Всі працівники
    $stmt = $db->prepare('
        SELECT 
            u.id, u.full_name, u.username, u.role,
            COUNT(s.id) as total_shifts,
            SUM(CASE WHEN s.shift_type = "morning" THEN 1 ELSE 0 END) as morning_shifts,
            SUM(CASE WHEN s.shift_type = "evening" THEN 1 ELSE 0 END) as evening_shifts,
            COALESCE(SUM(s.total_hours), 0) as total_hours,
            COALESCE(AVG(s.total_hours), 0) as avg_hours
        FROM users u
        LEFT JOIN shifts s ON u.id = s.user_id AND ' . $whereClause . '
        GROUP BY u.id
        ORDER BY total_hours DESC
    ');
    $stmt->execute($params);
    $allWorkers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Активні сесії
    $activeSessions = getAllActiveSessions();
    
    // Активність по годинах
    $hourlyActivity = array_fill(0, 24, 0);
    $stmt = $db->query('
        SELECT CAST(strftime("%H", start_time) AS INTEGER) as hour, COUNT(*) as count
        FROM shifts
        GROUP BY hour
    ');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $hourlyActivity[$row['hour']] = (int)$row['count'];
    }
    
    return [
        'globalStats' => $globalStats,
        'topWorkers' => $topWorkers,
        'allWorkers' => $allWorkers,
        'activeSessions' => $activeSessions,
        'hourlyActivity' => $hourlyActivity
    ];
}

function exportToCSV($month = null) {
    $db = getDB();
    
    $whereClause = '1=1';
    $params = [];
    
    if ($month) {
        $whereClause = 'strftime("%Y-%m", s.date) = ?';
        $params = [$month];
    }
    
    $stmt = $db->prepare('
        SELECT 
            u.full_name, u.username, s.date, s.shift_type, 
            s.start_time, s.end_time, s.total_hours
        FROM shifts s
        JOIN users u ON s.user_id = u.id
        WHERE ' . $whereClause . '
        ORDER BY s.date DESC
    ');
    $stmt->execute($params);
    
    $output = "Ім'я,Username,Дата,Зміна,Початок,Кінець,Години\n";
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $output .= sprintf(
            '"%s","%s","%s","%s","%s","%s","%s"' . "\n",
            $row['full_name'],
            $row['username'] ?: '-',
            $row['date'],
            $row['shift_type'] === 'morning' ? 'Ранкова' : 'Вечірня',
            $row['start_time'],
            $row['end_time'] ?: '-',
            round($row['total_hours'], 2)
        );
    }
    
    return $output;
}