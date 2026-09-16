FROM php:8.4-apache

# Systemowe zależności
RUN apt-get update && apt-get install -y --no-install-recommends \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    libxml2-dev \
    libicu-dev \
    libonig-dev \
    libsqlite3-dev \
    libssl-dev \
    default-mysql-client \
    unzip \
    git \
    cron \
    msmtp \
    qpdf \
    sudo \
    python3 \
    nodejs \
    gcc \
    g++ \
    libc6-dev \
    default-jdk-headless \
    && rm -rf /var/lib/apt/lists/*

# ── Piaskownica Equi Exams: użytkownik bez logowania do uruchamiania kodu
#    kursanta, oddzielony od www-data (patrz modules/equi_exams/logic/sandbox.php).
#    `www-data` dostaje WYŁĄCZNIE bezhasłowe, bez-argumentowe uprawnienie do
#    jednego stałego polecenia — bez wildcardów, żeby nic więcej nie dało się
#    tą regułą uruchomić jako examrun.
RUN useradd --system --no-create-home --shell /usr/sbin/nologin examrun \
    && echo 'www-data ALL=(examrun) NOPASSWD: /usr/local/bin/php /var/www/html/cli/exam_sandbox_worker.php' \
        > /etc/sudoers.d/exam-sandbox \
    && echo 'Defaults:www-data !requiretty' >> /etc/sudoers.d/exam-sandbox \
    && chmod 0440 /etc/sudoers.d/exam-sandbox \
    && visudo -cf /etc/sudoers.d/exam-sandbox

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
        soap \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis

# Apache: mod_rewrite + headers + remoteip (dla Traefik X-Forwarded-For)
RUN a2enmod rewrite headers remoteip

COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY php.ini     /usr/local/etc/php/conf.d/app.ini

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
