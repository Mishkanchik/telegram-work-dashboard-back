<?php
// =============================================
// CONFIGURATION
// =============================================

// Telegram Bot
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '8668204279:AAF82J6z4fy3ynU0t4L-UJMicz5aI9driqU');
define('BOT_USERNAME', getenv('BOT_USERNAME') ?: 'Work_Dashboard_bot');

// Admin
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: 'admin123');
define('ADMIN_IDS', getenv('ADMIN_IDS') ?: '123456789');

// URLs
define('WEBAPP_URL', getenv('WEBAPP_URL') ?: 'https://telegram-work-dashboard.vercel.app');
define('WEBHOOK_URL', getenv('WEBHOOK_URL') ?: 'https://telegram-work-dashboard-back.onrender.com/webhook.php');

// Database
define('DB_PATH', __DIR__ . '/database/workbot.db');

// Timezone
date_default_timezone_set('Europe/Kiev');