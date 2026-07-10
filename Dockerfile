# =============================================================================
# VOXEL PACS — api/Dockerfile
# Sistema PHP 8.1 + Apache
# Baseado na estrutura MVC do repositório voxelpacs
# =============================================================================
FROM php:8.1-apache

LABEL maintainer="VOXEL PACS <andre@voxelpacs.com.br>"
LABEL description="VOXEL PACS — Sistema de Gestão PACS (PHP 8.1 + Apache)"

# Instalar extensões PHP necessárias
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    unzip \
    curl \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mysqli \
        gd \
        zip \
        mbstring \
        xml \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configurar Apache
RUN a2enmod rewrite headers
COPY .htaccess /etc/apache2/conf-available/voxelpacs.conf
RUN a2enconf voxelpacs

# Configurar DocumentRoot para /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# Configurar PHP
RUN echo "upload_max_filesize = 50M" >> /usr/local/etc/php/conf.d/voxelpacs.ini \
    && echo "post_max_size = 50M" >> /usr/local/etc/php/conf.d/voxelpacs.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/voxelpacs.ini \
    && echo "max_execution_time = 120" >> /usr/local/etc/php/conf.d/voxelpacs.ini \
    && echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/voxelpacs.ini \
    && echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/voxelpacs.ini

# Copiar código da aplicação
WORKDIR /var/www/html
COPY . .

# Instalar dependências PHP
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Criar diretórios de storage e ajustar permissões
RUN mkdir -p storage/uploads storage/logs \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/storage

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -sf http://localhost/health || exit 1

EXPOSE 80
