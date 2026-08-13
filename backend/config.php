<?php
// backend/config.php - Конфігурація

// Змінні середовища (Render)
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '8668204279:AAF82J6z4fy3ynU0t4L-UJMicz5aI9driqU');
define('BOT_USERNAME', getenv('BOT_USERNAME') ?: 'YourBotUsername');
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: 'admin123');
define('WEBAPP_URL', getenv('WEBAPP_URL') ?: 'https://telegram-work-dashboard.vercel.app');

// База даних SQLite
define('DB_PATH', __DIR__ . '/database/workbot.db');

// Часовий пояс
date_default_timezone_set('Europe/Kiev');