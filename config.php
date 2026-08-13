<?php
/**
 * WorkTracker - Конфігурація
 * 
 * Скопіюйте цей файл у config.php та заповніть свої даними
 */

// ====================
// TELEGRAM BOT SETTINGS
// ====================
define('BOT_TOKEN', '8668204279:AAF82J6z4fy3ynU0t4L-UJMicz5aI9driqU');  // ✅ Ваш токен
define('BOT_USERNAME', 'Work_Dashboard_bot');                         // ✅ Username бота без @
define('WEBAPP_URL', 'https://telegram-work-dashboard.vercel.app');   // ✅ Ваш фронтенд на Vercel

// ====================
// WEBHOOK SETTINGS
// ====================
define('WEBHOOK_URL', 'https://telegram-work-dashboard-back.onrender.com/webhook.php'); // ✅ Webhook на Render

// ====================
// ADMIN SETTINGS
// ====================
define('ADMIN_IDS', '123456789,987654321');           // Ваш Telegram ID
define('ADMIN_PASSWORD', 'change_me_secure_password'); // Пароль для адмін-панелі

// ====================
// DATABASE SETTINGS
// ====================
define('DB_PATH', __DIR__ . '/database/workbot.db');

// ====================
// LOG SETTINGS
// ====================
define('LOG_PATH', __DIR__ . '/logs/webhook.log');    // Шлях до файлу логів

// ====================
// TIMEZONE
// ====================
define('TIMEZONE', 'Europe/Kiev');
date_default_timezone_set(TIMEZONE);

// ====================
// DEBUG MODE
// ====================
define('DEBUG', false);                               // На Render краще false
error_reporting(DEBUG ? E_ALL : 0);
ini_set('display_errors', DEBUG ? '1' : '0');
