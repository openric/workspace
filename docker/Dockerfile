# =============================================================================
# OpenRiC Workspace - one image: nginx, PHP 8.3-FPM, the scheduler and a queue worker, under supervisord
#
# After Heratio's docker/Dockerfile, on Ubuntu 24.04 rather than Debian, so the packages are those INSTALL.md and
# bin/install name (and pg_dump is PostgreSQL 16's, as the database is). Composer runs at build time; the database
# schema, the audit key and the first institution are made at first boot by docker/init.sh, which is idempotent.
# The database is its own container (docker/docker-compose.yml).
#
#   docker compose -f docker/docker-compose.yml --env-file docker/.env.docker up -d --build
#
# Build arguments:
#   WITH_OFFICE=true   LibreOffice, for PDF copies of office documents (about 600 MB more); default false
#
# Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
# SPDX-License-Identifier: AGPL-3.0-or-later
# =============================================================================
FROM ubuntu:24.04

ARG WITH_OFFICE=false
ARG SIEGFRIED_VERSION=1.11.9
ARG SIEGFRIED_SHA256=db22156c4767ee2db3c410b86488966c18e66c39b3bd90aa7a9dab7d769e3005
ENV DEBIAN_FRONTEND=noninteractive TZ=UTC

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip acl gosu supervisor nginx postgresql-client-16 \
        php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-intl php8.3-curl php8.3-zip php8.3-gd php8.3-imagick \
        imagemagick poppler-utils libimage-exiftool-perl ffmpeg ghostscript rsync fonts-dejavu-core 7zip tar \
    && if [ "$WITH_OFFICE" = true ]; then apt-get install -y --no-install-recommends libreoffice-core libreoffice-writer libreoffice-calc libreoffice-impress; fi \
    && curl -fsSL -o /tmp/sf.deb "https://github.com/richardlehane/siegfried/releases/download/v${SIEGFRIED_VERSION}/siegfried_${SIEGFRIED_VERSION}-1_amd64.deb" \
    && echo "${SIEGFRIED_SHA256}  /tmp/sf.deb" | sha256sum -c - \
    && apt-get install -y --no-install-recommends /tmp/sf.deb && rm /tmp/sf.deb \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/openric-workspace

# The lock first, so a change to the code alone does not install the packages again.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && rm -rf storage/app/* storage/logs/* storage/framework/cache/data/* storage/framework/sessions/* storage/framework/views/* \
    && mkdir -p storage/app/private storage/keys storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/php-fpm.conf /etc/php/8.3/fpm/pool.d/zz-openric-workspace.conf
COPY docker/php.ini /etc/php/8.3/fpm/conf.d/99-openric-workspace.ini
COPY docker/php.ini /etc/php/8.3/cli/conf.d/99-openric-workspace.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/openric-workspace.conf
COPY docker/init.sh /usr/local/bin/openric-workspace-init
COPY docker/entrypoint.sh /usr/local/bin/openric-workspace-entrypoint
RUN chmod +x /usr/local/bin/openric-workspace-init /usr/local/bin/openric-workspace-entrypoint \
    && mkdir -p /run/php /var/lib/openric-workspace

LABEL org.opencontainers.image.title="OpenRiC Workspace" \
      org.opencontainers.image.source="https://github.com/openric/workspace" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later"

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=120s --retries=5 CMD curl -fsS http://127.0.0.1/up || exit 1
ENTRYPOINT ["/usr/local/bin/openric-workspace-entrypoint"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
