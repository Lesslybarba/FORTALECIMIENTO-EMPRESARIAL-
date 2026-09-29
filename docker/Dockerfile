FROM php:8.1-apache
 
# Evitar preguntas interactivas
ENV DEBIAN_FRONTEND=noninteractive
 
# Instalar dependencias en pasos separados para mejor diagnóstico
RUN apt-get update -y
 
RUN apt-get install -y \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    libxml2-dev \
    libicu-dev \
    libonig-dev \
    libxslt-dev \
    unzip \
    curl \
    git \
    && rm -rf /var/lib/apt/lists/*
 
# Configurar y compilar extensiones PHP
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg
 
RUN docker-php-ext-install -j$(nproc) \
    gd \
    zip \
    intl \
    mbstring \
    xml \
    soap \
    opcache \
    pdo \
    pdo_mysql \
    mysqli \
    exif \
    xsl
 
# Habilitar mod_rewrite de Apache
RUN a2enmod rewrite
 
# Descargar e instalar Moodle 4.3
RUN curl -L --retry 3 \
    "https://download.moodle.org/download.php/direct/stable405/moodle-latest-405.tgz" \
    -o /tmp/moodle.tgz \
    && tar -xzf /tmp/moodle.tgz -C /var/www/html --strip-components=1 \
    && rm /tmp/moodle.tgz \
    && chown -R www-data:www-data /var/www/html
 
# Crear directorio de datos Moodle
RUN mkdir -p /var/moodledata \
    && chown -R www-data:www-data /var/moodledata \
    && chmod 777 /var/moodledata
 
# Configuración PHP para Moodle
RUN echo "max_input_vars = 5000" >> /usr/local/etc/php/php.ini \
    && echo "upload_max_filesize = 100M" >> /usr/local/etc/php/php.ini \
    && echo "post_max_size = 100M" >> /usr/local/etc/php/php.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/php.ini
 
EXPOSE 80