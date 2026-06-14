# Pinned to 8.4 — the version currently deployed as v1.6.0-prod (PHP API
# 20240924). The lib-stripping regression below is fixed here without ALSO
# jumping a PHP minor mid-incident; bump to 8.5 deliberately once EA is
# verified against it.
FROM php:8.4-fpm

WORKDIR "/var/www/html"

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        zip \
        unzip \
    && curl -sSL https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions -o - | sh -s \
        curl gd intl ldap mbstring mysqli odbc pdo pdo_mysql soap sockets xml zip xdebug exif sqlite3 gettext bcmath csv event inotify redis \
    && docker-php-ext-enable xdebug \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && curl -sLS https://deb.nodesource.com/setup_18.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g npm \
    && apt-get install -q -y ssmtp mailutils \
    && echo "hostname=localhost.localdomain" > /etc/ssmtp/ssmtp.conf \
    && echo "root=root@example.org" >> /etc/ssmtp/ssmtp.conf \
    && echo "mailhub=mailpit:1025" >> /etc/ssmtp/ssmtp.conf \
    && echo "sendmail_path=/usr/sbin/ssmtp -t" >> /usr/local/etc/php/conf.d/php-sendmail.ini \
    && echo "alias ll=\"ls -al\"" >> /root/.bashrc \
    && echo "export XDEBUG_TRIGGER=1" >> /root/.bashrc \
    && echo "export PHP_IDE_CONFIG=\"serverName=host.docker.internal\"" >> /root/.bashrc \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*
# NOTE: `apt-get autoremove` was removed here intentionally. It stripped the
# runtime libs `intl`/`gd` link against (libicu, libpng) — the exact cause of
# the v1.6.0-prod 502 outage (EA hard-requires intl). docker-php-extension-
# installer marks runtime libs as manually-installed, but autoremove on top of
# the node/composer build deps was removing them anyway. The few MB saved is
# not worth a recurring production outage.

# Fail the build if any required PHP extension can't actually LOAD (not just is
# compiled). A stripped runtime lib makes the extension silently absent from
# `php -m`; asserting here turns that into a red build instead of a 502 in prod.
RUN set -eux; \
    for ext in intl gd mysqli pdo_mysql mbstring; do \
        php -m | grep -qix "$ext" \
            || { echo "FATAL: PHP extension '$ext' not loaded — missing runtime lib?"; exit 1; }; \
    done

CMD ["bash", "docker/php-fpm/start-container"]
