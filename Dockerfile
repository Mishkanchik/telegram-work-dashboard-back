FROM php:8.2-apache

# Встановлюємо системні залежності для SQLite
RUN apt-get update && apt-get install -y \
    sqlite3 \
    libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# Встановлюємо PHP розширення
RUN docker-php-ext-install pdo_sqlite

# Вмикаємо Apache модулі
RUN a2enmod rewrite

# Копіюємо код
COPY . /var/www/html/

# Встановлюємо права
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Створюємо папки для БД та логів
RUN mkdir -p /var/www/html/database \
    && mkdir -p /var/www/html/logs \
    && chmod 777 /var/www/html/database \
    && chmod 777 /var/www/html/logs

# Створюємо index.php (якщо його немає)
RUN echo '<?php echo "✅ WorkTracker Backend is running!"; ?>' > /var/www/html/index.php

# Налаштовуємо Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

EXPOSE 8080
