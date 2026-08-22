# SquirrelMail 1.4.22 — awaryjny, archaiczny webmail (ostatnie wydanie: 2011).
#
# Świadomie PHP 7.4, nie 8.x: kod SquirrelMaila pochodzi z ery PHP 4/5 i na
# PHP 8 wywala się na usuniętych funkcjach (m.in. rodzina ereg_*, przekazywanie
# przez referencję w wywołaniach). 7.4 to najnowsza wersja, na której 1.4.22
# działa bez łatania — a skoro sam kod nie jest już rozwijany, nie ma sensu go
# portować. Wniosek praktyczny: ten kontener trzymamy w sieci wewnętrznej, nie
# wystawiamy go publicznie (zob. README.md i ipallowlist w compose).
FROM php:7.4-apache

# gettext — SquirrelMail używa tłumaczeń; mbstring — poprawne kodowania nagłówków.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends gettext libgettextpo-dev libonig-dev curl ca-certificates; \
    docker-php-ext-install -j"$(nproc)" gettext mbstring; \
    apt-get purge -y --auto-remove libgettextpo-dev libonig-dev; \
    rm -rf /var/lib/apt/lists/*

ARG SQM_VERSION=1.4.22
ARG SQM_URL=https://downloads.sourceforge.net/project/squirrelmail/stable/${SQM_VERSION}/squirrelmail-webmail-${SQM_VERSION}.tar.gz

# Kod idzie do /var/www/html, dane (preferencje, książka adresowa, załączniki)
# POZA webroot — w 1.4.x to jedyna ochrona tych plików przed odczytem z sieci.
RUN set -eux; \
    curl -fsSL "${SQM_URL}" -o /tmp/sqm.tgz; \
    tar xzf /tmp/sqm.tgz -C /tmp; \
    rm -rf /var/www/html; \
    mv "/tmp/squirrelmail-webmail-${SQM_VERSION}" /var/www/html; \
    rm -f /tmp/sqm.tgz; \
    mkdir -p /var/local/squirrelmail/data /var/local/squirrelmail/attach; \
    chown -R www-data:www-data /var/local/squirrelmail /var/www/html/config; \
    chmod 730 /var/local/squirrelmail/attach

# Konfiguracja: config_local.php jest wczytywany po config.php i nadpisuje jego
# wartości — dzięki temu nie generujemy config.php skryptem conf.pl w obrazie.
COPY config_local.php /var/www/html/config/config_local.php
RUN set -eux; \
    cp /var/www/html/config/config_default.php /var/www/html/config/config.php; \
    chown www-data:www-data /var/www/html/config/config.php /var/www/html/config/config_local.php

# Kolejka błędów do stdout kontenera (SquirrelMail sam nic nie loguje sensownie).
RUN { \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'error_log=/dev/stderr'; \
      echo 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING'; \
    } > /usr/local/etc/php/conf.d/sqm.ini

EXPOSE 80
