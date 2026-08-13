<?php
// test.php - Розширена перевірка

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "🚀 WorkTracker Backend Test\n";
echo "============================\n\n";

// 1. Перевірка конфігурації
echo "1. Перевірка config.php:\n";
if (file_exists(__DIR__ . '/config.php')) {
    echo "   ✅ config.php існує\n";
    require_once __DIR__ . '/config.php';
    echo "   ✅ BOT_TOKEN: " . substr(BOT_TOKEN, 0, 10) . "...\n";
    echo "   ✅ BOT_USERNAME: " . BOT_USERNAME . "\n";
    echo "   ✅ WEBHOOK_URL: " . WEBHOOK_URL . "\n";
    echo "   ✅ DB_PATH: " . DB_PATH . "\n";
    echo "   ✅ LOG_PATH: " . LOG_PATH . "\n";
} else {
    echo "   ❌ config.php не знайдено!\n";
    exit;
}

echo "\n2. Перевірка functions.php:\n";
if (file_exists(__DIR__ . '/functions.php')) {
    echo "   ✅ functions.php існує\n";
    echo "   ⏳ Спробуємо завантажити...\n";
    
    try {
        require_once __DIR__ . '/functions.php';
        echo "   ✅ functions.php завантажено успішно\n";
    } catch (Exception $e) {
        echo "   ❌ Помилка при завантаженні: " . $e->getMessage() . "\n";
        echo "   ❌ Файл: " . $e->getFile() . ":" . $e->getLine() . "\n";
        exit;
    }
} else {
    echo "   ❌ functions.php не знайдено!\n";
    exit;
}

echo "\n3. Перевірка функцій з functions.php:\n";
$requiredFunctions = [
    'getDB',
    'getUserByTelegramId', 
    'createUser',
    'sendMessage',
    'getMainMenuKeyboard',
    'isAdmin',
    'getActiveSession',
    'startSession',
    'endSession',
    'writeLog'
];

$allExist = true;
foreach ($requiredFunctions as $func) {
    if (function_exists($func)) {
        echo "   ✅ Функція '$func' існує\n";
    } else {
        echo "   ❌ Функція '$func' ВІДСУТНЯ!\n";
        $allExist = false;
    }
}

if (!$allExist) {
    echo "\n⚠️ Деякі функції відсутні! Перевірте functions.php\n";
}

echo "\n4. Перевірка бази даних:\n";
try {
    if (function_exists('getDB')) {
        $db = getDB();
        echo "   ✅ Підключення до БД успішне\n";
        
        // Перевіряємо таблиці
        $tables = ['users', 'shifts', 'work_sessions', 'action_logs'];
        foreach ($tables as $table) {
            $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
            if ($result->fetchArray()) {
                echo "   ✅ Таблиця '$table' існує\n";
            } else {
                echo "   ❌ Таблиця '$table' відсутня!\n";
            }
        }
    } else {
        echo "   ❌ Функція getDB() не існує\n";
    }
} catch (Exception $e) {
    echo "   ❌ Помилка БД: " . $e->getMessage() . "\n";
}

echo "\n5. Перевірка папок:\n";
$folders = ['database', 'logs'];
foreach ($folders as $folder) {
    $path = __DIR__ . '/' . $folder;
    if (is_dir($path)) {
        echo "   ✅ Папка '$folder' існує\n";
        if (is_writable($path)) {
            echo "   ✅ Папка '$folder' доступна для запису\n";
        } else {
            echo "   ❌ Папка '$folder' НЕ доступна для запису!\n";
        }
    } else {
        echo "   ❌ Папка '$folder' відсутня!\n";
        // Спробуємо створити
        if (mkdir($path, 0777, true)) {
            echo "   ✅ Папку '$folder' створено!\n";
        } else {
            echo "   ❌ Не вдалося створити папку '$folder'\n";
        }
    }
}

echo "\n6. Перевірка webhook:\n";
if (function_exists('sendTelegramRequest')) {
    $result = sendTelegramRequest('getWebhookInfo');
    if ($result && $result['ok']) {
        echo "   ✅ Webhook налаштовано на: " . ($result['result']['url'] ?? 'не вказано') . "\n";
        echo "   📊 Очікує оновлень: " . ($result['result']['pending_update_count'] ?? 0) . "\n";
    } else {
        echo "   ❌ Помилка перевірки webhook\n";
    }
} else {
    echo "   ❌ Функція sendTelegramRequest не існує\n";
}

echo "\n============================\n";
echo "✅ Тест завершено!\n";

// Перевірка, чи є помилки в логах
if (file_exists(LOG_PATH)) {
    echo "\n📋 Останні 5 рядків логу:\n";
    $log = file_get_contents(LOG_PATH);
    $lines = array_slice(explode("\n", $log), -5);
    foreach ($lines as $line) {
        if (trim($line)) {
            echo "   " . $line . "\n";
        }
    }
}
