FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip \
    python3-venv \
    poppler-utils \
    libzip-dev \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_mysql

COPY . /var/www/html/

WORKDIR /var/www/html

RUN ls -la
RUN ls -la ai

RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 755 /var/www/html/uploads

RUN pip3 install --break-system-packages -r ai/requirements.txt

RUN a2enmod rewrite

EXPOSE 80

CMD ["apache2-foreground"]