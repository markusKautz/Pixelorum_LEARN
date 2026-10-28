# syntax=docker/dockerfile:1.7
#
# Produktivimage fuer LEARN. Vier Stufen, damit weder Composer noch Node im
# Ergebnis landen.

# ---------------------------------------------------------------------------
# Stufe 0: gemeinsame PHP-Basis
#
# Die Erweiterungen werden hier ein einziges Mal installiert. Wuerde jede
# PHP-Stufe sie selbst installieren, liefe derselbe Vorgang zweimal, und die
# Laufzeit koennte stillschweigend eine andere Erweiterungsmenge bekommen als
# die, gegen die Composer aufgeloest hat.
#
# opcache ist im Basisimage bereits enthalten und wird nicht erneut installiert.
# intl bewusst nicht: Es wird aus dem Quelltext uebersetzt und kostet Minuten,
# im Projekt verwendet es niemand, und kein Produktivpaket verlangt es (nur
# `suggest`). Die Symfony-Polyfills decken die betroffenen Funktionen ab.
# ---------------------------------------------------------------------------
FROM docker.io/dunglas/frankenphp:1.12-php8.5-trixie AS base

# zip braucht neben Composer auch die Laufzeit: Export und Upload von
# Lernpfaden mit Bildern als ZIP-Buendel (DEV-14).
RUN install-php-extensions pdo_pgsql zip

WORKDIR /app

# ---------------------------------------------------------------------------
# Stufe 1: PHP-Abhaengigkeiten
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=docker.io/composer/composer:2-bin /composer /usr/bin/composer

# Eigene Schicht: solange sich die Abhaengigkeiten nicht aendern, bleibt der
# teure composer install im Cache, auch wenn sich Anwendungscode aendert.
COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .

# package:discover bootet die Anwendung und braucht dafuer einen beschreibbaren
# View-Cache. storage/framework steht in .containerignore, existiert hier also
# nicht. Ohne diese Zeile bricht der naechste Schritt mit "Please provide a
# valid cache path" ab.
RUN mkdir -p storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache

# Ohne --no-scripts, damit post-autoload-dump das package:discover-Manifest
# schreibt und es zur Laufzeit nicht erst erzeugt werden muss.
RUN composer dump-autoload --optimize --no-dev --no-interaction

# ---------------------------------------------------------------------------
# Stufe 2: Frontend-Bundle
# ---------------------------------------------------------------------------
FROM docker.io/library/node:22-trixie-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

# vendor/ muss vor dem Build da sein: resources/css/app.css zieht ueber @source
# die Blade-Dateien von Flux herein. Fehlen sie, generiert Tailwind
# die Utility-Klassen der Flux-Komponenten nicht, und die Oberflaeche kommt
# unformatiert heraus, ohne dass der Build einen Fehler meldet.
COPY --from=vendor /app/vendor ./vendor
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public

# Laedt die Instrument-Sans-Dateien von bunny.net (siehe vite.config.js).
# Die Build-Maschine braucht dafuer Internet, der Server nicht.
RUN npm run build

# ---------------------------------------------------------------------------
# Stufe 3: Laufzeit
# ---------------------------------------------------------------------------
FROM base AS runtime

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# php.ini-production laesst nur 2 MB je Upload zu. Bilder im Blockformular
# duerfen 4 MB haben, ZIP-Buendel 12 MB (Livewire-Standardlimit, DEV-14).
RUN printf 'upload_max_filesize = 12M\npost_max_size = 16M\n' > "$PHP_INI_DIR/conf.d/zz-uploads.ini"

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY deploy/Caddyfile /etc/caddy/Caddyfile
COPY deploy/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint

# Kein TLS, siehe deploy/Caddyfile. Der Doppelpunkt ohne Host bindet an jede
# Adresse und haelt Caddys automatisches HTTPS aus dem Spiel.
ENV SERVER_NAME=":80"

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
