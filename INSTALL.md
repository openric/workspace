# Installing the OpenRiC Workspace

This guide takes a fresh Ubuntu 24.04 server to a running Workspace. It is written from the live instance at https://workspace.openric.org, which runs exactly this way. Once it is installed, `deploy/README.md` covers releasing and deploying new versions, and `deploy/runbooks/` covers updating and rotating keys.

The examples use `archives.example.org` for the host, `/usr/share/nginx/openric-workspace-live` for the code and `openric_workspace` for the database and its role. Change them to yours.

The Workspace is in development and not yet released for use. Install it to try it, not yet to hold an archive's only copy of anything.

## What it needs

- Ubuntu 24.04 (or another Linux with the same packages), with a user that owns the code and `sudo`.
- PHP 8.3 with php-fpm and the extensions `pgsql`, `pdo_pgsql`, `mbstring`, `xml`, `intl`, `curl`, `zip`, `gd`, `imagick` and `sodium` (sodium signs the audit log).
- PostgreSQL 16.
- nginx and a TLS certificate (certbot).
- Composer 2.
- The tools files are handled with:

| Tool | Package | What it does | Without it |
|---|---|---|---|
| ImageMagick (`convert`) | `imagemagick` | Viewing copies and thumbnails of images; TIFF and JPEG normalisation | No viewing copies of images |
| Poppler (`pdftoppm`, `pdftotext`) | `poppler-utils` | The first page of a PDF as a copy; a PDF's text | No PDF copies or text |
| ExifTool | `libimage-exiftool-perl` | Embedded metadata of a file | Embedded metadata not read |
| FFmpeg (`ffmpeg`, `ffprobe`) | `ffmpeg` | Copies of sound and video, their length; WAV, FFV1 and MP4 normalisation | No copies of sound or video |
| Ghostscript (`gs`) | `ghostscript` | PDF/A-2b preservation masters | That rule is skipped |
| LibreOffice (`soffice`) | `libreoffice-core` and its writer, calc and impress parts | PDF copies of office documents | Those rules are skipped |
| Siegfried (`sf`) | `siegfried` (from its own repository) | File formats against PRONOM | Files left unidentified |
| ClamAV daemon (`clamdscan`) | `clamav-daemon` | A virus scan of every upload | Set `WORKSPACE_VIRUS_SCANNER=` and files are kept as not scanned |
| rsync | `rsync` | Replication of the object store | No replication targets |
| DejaVu fonts | `fonts-dejavu-core` | The text of watermarks | Watermark text not drawn |
| tar, 7-Zip | `tar`, `7zip` | Information packages and DIPs from Archivematica | 7z DIPs refused |
| Apache Jena Fuseki | from jena.apache.org | The read-only SPARQL endpoint for Sparnatural (phase 16e) | No graph explorer |

## 1. System packages

    sudo apt update
    sudo apt install -y nginx postgresql php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml \
        php8.3-intl php8.3-curl php8.3-zip php8.3-gd php8.3-imagick composer git unzip \
        imagemagick poppler-utils libimage-exiftool-perl ffmpeg ghostscript rsync fonts-dejavu-core 7zip \
        libreoffice-core libreoffice-writer libreoffice-calc libreoffice-impress clamav-daemon certbot python3-certbot-nginx

Siegfried comes from its own repository (https://github.com/richardlehane/siegfried, "Install"); after installing it, run `sf -update` once to fetch PRONOM's signatures. sodium is built into PHP 8.3 on Ubuntu.

ImageMagick's own policy refuses PDF input; the Workspace does not need it, as `pdftoppm` reads PDFs.

## 2. The database

    sudo -u postgres createuser --pwprompt openric_workspace
    sudo -u postgres createdb --owner=openric_workspace --encoding=UTF8 openric_workspace

Keep the password for `.env`. The Workspace uses PostgreSQL's own features (triggers, `jsonb`, `ON CONFLICT`), so no other database will do.

## 3. The code

    sudo mkdir -p /usr/share/nginx/openric-workspace-live
    sudo chown "$USER": /usr/share/nginx/openric-workspace-live
    git clone https://github.com/openric/workspace.git /usr/share/nginx/openric-workspace-live
    cd /usr/share/nginx/openric-workspace-live
    git checkout vX.Y.Z

Use the newest tag (`git tag --sort=-v:refname | head -1`); the live instance only ever holds a tagged release. Then:

    composer install --no-dev --optimize-autoloader --no-interaction
    cp .env.example .env
    php artisan key:generate

## 4. Settings in `.env`

Set at least:

    APP_NAME="OpenRiC Workspace"
    APP_ENV=production
    APP_DEBUG=false
    APP_URL=https://archives.example.org
    LOG_LEVEL=info

    DB_CONNECTION=pgsql
    DB_HOST=127.0.0.1
    DB_PORT=5432
    DB_DATABASE=openric_workspace
    DB_USERNAME=openric_workspace
    DB_PASSWORD=<the password from step 2>

    SESSION_SECURE_COOKIE=true
    QUEUE_CONNECTION=database

    MAIL_MAILER=smtp
    MAIL_HOST=<your mail server>
    MAIL_PORT=587
    MAIL_USERNAME=<user>
    MAIL_PASSWORD=<password>
    MAIL_FROM_ADDRESS=archives@example.org
    MAIL_FROM_NAME="Riverside Archives"

    WORKSPACE_TIMEZONE=Africa/Johannesburg
    WORKSPACE_OPERATORS=you@example.org

And, as they apply:

| Setting | What it is | Default |
|---|---|---|
| `WORKSPACE_OBJECT_STORE` | The folder of the OCFL storage root that holds every file; outside `public/` | `storage/app/objects` |
| `WORKSPACE_MAX_UPLOAD_MB` | The largest file an upload may be | 60 |
| `WORKSPACE_VIRUS_SCANNER` | The ClamAV client, or empty for none | `clamdscan` |
| `WORKSPACE_MODULES` | The experimental modules this installation offers, comma-separated, or `all`; `MODULES.md` lists them and why each is experimental | empty: the supported modules only |
| `WORKSPACE_SIEGFRIED` | Siegfried's command, or empty for none | `sf` |
| `WORKSPACE_OPERATORS` | Comma-separated e-mail addresses of the installation's operators, who see its health and set file formats, normalisation rules, replication and Archivematica | With one institution, its administrators |
| `WORKSPACE_METRICS_TOKEN`, `WORKSPACE_METRICS_IPS` | The bearer token and addresses for `/metrics` (Prometheus) | Not served |
| `WORKSPACE_API_KEY_HOURLY_LIMIT` | Requests an hour for one API key | 1000 |
| `WORKSPACE_EMAIL_BOUNCE_SECRET`, `WORKSPACE_EMAIL_BOUNCE_PROVIDER` | The mail provider's bounce webhook | Every call refused |
| `WORKSPACE_OAI_ADMIN_EMAIL` | The administrator OAI-PMH names, where the institution names none | `MAIL_FROM_ADDRESS` |
| `WORKSPACE_IMPORT_FILES` | A copy of AtoM's `uploads` folder for importing its files | None |
| `WORKSPACE_INTAKE_ROOT`, `WORKSPACE_INTAKE_SETTLE_SECONDS` | Watched folders for intake | None |
| `WORKSPACE_WEBHOOKS_CHECK_HOSTS` | Refuse webhooks to private addresses | On |
| `WORKSPACE_USAGE_RETENTION_DAYS` | How long usage counts are kept, in days | 90 |
| `WORKSPACE_PREFLIGHT_WAIVE` | Preflight checks to waive, comma-separated (for example `mail` on a trial) | None |

Then make `.env` readable by the web server and no one else:

    sudo chgrp www-data .env && chmod 640 .env

## 5. Folders the web server writes

    sudo chown -R www-data:www-data storage bootstrap/cache
    sudo setfacl -R -m u:www-data:rwx -m u:"$USER":rwx storage bootstrap/cache
    sudo setfacl -R -d -m u:www-data:rwx -m u:"$USER":rwx storage bootstrap/cache

If the object store is elsewhere, make it, give it to `www-data` the same way, and keep it off the public web root.

Ubuntu's php8.3-fpm unit runs with `ProtectSystem=full`, which makes `/usr` read-only to PHP, so under `/usr/share/nginx` the web server cannot write its own `storage/` until it is allowed to. Put this in `/etc/systemd/system/php8.3-fpm.service.d/openric-workspace-storage.conf` (with the object store's folder too, if it is elsewhere under `/usr`):

    [Service]
    ReadWritePaths=/usr/share/nginx/openric-workspace-live/storage
    ReadWritePaths=/usr/share/nginx/openric-workspace-live/bootstrap/cache

and reload:

    sudo systemctl daemon-reload && sudo systemctl restart php8.3-fpm

Without it, every page that writes a log or a cache fails with "Read-only file system".

PHP must take uploads as large as `WORKSPACE_MAX_UPLOAD_MB`. In `/etc/php/8.3/fpm/conf.d/99-openric-workspace.ini`:

    upload_max_filesize = 64M
    post_max_size = 64M
    memory_limit = 512M

## 6. The database, the audit key and the first institution

Run artisan as `www-data`, never as root: a file artisan writes as root under `storage/` stops the web server writing there.

    sudo -u www-data php artisan migrate --force
    sudo -u www-data php artisan workspace:audit:create-key --if-missing
    sudo -u www-data php artisan workspace:install \
        --institution="Riverside Archives" \
        --base-iri=https://archives.example.org \
        --admin-name="Your Name" \
        --admin-email=you@example.org \
        --language=en

`workspace:install` loads the vocabularies (from openric.org; `--skip-openric-vocabularies` offline), makes the institution with its role grants and first repository, and the first administrator. It asks for the administrator's password (at least 12 characters); left empty, it generates one and shows it once, so note it. The base IRI is the start of every persistent identifier the institution mints: choose it once, on a domain you will keep.

The audit signing key is `storage/keys/audit-signing.sk`. Every change the Workspace makes is signed with it, and without it every change is refused. Back it up now, apart from the database: it is never replaced, and the database dumps do not contain it.

Then cache what production caches, and check the configuration:

    sudo -u www-data php artisan config:cache
    sudo -u www-data php artisan route:cache
    sudo -u www-data php artisan view:cache
    sudo -u www-data php artisan workspace:preflight

`workspace:preflight` refuses a configuration that is not ready for production: debug on, no application key, http rather than https, session cookies that are not secure-only, mail logged rather than sent, a debug log level, a world-readable `.env` or audit key, or an object store inside `public/`.

## 7. nginx and TLS

`/etc/nginx/sites-available/archives.example.org.conf`, as the live instance's:

    server {
        server_name archives.example.org;
        root /usr/share/nginx/openric-workspace-live/public;
        index index.php;
        charset utf-8;
        client_max_body_size 64m;

        add_header X-Frame-Options        "SAMEORIGIN"                      always;
        add_header X-Content-Type-Options "nosniff"                         always;
        add_header Referrer-Policy        "strict-origin-when-cross-origin" always;
        add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;

        location / { try_files $uri $uri/ /index.php?$query_string; }
        location = /favicon.ico { access_log off; log_not_found off; }
        location = /robots.txt  { access_log off; log_not_found off; }
        location ~ /\.(?!well-known).* { deny all; }

        location ~ ^/index\.php(/|$) {
            fastcgi_pass unix:/run/php/php8.3-fpm.sock;
            fastcgi_split_path_info ^(.+\.php)(/.*)$;
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
            fastcgi_param DOCUMENT_ROOT $realpath_root;
            fastcgi_hide_header X-Powered-By;
            fastcgi_read_timeout 60;
        }
        location ~ \.php$ { return 404; }

        listen 80;
        listen [::]:80;
    }

Enable it and let certbot add TLS and the redirect from http:

    sudo ln -s /etc/nginx/sites-available/archives.example.org.conf /etc/nginx/sites-enabled/
    sudo nginx -t && sudo systemctl reload nginx
    sudo certbot --nginx -d archives.example.org

`client_max_body_size` must be at least `WORKSPACE_MAX_UPLOAD_MB`.

## 8. The scheduler and background jobs

Viewing copies, imports, format identification, normalisation, fixity schedules, replication, migration plans, Archivematica and mail all run in the background, started by Laravel's scheduler each minute. Without it uploads keep their files but nothing else happens. Install the cron entry once:

    sudo cp deploy/cron/openric-workspace /etc/cron.d/openric-workspace
    sudo chown root:root /etc/cron.d/openric-workspace && sudo chmod 0644 /etc/cron.d/openric-workspace

Change the path in it if the code is not at `/usr/share/nginx/openric-workspace-live`. It runs as `www-data`. Admin, Operations shows the scheduler's heartbeat; it should be under a minute or two old.

### The files worker, without outside network (recommended)

File converters (LibreOffice, FFmpeg, ImageMagick, Ghostscript, ExifTool, Siegfried) read files people upload. Each already runs under CPU, memory and file-size limits, in a session of its own. On a host where AppArmor forbids unprivileged namespaces (Ubuntu 24.04 does), a single command cannot also be cut off from the network, so the jobs that run converters go to a queue of their own, `files`, which a systemd service runs with no address but the machine's own:

    sudo cp deploy/systemd/openric-workspace-files.service /etc/systemd/system/
    sudo systemctl daemon-reload && sudo systemctl enable --now openric-workspace-files

Then set `WORKSPACE_FILES_WORKER=true` in `.env` and run `php artisan optimize`, so the scheduler's worker leaves the `files` queue to it. Until then the scheduler's worker runs both queues, with network. Change the paths in the unit if the code is not at `/usr/share/nginx/openric-workspace-live`, or if the object store (`WORKSPACE_OBJECT_STORE`) is outside `storage/`: add it to `ReadWritePaths`. A deploy restarts the worker.

## 9. ClamAV and Siegfried

    sudo systemctl enable --now clamav-daemon
    sudo -u www-data clamdscan --version
    sf -update && sf -version

The ClamAV daemon takes a few minutes to load its signatures on first start. `www-data` must be able to run `clamdscan` and `sf`.

## 10. Check it

- `https://archives.example.org/sign-in` answers, and the administrator signs in with the password from step 6.
- Sign in, make a unit of description and upload an image: its reference copy and thumbnail appear, and its instantiation's Preservation section shows its ingestion, digest, virus check and format.
- Admin, Operations shows no problem, and the scheduler's heartbeat is fresh.
- `sudo -u www-data php artisan workspace:audit:verify` reports the audit chain intact.
- `sudo -u www-data php artisan workspace:ocfl:verify` reports every object without problems.

## Backups

Back up, together, and test a restore:

- the database (`pg_dump -Fc`; `deploy/deploy.sh` keeps the last ten under `storage/app/backups/` at each release);
- the object store, which holds every file in OCFL objects any OCFL tool can read;
- the audit signing key (`storage/keys/`);
- the institution's logo (`storage/app/private/branding/`);
- `.env`.

A restore is complete only when `workspace:fixity` reports no failure and `workspace:audit:verify` the chain intact. Admin, Preservation can also keep a copy of the object store on another disk or server by rsync, on a schedule.

## Optional parts

- Experimental modules (research services, repatriation, the shop, federation, DOIs and the others in `MODULES.md`) are off until named in `WORKSPACE_MODULES`. Name only what the institution has decided to take on.
- Single sign-on and two-factor sign-in: Admin, Sign-in settings (`deploy/README.md`, "Single sign-on and two-factor sign-in").
- Archivematica: Admin, Archivematica, set by an operator. Its transfer source must be a folder both Archivematica and this server can reach. Its DIP upload to the Workspace uses SWORD at `https://archives.example.org/sword/servicedocument`, signed in with an account's e-mail address and an API key with the write scope.
- Replication: Admin, Preservation, Replication. A target on another server needs an SSH key `www-data` can read, and the server's host key accepted once.
- Metrics: set `WORKSPACE_METRICS_TOKEN` and point Prometheus at `/metrics`.
- Federation: Admin, Federation. An institution chooses whether it answers peers, and which peers it searches. Peers verify its answers with the audit signing key's public half, published at `/.well-known/openric-federation-key`, so a restored server must keep the same key.
- The SPARQL endpoint and Sparnatural (phase 16e): an Apache Jena Fuseki server holds the Workspace's published RiC-O, and the Workspace forwards public queries to it, so Fuseki listens on 127.0.0.1 only and nginx needs nothing new. Create a TDB2 dataset with Fuseki's admin user, for example `curl --user admin -X POST http://127.0.0.1:3030/$/datasets -d dbName=openric-workspace -d dbType=tdb2`, which asks for the password rather than taking it on the command line; set `WORKSPACE_SPARQL_URL=http://127.0.0.1:3030`, `WORKSPACE_SPARQL_DATASET=openric-workspace`, and `WORKSPACE_SPARQL_USER` and `WORKSPACE_SPARQL_PASSWORD` for a Fuseki user that may update it; then fill it once with `php artisan workspace:sparql:sync --full`. The scheduler keeps it current every ten minutes and in full each night. The endpoint is `/sparql`, the explorer `/discover/explore`; while `WORKSPACE_SPARQL_URL` is empty both say the graph is not open.
- The public site (phase 20a): static pages, articles and landing pages are written under Public site in the menu, by anyone who may publish descriptions. Their files are kept in the object store like every other. A landing page's map draws OpenStreetMap's tiles, which the visitor's browser fetches from tile.openstreetmap.org.
- The Help Center (phase 20b) is at `/help`, with its guides in `docs/help`; it reads them in by itself when they change. `WORKSPACE_HELP_DIRECTORY` points it at another folder of guides.
- DOIs (phase 20c): give the institution's DataCite repository ID, password and prefix under Research, ORCID and DataCite, on the test service first, then set how DOIs are minted under DOIs, Settings. The scheduler sends the queue every five minutes. Nothing is sent to DataCite until the account is given.
- Lists and display (phase 20d): under Admin, the Dropdown manager words and orders the bundled lists for the institution, and Display sets the public page's profiles by level and how staff browse. Both work with nothing set. Which lists take an institution's own terms is `open_vocabularies` in `config/workspace.php`.
- AI (phase 17a): set `AI_GATEWAY_KEY` in `.env` to a key with the gateway scope for `ai.theahg.co.za`, then switch AI on under Admin, AI. Nothing is sent to a model before both. Reading pages needs `tesseract` with the languages of your descriptions (`tesseract-ocr-eng`, `tesseract-ocr-afr`), and a queue worker. `php artisan workspace:ai:verify` checks the log of inferences.
- Resolving names (phase 17b2): under Admin, Resolving names, switch on the outside authorities to fill new records from (VIAF, Wikidata, GeoNames with your own registered GeoNames username); all are off by default, and the names searched for are sent to them. The scheduler scans parked names nightly; `php artisan workspace:authority:promote` brings names proposed through the API into the queue.
- Search by meaning (phase 17d1) needs pgvector (0.6 or later) in the database: as a PostgreSQL superuser, run `CREATE EXTENSION IF NOT EXISTS vector;` in the Workspace's database before migrating (`sudo -u postgres psql -d <database> -c 'CREATE EXTENSION IF NOT EXISTS vector;'`; on Debian and Ubuntu the package is `postgresql-16-pgvector`). Switch it on under Admin, AI, Search; the scheduler embeds the index every ten minutes.
- WhatsApp notices to staff: the Workspace writes each one into the estate's spool (`/var/spool/ahg-whatsapp`) as the Meta-approved template `ahg_system_notice`, and `ahg-whatsapp-watcher` sends it, so the Workspace holds no WhatsApp credential. Put the web server's user in the spool's group (`usermod -aG ahg-whatsapp www-data`, then restart PHP-FPM), and set `WHATSAPP_ENABLED=true` once the template is approved. Each staff member then gives their number on their profile.
- ResourceSync is always on, at `/.well-known/resourcesync`. `RESOURCESYNC_CHANGELIST_DAYS` (30) and `RESOURCESYNC_PAGE_SIZE` (1000) change its horizon and page size.

## Updating

`deploy/runbooks/updating.md`. In short, `deploy/deploy.sh vX.Y.Z` in the code's folder: it refuses a release whose tests did not pass, backs up the database, runs the migrations and caches, and checks the site answers.

## Help

Heratio's Help Center (https://heratio.org/help/) documents the functions the Workspace copies, with user guides and the system map; the Workspace's own help follows it in roadmap phase 20. The design decisions are under `architecture/adr/`, and what the Workspace does, area by area, is at https://openric.org/application/features/.
