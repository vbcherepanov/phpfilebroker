FROM php:8.4-cli

RUN apt-get update && apt-get install -y git curl unzip && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

COPY composer.json composer.json
COPY src/ src/
COPY config/ config/
COPY tests/ tests/
COPY phpunit.xml.dist phpunit.xml.dist
COPY phpstan.neon phpstan.neon
COPY Makefile Makefile

RUN composer install --optimize-autoloader --no-interaction

CMD ["make", "test"]
