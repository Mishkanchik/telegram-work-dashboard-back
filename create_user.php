<?php
// create_user.php - Створення користувача вручну
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$telegram_id = 761584410; // Ваш Telegram ID
$username = 'your_username'; // Ваш username в Telegram
$full_name = 'Your Full Name'; // Ваше ім'я

echo "🔧 Створення користувача...\n\n";

// Перевіряємо, чи існує користувач
$existing = getUserByTelegramId($telegram_id);
if ($existing) {
    echo "✅ Користувач вже існує!\n";
    echo "📋 ID: " . $existing['id'] . "\n";
    echo "📋 Ім'я: " . $existing['full_name'] . "\n";
    echo "📋 Роль: " . $existing['role'] . "\n";
    echo "📋 Реферальний код: " . $existing['referral_code'] . "\n";
} else {
    echo "❌ Користувача не знайдено. Створюємо...\n";
    
    // Створюємо користувача
    $user = createUser($telegram_id, $username, $full_name);
    
    if ($user) {
        echo "✅ Користувача успішно створено!\n";
        echo "📋 ID: " . $user['id'] . "\n";
        echo "📋 Telegram ID: " . $user['telegram_id'] . "\n";
        echo "📋 Ім'я: " . $user['full_name'] . "\n";
        echo "📋 Роль: " . $user['role'] . "\n";
        echo "📋 Реферальний код: " . $user['referral_code'] . "\n";
    } else {
        echo "❌ Не вдалося створити користувача!\n";
    }
}

// Перевіряємо, чи є користувач в БД після створення
echo "\n🔍 Перевірка в БД...\n";
$check = getUserByTelegramId($telegram_id);
if ($check) {
    echo "✅ Користувач є в БД!\n";
} else {
    echo "❌ Користувача немає в БД!\n";
}
