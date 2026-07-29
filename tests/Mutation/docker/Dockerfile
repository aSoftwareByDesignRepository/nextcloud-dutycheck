FROM php:8.3-cli
RUN pecl install pcov && docker-php-ext-enable pcov
ADD https://github.com/infection/infection/releases/download/0.29.14/infection.phar /usr/local/bin/infection
RUN chmod a+rx /usr/local/bin/infection
