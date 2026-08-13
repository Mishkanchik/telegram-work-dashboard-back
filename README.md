# WorkTracker Backend

PHP API для WorkTracker - облік робочих змін через Telegram бота.

## Деплой на Render

1. Створіть новий Web Service на Render
2. Підключіть репозиторій
3. Вкажіть Root Directory: `backend`
4. Environment: PHP
5. Build Command: `composer install` (якщо є composer.json)
6. Start Command: `php -S 0.0.0.0:$PORT`

### Змінні середовища на Render

```
BOT_TOKEN=your_telegram_bot_token
BOT_USERNAME=YourBotUsername
ADMIN_PASSWORD=secure_admin_password
WEBAPP_URL=https://your-frontend.vercel.app
```

## API Endpoints

### User API
- `GET /api/user.php?user_id={id}` - Отримати дані користувача

### Stats API
- `GET /api/stats.php?user_id={id}&month={YYYY-MM}` - Статистика користувача

### Admin API
- `POST /api/admin/auth.php` - Автентифікація адміна
- `GET /api/admin/stats.php?month={YYYY-MM}` - Загальна статистика
- `GET /api/admin/export.php?month={YYYY-MM}` - Експорт в CSV

### Webhook
- `POST /webhook.php` - Обробник Telegram webhook

## Структура

```
backend/
├── config.php          # Конфігурація (змінні середовища)
├── functions.php       # Функції БД та API
├── webhook.php         # Telegram webhook обробник
├── database/           # SQLite база даних
└── logs/               # Логи
```

## Налаштування Webhook

Після деплою на Render, встановіть webhook:

```bash
curl -X POST "https://api.telegram.org/bot{BOT_TOKEN}/setWebhook" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://your-backend.onrender.com/webhook.php"}'
```

Або відкрийте у браузері:
```
https://your-backend.onrender.com/set_webhook.php
```

## База даних

Використовується SQLite (файлова БД). Автоматично створюється при першому запуску.

### Таблиці:
- `users` - Користувачі
- `shifts` - Робочі зміни
- `work_sessions` - Активні сесії