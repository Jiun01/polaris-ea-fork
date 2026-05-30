# Docker Build Codemap

**Last Updated:** 2026-05-20
**Entry Points:** ./Dockerfile, docker/build.sh

## Architecture

### Multi-Stage Build

```
┌──────────────────────────────────────────────────────────┐
│  Stage 1: Node Assets (node:18-alpine)                  │
├──────────────────────────────────────────────────────────┤
│  COPY package*.json                                     │
│  RUN npm ci --prefer-offline                            │
│  COPY gulpfile.js + assets/                             │
│  RUN npx gulp compile                                   │
│     ├─ Compile SCSS → CSS                               │
│     ├─ Minify JS                                        │
│     └─ Copy to build/production/                         │
│  ↓                                                       │
│  Output: compiled assets (no source code)                │
│                                                          │
├──────────────────────────────────────────────────────────┤
│  Stage 2: PHP Vendor (php:8.4-cli)                       │
├──────────────────────────────────────────────────────────┤
│  COPY --from=composer:2.7 /usr/bin/composer              │
│  RUN apt-get install unzip git                           │
│  COPY composer.json composer.lock ./                     │
│  RUN composer install --no-dev --no-scripts \            │
│      --optimize-autoloader --ignore-platform-reqs        │
│  ↓                                                       │
│  Output: vendor/ directory (production only, no dev)     │
│                                                          │
├──────────────────────────────────────────────────────────┤
│  Stage 3: Production Image (php:8.4-fpm)                 │
├──────────────────────────────────────────────────────────┤
│  FROM php:8.4-fpm                                        │
│  RUN apt-get install extensions                          │
│  COPY --from=assets /build/build/production/ ./          │
│  COPY --from=vendor /build/vendor/ ./vendor/             │
│  COPY application/ system/ index.php ./                  │
│  RUN chown -R www-data:www-data /var/www/html           │
│  ↓                                                       │
│  Final image: ghcr.io/jiun01/polaris-ea:v1.6.0-r1      │
│  Size: ~600 MB (lean, no build tools)                    │
│  Extensions: gd, pdo_mysql, curl, xml, mbstring,         │
│             intl, exif, zip (runtime-only)               │
└──────────────────────────────────────────────────────────┘
```

## Build Script (`docker/build.sh`)

### Usage

```bash
# Build locally (no push)
./docker/build.sh

# Build and push to GHCR
./docker/build.sh --push

# Custom tag
./docker/build.sh --tag v1.6.0-r1

# Push custom tag
./docker/build.sh --tag v1.6.0-r1 --push
```

### Environment Variables

Sourced from `.env` file in repo root:

```bash
# .env (git-ignored)
GITHUB_TOKEN=ghp_...       # GitHub token for GHCR push
```

### Image Naming

```
ghcr.io/jiun01/polaris-ea:<VERSION>
```

Version defaults to:
1. Git tag matching `polaris/v*` (e.g., `git tag polaris/v1.6.0-r1`)
2. Git describe output (strips `polaris/` prefix)
3. Fallback: `v1.6.0-r1`

### Build Args

```bash
docker buildx build \
  --platform linux/amd64 \
  --target production \
  --tag ghcr.io/jiun01/polaris-ea:v1.6.0-r1 \
  --tag ghcr.io/jiun01/polaris-ea:latest \
  --file Dockerfile \
  [--push]                    # Optional: push to registry
  .
```

**Flags:**
- `--platform linux/amd64` — Single-arch build (x86_64 Linux)
- `--target production` — Use Stage 3 only
- `--tag` — Image name + version (set twice for "latest" alias)
- `--push` — Push to registry after build

## Dockerfile Details

### Stage 1: Assets

```dockerfile
FROM node:18-alpine AS assets

WORKDIR /build

COPY package*.json ./
RUN npm ci --prefer-offline

COPY gulpfile.js .
COPY assets assets

# `compile` = clean + vendor + scripts + styles (no archive step).
RUN npx gulp compile
```

**Key Points:**
- `npm ci` — deterministic install (uses `package-lock.json`)
- `gulp compile` — not `gulp build` (which also archives the dist; we don't need that)
- Output: all compiled CSS + JS in `build/production/`

### Stage 2: Vendor

```dockerfile
FROM php:8.4-cli AS vendor

COPY --from=composer:2.7 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip git \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /build

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --optimize-autoloader \
    --no-interaction --ignore-platform-reqs
```

**Key Points:**
- `--from=composer:2.7` — Use official Composer image (pins version)
- `--no-dev` — Exclude phpunit, dev libraries, etc.
- `--no-scripts` — Skip Composer post-install scripts (not needed in Docker)
- `--optimize-autoloader` — Generate optimized class map (faster runtime)
- `--ignore-platform-reqs` — CLI stage has no PHP extensions; verify at runtime instead
- Output: `vendor/` directory ready for production

### Stage 3: Production

```dockerfile
FROM php:8.4-fpm AS production

# Install extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev libpng-dev libjpeg-dev libwebp-dev \
        libxml2-dev libcurl4-openssl-dev libonig-dev libicu-dev
    && docker-php-ext-configure gd \
        --with-freetype --with-jpeg --with-webp
    && docker-php-ext-install -j "$(nproc)" \
        gd pdo_mysql curl xml exif zip mbstring intl
    && rm -rf /var/lib/apt/lists/*

# Copy compiled assets from Stage 1
COPY --from=assets /build/build/production/ /var/www/html/

# Copy vendor from Stage 2
COPY --from=vendor /build/vendor/ /var/www/html/vendor/

# Copy source code
COPY application/ system/ index.php /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 9000

# Healthcheck (optional)
HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
    CMD php -f /var/www/html/index.php || exit 1
```

**Key Points:**
- Extensions: gd (images), pdo_mysql (database), curl (webhooks), xml, exif (EXIF data), zip (archives), mbstring, intl (i18n)
- Copy FROM previous stages (not from build context; avoids re-building)
- `chown -R www-data` — FPM runs as www-data; must own /var/www/html
- `EXPOSE 9000` — FPM listens on port 9000 (for reverse proxy/Nginx)
- HEALTHCHECK — optional; can be overridden at runtime

## PHP Configuration (`docker/php-ini-production.ini`)

Mounted into `/usr/local/etc/php/conf.d/` at runtime.

**Key Settings:**

```ini
; Memory and execution
memory_limit = 512M              ; Images + async uploads
max_execution_time = 300         ; Long-running webhooks
max_input_time = 300

; Upload size
upload_max_filesize = 100M       ; Profile pictures, documents
post_max_size = 100M

; Error handling
log_errors = On
error_log = /proc/self/fd/2      ; Docker: stderr
display_errors = Off             ; Production: hide internal errors

; Opcache (PHP bytecode cache)
opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0  ; Production: no re-validation

; Session
session.save_handler = files
session.gc_maxlifetime = 86400   ; 24 hours
```

## Docker Compose (Local Dev)

The repo includes `docker-compose.yml` for local development. Use the Dockerfile above for production; the compose file is for dev/testing only.

```yaml
services:
  php:
    image: php:8.4-fpm
    build:
      context: .
      target: production
    ports:
      - "9000:9000"
    environment:
      - POLARIS_EA_JWT_SECRET=dev-secret
      - POLARIS_TURNSTILE_SITEKEY=1x00000000000000000000AA
      - POLARIS_TURNSTILE_SECRET=2x0000000000000000000000000000000AA
    volumes:
      - ./docker/php-ini-production.ini:/usr/local/etc/php/conf.d/polaris.ini
      - ./config.php:/var/www/html/config.php

  nginx:
    image: nginx:latest
    ports:
      - "8080:80"
    volumes:
      - ./docker/nginx.conf:/etc/nginx/nginx.conf

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: easyappointments
      MYSQL_USER: user
      MYSQL_PASSWORD: password
      MYSQL_ROOT_PASSWORD: root
    volumes:
      - mysql_data:/var/lib/mysql

  mailpit:
    image: axllent/mailpit:latest
    ports:
      - "8025:1025"
```

## Image Optimization

### Size Reduction

- **Stage 1 discarded:** Asset build tools (npm, Node) not in final image
- **Stage 2 discarded:** Composer + build tools not in final image
- **Production stage only:** FPM runtime + extensions + source code
- **Final size:** ~600 MB (vs ~2 GB if all stages included)

### Runtime Secrets

All secrets are injected at **runtime** via environment variables, never baked into the image:

```bash
docker run \
  -e POLARIS_EA_JWT_SECRET="$JWT_SECRET" \
  -e POLARIS_TURNSTILE_SITEKEY="$SITEKEY" \
  -e POLARIS_TURNSTILE_SECRET="$SECRET" \
  -e POLARIS_CORS_ORIGIN="https://getpolaris.my" \
  -e POLARIS_WEBHOOK_SECRET="$WEBHOOK_SECRET" \
  ghcr.io/jiun01/polaris-ea:v1.6.0-r1
```

To verify no secrets are in the image:

```bash
docker inspect ghcr.io/jiun01/polaris-ea:v1.6.0-r1 | jq '.Config.Env'
# Should NOT contain actual secret values
```

### Build Caching

The script uses Docker's layer caching. To force a clean build:

```bash
docker buildx build \
  --platform linux/amd64 \
  --target production \
  --tag ghcr.io/jiun01/polaris-ea:v1.6.0-r1 \
  --no-cache \
  .
```

## GHCR Push

### Prerequisites

1. **GitHub account:** jiun01 (repo owner)
2. **GHCR token:** PAT with `write:packages` scope
3. **Docker login:**

```bash
echo $GITHUB_TOKEN | docker login ghcr.io -u jiun01 --password-stdin
```

### Push Command

```bash
./docker/build.sh --push

# OR manually
docker buildx build \
  --platform linux/amd64 \
  --target production \
  --tag ghcr.io/jiun01/polaris-ea:v1.6.0-r1 \
  --tag ghcr.io/jiun01/polaris-ea:latest \
  --push \
  .
```

### Verification

```bash
# Pull the image
docker pull ghcr.io/jiun01/polaris-ea:v1.6.0-r1

# Inspect layers
docker inspect ghcr.io/jiun01/polaris-ea:v1.6.0-r1
```

## Related Codemaps

- **e2e_testing.md** — Testing the built image
- **multi_tenancy.md** — Runtime configuration (environment variables)
