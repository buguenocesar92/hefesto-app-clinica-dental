# =============================================================================
# Hefesto Studio — ClinicaDental: API
# =============================================================================
# Imagen de produccion: PHP con php-fpm, nginx y el worker de colas, bajo un
# unico supervisor.
#
# POR QUE VARIAS INSTANCIAS EN UNA SOLA IMAGEN
# --------------------------------------------
# En un consultorio el cuello de botella no es PHP: es esperar a que alguien
# responda. Un solo contenedor con php-fpm, nginx, el worker de colas y el
# planificador es mas simple de operar, y en una instalacion de este tamano no
# cuesta nada frente a separar en varios contenedores.
#
# Si el worker de colas no corre, las tareas quedan apiladas. En este producto
# eso significa que nadie se entera de que se agendo una cita ni de que hay una
# alergia grave hasta que se abre el correo del operador.
#
# Versiones fijadas, consultadas el 2026-10-17:
#   - php:8.4-fpm-alpine3.24   (Docker Hub, etiqueta existente)
# =============================================================================

FROM php:8.4-fpm-alpine3.24 AS base

# `pcntl` lo necesita el worker de colas; `pdo_pgsql`, la conexion a
# PostgreSQL; `bcmath` e `intl`, calculos y formato de fechas. `nginx` y
# `supervisor` son los dos procesos que corren adentro.
RUN apk add --no-cache \
        nginx \
        supervisor \
        libpq-dev \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
        bash \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del --no-network .build-deps \
    && rm -rf /tmp/*

WORKDIR /var/www/html

# Se copian primero los manifiestos para que la capa de dependencias se
# reutilice mientras no cambien las versiones.
COPY api/composer.json api/composer.lock* ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY api/ ./

# El `--optimize-autoloader` importa mas de lo habitual en esta aplicacion:
# `app/Http/Requests/RequestsDeClinica.php` declara cuatro clases de validacion
# en un solo archivo, y se resuelven por `classmap` en el `composer.json`.
# Sin el classmap optimizado, esas cuatro no se encuentran.
RUN composer install \
        --no-dev \
        --no-scripts \
        --optimize-autoloader \
        --no-interaction \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY deploy/api/docker/php.ini /usr/local/etc/php/conf.d/zz-clinica-dental.ini
COPY deploy/api/docker/nginx.conf /etc/nginx/http.d/clinica-dental.conf
COPY deploy/api/docker/supervisord.conf /etc/supervisord.conf
COPY deploy/api/docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 8000

# La salud no depende de la sesion ni del consultorio: solo comprueba que PHP
# responde. Un chequeo que necesita sesion daria falso negativo durante un
# despliegue, y un chequeo que toca una tabla protegida fallaria cuando la
# peticion no tiene `app.tenant_id` fijado.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8000/api/v1/salud") === false ? 1 : 0);'

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]