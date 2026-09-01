# Imagem da aplicação — PHP 8.3 FPM com as extensões do Laravel + projeto.
FROM php:8.3-fpm

# Dependências de sistema para compilar as extensões PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
    # O gd instala-se LOGO a seguir ao seu configure: cada docker-php-ext-install apaga
    # /usr/src/php no fim, e o gd instalado mais tarde era reconfigurado sem as flags
    # (GD sem JPEG nem FreeType — os uploads .jpg e os testes com imagens rebentavam).
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd \
    && docker-php-ext-install pdo_pgsql \
    && docker-php-ext-install bcmath \
    && docker-php-ext-install zip \
    && docker-php-ext-install intl \
    && docker-php-ext-install pcntl \
    && docker-php-ext-install opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Extensão Redis — compilada a partir do GitHub (pecl.php.net é instável)
RUN git clone --depth=1 https://github.com/phpredis/phpredis.git /usr/src/phpredis \
    && cd /usr/src/phpredis \
    && phpize \
    && ./configure \
    && make \
    && make install \
    && docker-php-ext-enable redis \
    && rm -rf /usr/src/phpredis

# Composer (copiado da imagem oficial)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configuração de PHP do projeto
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-nexus.ini

# Entrypoint que garante permissões de escrita antes de arrancar
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

# Utilizador não-root com o mesmo uid típico do host (evita problemas de permissões)
RUN usermod -u 1000 www-data || true

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
