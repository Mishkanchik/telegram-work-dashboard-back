# WorkTracker Backend API

PHP + SQLite backend for Telegram Work Tracker Bot.

## Structure

```
backend/
├── config.php              # Configuration (env variables)
├── functions.php           # Core functions (DB, users, shifts, admin)
├── webhook.php             # Telegram webhook handler
├── set_webhook.php         # Set webhook script
├── api/
│   ├── stats.php           # User statistics
│   ├── user.php            # User profile + active session
│   ├── shifts.php          # User shifts list
│   ├── achievements.php    # User achievements
│   └── admin/
│       ├── auth.php        # Admin authentication
│       ├── stats.php       # Admin dashboard stats
│       └── export.php      # CSV export
└── database/               # SQLite database (auto-created)
```

## API Endpoints

### Public
- `GET /api/stats.php?telegram_id=123` - User stats + achievements
- `GET /api/user.php?telegram_id=123` - User profile + active session
- `GET /api/shifts.php?telegram_id=123&limit=50&offset=0` - User shifts
- `GET /api/achievements.php?telegram_id=123` - User achievements

### Admin (requires Authorization: Bearer ADMIN_PASSWORD)
- `POST /api/admin/auth.php` - Login, returns token
- `GET /api/admin/stats.php` - All workers stats, active shifts, charts data
- `GET /api/admin/export.php?user_id=&start_date=&end_date=` - CSV export

## Deployment (Render)

1. Create new Web Service
2. Connect GitHub repo
3. Build Command: (empty)
4. Start Command: `php -S 0.0.0.0:$PORT`
5. Add Environment Variables:
   - `BOT_TOKEN`
   - `BOT_USERNAME`
   - `ADMIN_PASSWORD`
   - `WEBAPP_URL`
   - `WEBHOOK_URL`
   - `ADMIN_IDS`

## Set Webhook

After deployment, run:
```
https://your-backend.onrender.com/set_webhook.php
```

## Database

SQLite database auto-created at `database/workbot.db` with tables:
- `users` - Telegram users
- `shifts` - Completed shifts
- `work_sessions` - Active sessions
- `admin_logs` - Admin actions