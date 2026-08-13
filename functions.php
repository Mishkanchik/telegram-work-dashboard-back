<?php
require_once __DIR__ . '/config.php';

// =============================================
// DATABASE FUNCTIONS
// =============================================

function getDB() {
    $dbPath = DB_PATH;
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $db;
}

function initDB() {
    $db = getDB();
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            telegram_id INTEGER UNIQUE NOT NULL,
            username TEXT,
            full_name TEXT,
            role TEXT DEFAULT 'worker',
            is_active INTEGER DEFAULT 1,
            registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            referral_code TEXT UNIQUE,
            referred_by_id INTEGER,
            last_activity DATETIME
        )
    ");
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS shifts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            shift_type TEXT NOT NULL,
            start_time DATETIME NOT NULL,
            end_time DATETIME,
            total_hours REAL DEFAULT 0,
            date DATE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS work_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            shift_type TEXT NOT NULL,
            start_timestamp DATETIME NOT NULL,
            end_timestamp DATETIME,
            is_active INTEGER DEFAULT 1,
            last_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            target_user_id INTEGER,
            details TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Create referral codes for existing users
    $stmt = $db->query("SELECT id FROM users WHERE referral_code IS NULL");
    foreach ($stmt->fetchAll() as $row) {
        $code = 'ref_' . $row['id'] . '_' . bin2hex(random_bytes(4));
        $db->prepare("UPDATE users SET referral_code = ? WHERE id = ?")->execute([$code, $row['id']]);
    }
}

// =============================================
// USER FUNCTIONS
// =============================================

function getUserByTelegramId($telegramId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegramId]);
    return $stmt->fetch();
}

function createUser($telegramId, $username, $fullName, $referredByCode = null) {
    $db = getDB();
    
    $referralCode = 'ref_' . $telegramId . '_' . bin2hex(random_bytes(4));
    $referredById = null;
    
    if ($referredByCode) {
        $stmt = $db->prepare("SELECT id FROM users WHERE referral_code = ?");
        $stmt->execute([$referredByCode]);
        $referrer = $stmt->fetch();
        if ($referrer) {
            $referredById = $referrer['id'];
        }
    }
    
    $stmt = $db->prepare("
        INSERT INTO users (telegram_id, username, full_name, referral_code, referred_by_id, last_activity)
        VALUES (?, ?, ?, ?, ?, datetime('now'))
    ");
    $stmt->execute([$telegramId, $username, $fullName, $referralCode, $referredById]);
    
    return $db->lastInsertId();
}

function getUserStats($userId) {
    $db = getDB();
    
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_shifts,
            SUM(total_hours) as total_hours,
            AVG(total_hours) as avg_hours,
            SUM(CASE WHEN shift_type = 'morning' THEN 1 ELSE 0 END) as morning_shifts,
            SUM(CASE WHEN shift_type = 'evening' THEN 1 ELSE 0 END) as evening_shifts
        FROM shifts 
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch();
    
    // This month
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as month_shifts,
            SUM(total_hours) as month_hours
        FROM shifts 
        WHERE user_id = ? AND date >= date('now', 'start of month')
    ");
    $stmt->execute([$userId]);
    $month = $stmt->fetch();
    
    return array_merge($stats, $month);
}

function getUserShifts($userId, $limit = 50, $offset = 0) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT * FROM shifts 
        WHERE user_id = ? 
        ORDER BY date DESC, start_time DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$userId, $limit, $offset]);
    return $stmt->fetchAll();
}

function getActiveSession($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM work_sessions WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

function startShift($userId, $shiftType) {
    $db = getDB();
    
    // Check if already has active session
    $active = getActiveSession($userId);
    if ($active) {
        return ['success' => false, 'error' => 'Already have active shift'];
    }
    
    // Validate time (not after 23:00)
    $hour = (int)date('H');
    if ($hour >= 23) {
        return ['success' => false, 'error' => 'Cannot start shift after 23:00'];
    }
    
    $stmt = $db->prepare("
        INSERT INTO work_sessions (user_id, shift_type, start_timestamp, is_active, last_updated)
        VALUES (?, ?, datetime('now'), 1, datetime('now'))
    ");
    $stmt->execute([$userId, $shiftType]);
    
    return ['success' => true, 'session_id' => $db->lastInsertId()];
}

function endShift($userId) {
    $db = getDB();
    
    $active = getActiveSession($userId);
    if (!$active) {
        return ['success' => false, 'error' => 'No active shift'];
    }
    
    $start = new DateTime($active['start_timestamp']);
    $end = new DateTime();
    $hours = $start->diff($end)->h + ($start->diff($end)->i / 60) + ($start->diff($end)->s / 3600);
    
    // Update session
    $stmt = $db->prepare("
        UPDATE work_sessions 
        SET end_timestamp = datetime('now'), is_active = 0, last_updated = datetime('now')
        WHERE id = ?
    ");
    $stmt->execute([$active['id']]);
    
    // Create shift record
    $stmt = $db->prepare("
        INSERT INTO shifts (user_id, shift_type, start_time, end_time, total_hours, date)
        VALUES (?, ?, ?, datetime('now'), ?, date('now'))
    ");
    $stmt->execute([$userId, $active['shift_type'], $active['start_timestamp'], round($hours, 2)]);
    
    return ['success' => true, 'hours' => round($hours, 2)];
}

function autoEndShifts() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM work_sessions WHERE is_active = 1");
    $sessions = $stmt->fetchAll();
    
    foreach ($sessions as $session) {
        $start = new DateTime($session['start_timestamp']);
        $end = new DateTime();
        $hours = $start->diff($end)->h + ($start->diff($end)->i / 60);
        
        $db->prepare("UPDATE work_sessions SET end_timestamp = datetime('now'), is_active = 0 WHERE id = ?")
           ->execute([$session['id']]);
        
        $db->prepare("INSERT INTO shifts (user_id, shift_type, start_time, end_time, total_hours, date) VALUES (?, ?, ?, datetime('now'), ?, date('now'))")
           ->execute([$session['user_id'], $session['shift_type'], $session['start_timestamp'], round($hours, 2)]);
    }
    
    return count($sessions);
}

// =============================================
// ADMIN FUNCTIONS
// =============================================

function verifyAdminPassword($password) {
    return $password === ADMIN_PASSWORD;
}

function getAllWorkersStats() {
    $db = getDB();
    $stmt = $db->query("
        SELECT 
            u.id,
            u.telegram_id,
            u.username,
            u.full_name,
            u.registered_at,
            u.last_activity,
            COUNT(s.id) as total_shifts,
            SUM(s.total_hours) as total_hours,
            AVG(s.total_hours) as avg_hours,
            SUM(CASE WHEN s.shift_type = 'morning' THEN 1 ELSE 0 END) as morning_shifts,
            SUM(CASE WHEN s.shift_type = 'evening' THEN 1 ELSE 0 END) as evening_shifts
        FROM users u
        LEFT JOIN shifts s ON u.id = s.user_id
        WHERE u.role = 'worker'
        GROUP BY u.id
        ORDER BY total_hours DESC
    ");
    return $stmt->fetchAll();
}

function getActiveShiftsNow() {
    $db = getDB();
    $stmt = $db->query("
        SELECT 
            ws.*,
            u.username,
            u.full_name,
            u.telegram_id
        FROM work_sessions ws
        JOIN users u ON ws.user_id = u.id
        WHERE ws.is_active = 1
    ");
    return $stmt->fetchAll();
}

function getTotalStats() {
    $db = getDB();
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'worker'");
    $totalWorkers = $stmt->fetch()['count'];
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM work_sessions WHERE is_active = 1");
    $activeToday = $stmt->fetch()['count'];
    
    $stmt = $db->query("SELECT SUM(total_hours) as total FROM shifts");
    $totalHours = $stmt->fetch()['total'] ?? 0;
    
    $stmt = $db->query("
        SELECT u.full_name, SUM(s.total_hours) as hours
        FROM shifts s
        JOIN users u ON s.user_id = u.id
        GROUP BY s.user_id
        ORDER BY hours DESC
        LIMIT 1
    ");
    $topWorker = $stmt->fetch();
    
    return [
        'total_workers' => $totalWorkers,
        'active_today' => $activeToday,
        'total_hours' => round($totalHours, 1),
        'top_worker' => $topWorker
    ];
}

function getHourlyActivity() {
    $db = getDB();
    $stmt = $db->query("
        SELECT 
            CAST(strftime('%H', start_time) AS INTEGER) as hour,
            COUNT(*) as count
        FROM shifts
        WHERE date >= date('now', '-7 days')
        GROUP BY hour
        ORDER BY hour
    ");
    $data = $stmt->fetchAll();
    
    $result = [];
    for ($i = 0; $i <= 23; $i++) {
        $result[$i] = 0;
    }
    foreach ($data as $row) {
        $result[$row['hour']] = $row['count'];
    }
    return $result;
}

function getShiftComparison() {
    $db = getDB();
    $stmt = $db->query("
        SELECT 
            date,
            SUM(CASE WHEN shift_type = 'morning' THEN total_hours ELSE 0 END) as morning_hours,
            SUM(CASE WHEN shift_type = 'evening' THEN total_hours ELSE 0 END) as evening_hours
        FROM shifts
        WHERE date >= date('now', '-30 days')
        GROUP BY date
        ORDER BY date
    ");
    return $stmt->fetchAll();
}

function logAdminAction($adminId, $action, $targetUserId = null, $details = null) {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO admin_logs (admin_id, action, target_user_id, details)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$adminId, $action, $targetUserId, $details]);
}

function exportShiftsCSV($userId = null, $startDate = null, $endDate = null) {
    $db = getDB();
    
    $sql = "
        SELECT 
            u.full_name,
            u.username,
            s.date,
            s.shift_type,
            s.start_time,
            s.end_time,
            s.total_hours
        FROM shifts s
        JOIN users u ON s.user_id = u.id
        WHERE 1=1
    ";
    $params = [];
    
    if ($userId) {
        $sql .= " AND s.user_id = ?";
        $params[] = $userId;
    }
    if ($startDate) {
        $sql .= " AND s.date >= ?";
        $params[] = $startDate;
    }
    if ($endDate) {
        $sql .= " AND s.date <= ?";
        $params[] = $endDate;
    }
    
    $sql .= " ORDER BY s.date DESC, s.start_time DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// =============================================
// ACHIEVEMENTS
// =============================================

function getAchievements($userId) {
    $stats = getUserStats($userId);
    $totalShifts = $stats['total_shifts'] ?? 0;
    $totalHours = $stats['total_hours'] ?? 0;
    
    $achievements = [
        ['id' => 'first_shift', 'name' => 'Перша зміна', 'icon' => '🎯', 'unlocked' => $totalShifts >= 1, 'progress' => min(100, $totalShifts * 100)],
        ['id' => 'ten_shifts', 'name' => '10 змін', 'icon' => '⭐', 'unlocked' => $totalShifts >= 10, 'progress' => min(100, $totalShifts * 10)],
        ['id' => 'fifty_shifts', 'name' => '50 змін', 'icon' => '🏆', 'unlocked' => $totalShifts >= 50, 'progress' => min(100, $totalShifts * 2)],
        ['id' => 'ten_hours', 'name' => '10 годин', 'icon' => '⏱', 'unlocked' => $totalHours >= 10, 'progress' => min(100, $totalHours * 10)],
        ['id' => 'fifty_hours', 'name' => '50 годин', 'icon' => '💪', 'unlocked' => $totalHours >= 50, 'progress' => min(100, $totalHours * 2)],
        ['id' => 'hundred_hours', 'name' => '100 годин', 'icon' => '🔥', 'unlocked' => $totalHours >= 100, 'progress' => min(100, $totalHours)],
    ];
    
    return $achievements;
}

// =============================================
// HELPERS
// =============================================

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function requireAuth() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? '';
    
    if (!preg_match('/^Bearer\s+(.+)$/', $auth, $matches)) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }
    
    $token = $matches[1];
    // Simple token validation - in production use JWT
    if ($token !== ADMIN_PASSWORD) {
        jsonResponse(['error' => 'Invalid token'], 401);
    }
    
    return true;
}

function corsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}