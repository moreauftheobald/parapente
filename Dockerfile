FROM php:8.4-fpm-bookworm

ENV PHP_INI_MEMORY_LIMIT=512M

# ──────────────────────────────────────────
# Dépendances système
# ──────────────────────────────────────────
RUN apt-get update -y \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
        zlib1g-dev \
        libicu-dev \
        libonig-dev \
        g++ \
        default-mysql-client \
        unzip \
        curl \
        git \
        apt-utils \
        msmtp \
        msmtp-mta \
        mailutils \
        supervisor \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# ──────────────────────────────────────────
# Node.js 22 LTS (pour Vite / npm)
# ──────────────────────────────────────────
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# ──────────────────────────────────────────
# Extensions PHP
# ──────────────────────────────────────────
RUN docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mysqli \
        mbstring \
        xml \
        bcmath \
        zip \
        intl \
        pcntl \
        opcache \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# Redis & Xdebug via PECL
RUN pecl install redis \
    && docker-php-ext-enable redis \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug

# ──────────────────────────────────────────
# Composer
# ──────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ──────────────────────────────────────────
# php.ini — configuration générale
# ──────────────────────────────────────────
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && sed -E -i -e 's/max_execution_time = 30/max_execution_time = 300/'    ${PHP_INI_DIR}/php.ini \
    && sed -E -i -e 's/memory_limit = 128M/memory_limit = 512M/'             ${PHP_INI_DIR}/php.ini \
    && sed -E -i -e 's/post_max_size = 8M/post_max_size = 64M/'              ${PHP_INI_DIR}/php.ini \
    && sed -E -i -e 's/upload_max_filesize = 2M/upload_max_filesize = 64M/'  ${PHP_INI_DIR}/php.ini \
    && echo "date.timezone = Europe/Paris"                                  >> ${PHP_INI_DIR}/php.ini

# ──────────────────────────────────────────
# Xdebug — configuration
# ──────────────────────────────────────────
RUN echo 'xdebug.mode=develop,debug'                       >> ${PHP_INI_DIR}/php.ini \
    && echo 'xdebug.start_with_request=yes'                >> ${PHP_INI_DIR}/php.ini \
    && echo 'xdebug.log="/tmp/xdebug.log"'                 >> ${PHP_INI_DIR}/php.ini \
    && echo 'xdebug.show_local_vars=1'                     >> ${PHP_INI_DIR}/php.ini \
    && echo 'xdebug.var_display_max_depth=10'              >> ${PHP_INI_DIR}/php.ini \
    && echo 'xdebug.client_host=host.docker.internal'      >> ${PHP_INI_DIR}/php.ini \
    && echo 'xdebug.client_port=9003'                      >> ${PHP_INI_DIR}/php.ini

# ──────────────────────────────────────────
# OPcache — configuration (prod-ready)
# ──────────────────────────────────────────
RUN echo 'opcache.enable=1'                                >> ${PHP_INI_DIR}/php.ini \
    && echo 'opcache.memory_consumption=256'               >> ${PHP_INI_DIR}/php.ini \
    && echo 'opcache.interned_strings_buffer=16'           >> ${PHP_INI_DIR}/php.ini \
    && echo 'opcache.max_accelerated_files=20000'          >> ${PHP_INI_DIR}/php.ini \
    && echo 'opcache.validate_timestamps=1'                >> ${PHP_INI_DIR}/php.ini

# ──────────────────────────────────────────
# msmtp → MailDev
# ──────────────────────────────────────────
RUN echo "account default"                         >  /etc/msmtprc \
    && echo "auth off"                             >> /etc/msmtprc \
    && echo "port 1025"                            >> /etc/msmtprc \
    && echo "host parapente-mail"                  >> /etc/msmtprc \
    && echo "from local@localdomain.com"           >> /etc/msmtprc \
    && echo "domain localhost.localdomain"         >> /etc/msmtprc \
    && echo "sendmail_path=/usr/bin/msmtp -t"      >> /usr/local/etc/php/conf.d/php-sendmail.ini

# ──────────────────────────────────────────
# Permissions & workdir
# ──────────────────────────────────────────
ARG HOST_UID=1000
ARG HOST_GID=1000
RUN groupmod -g ${HOST_GID} www-data \
    && usermod -u ${HOST_UID} www-data

WORKDIR /var/www/html

RUN chown -R www-data:www-data /var/www
