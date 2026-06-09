FROM php:8.4-apache

# Systemowe zależności
RUN apt-get update && apt-get install -y --no-install-recommends \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    libxml2-dev \
    libmagickwand-dev \
    libicu-dev \
    libonig-dev \
    libsqlite3-dev \
    libssl-dev \
    default-mysql-client \
    unzip \
    git \
    cron \
    msmtp \
    && rm -rf /var/lib/apt/lists/*

# Rozszerzenia PHP (SQLite + MySQL + extras)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        bcmath \
        intl \
        mbstring \
        opcache \
        pdo \
        pdo_sqlite \
        pdo_mysql \
        xml \
        xmlwriter \
        zip \
    && pecl install imagick redis \
    && docker-php-ext-enable imagick redis

# Apache: mod_rewrite + headers + remoteip (dla Traefik X-Forwarded-For)
RUN a2enmod rewrite headers remoteip

COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY php.ini     /usr/local/etc/php/conf.d/app.ini
COPY msmtp.conf  /etc/msmtprc
RUN chmod 600 /etc/msmtprc

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Crontab
COPY crontab /etc/cron.d/feer-szo
RUN chmod 0644 /etc/cron.d/feer-szo && crontab /etc/cron.d/feer-szo

WORKDIR /var/www/html

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
