<?php
/**
 * WorkTracker - Конфігурація
 * 
 * Скопіюйте цей файл у config.php та заповніть свої дані:
 * cp config.php.example config.php
 */

// ====================
// TELEGRAM BOT SETTINGS
// ====================
define('BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE');           // Токен від @BotFather
define('BOT_USERNAME', 'YourBotUsername');            // Username бота без @
define('WEBAPP_URL', 'https://your-domain.com');      // URL вашого сайту (HTTPS обов'язково)

// ====================
// WEBHOOK SETTINGS
// ====================
define('WEBHOOK_URL', WEBAPP_URL . '/webhook.php');   // Повний URL до webhook.php

// ====================
// ADMIN SETTINGS
// ====================
define('ADMIN_IDS', '123456789,987654321');           // Telegram ID адмінів (через кому)
define('ADMIN_PASSWORD', 'change_me_secure_password'); // Пароль для адмін-панелі

// ====================
// DATABASE SETTINGS
// ====================
define('DB_PATH', __DIR__ . '/database/workbot.db');  // Шлях до SQLite БД

// ====================
// TIMEZONE
// ====================
define('TIMEZONE', 'Europe/Kiev');                    // Часовий пояс
date_default_timezone_set(TIMEZONE);

// ====================
// DEBUG MODE
// ====================
define('DEBUG', true);                                // true для розробки, false для продакшну
error_reporting(DEBUG ? E_ALL : 0);
ini_set('display_errors', DEBUG ? '1' : '0');