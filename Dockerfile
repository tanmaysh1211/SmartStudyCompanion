FROM php:8.2-apache

# Install Python
RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip \
    python3-venv \
    poppler-utils \
    libzip-dev \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_mysql

# Copy project
COPY . /var/www/html/

WORKDIR /var/www/html

# Install Python dependencies
RUN pip3 install --break-system-packages -r ai/requirements.txt

# Enable Apache rewrite
RUN a2enmod rewrite

# Expose port
EXPOSE 80