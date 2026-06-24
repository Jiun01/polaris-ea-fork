# Contributing

When contributing to this repository, please first discuss the change you wish to make via issue, email, or any other method with the owner of this repository before making a change.

Please note we have a code of conduct, please follow it in all your interactions with the project.

## Dev Setup (Local Docker)

### Prerequisites

- Docker + Docker Compose (or Podman)
- Node.js 18+ (for asset compilation)
- PHP 8.4 CLI (for Composer)
- Git

### Quick Start

```bash
# Clone the fork
git clone https://github.com/Jiun01/polaris-ea-fork.git
cd polaris-ea-fork

# Create .env with test secrets
cat > .env << 'EOF'
POLARIS_EA_JWT_SECRET=test-secret-with-at-least-32-bytes-of-entropy
POLARIS_TURNSTILE_SITEKEY=1x00000000000000000000AA
POLARIS_TURNSTILE_SECRET=2x0000000000000000000000000000000AA
POLARIS_CORS_ORIGIN=http://localhost:3000
POLARIS_WEBHOOK_SECRET=test-webhook-secret
EOF

# Start Docker services (MySQL, PHP-FPM, Nginx, Mailpit)
docker compose up -d

# Install dependencies
npm install
composer install

# Compile frontend assets (SCSS → CSS, JS minification)
npm run build

# Run migrations (inside the PHP container)
docker compose exec php bash -c "php index.php migrate"

# App is now available at http://localhost:8080
# phpMyAdmin: http://localhost:8080/phpmyadmin
# Mailpit: http://localhost:8025
```

### Useful Commands

```bash
# Watch assets (auto-recompile on .scss/.js changes)
npm run start

# One-off asset rebuild (SCSS->CSS + JS minify) after editing assets/**/*.{scss,js}
npx gulp compile        # or: npx gulp styles / npx gulp scripts

# View application logs
docker compose logs -f php

# SSH into PHP container
docker compose exec php bash

# Reset database
docker compose exec php bash -c "php index.php migrate --version=0"
docker compose exec php bash -c "php index.php migrate"

# Stop services (keep data)
docker compose down

# Remove everything (fresh start)
docker compose down -v
```

> **Asset gotcha (bit us twice):** `assets/**/*.min.{js,css}` are **gitignored
> build artifacts** — editing a `.scss`/`.js` source does nothing until you
> rebuild (`npx gulp compile`; the Docker image runs this at build time, but
> local dev bind-mounts the host tree over the image's compiled assets).
>
> The cache-buster `application/config/app.php → cache_busting_token` (`?TSJxx`)
> is the only thing that changes the asset URL, so a stale token = returning
> browsers serve cached old JS/CSS. **In prod this is now automatic:** every EA
> image build stamps its unique image tag into the token (`EA_ASSET_TOKEN` →
> `Dockerfile` → `app.php`, see `polaris/infra/docker-bake.hcl`), so each release
> busts all asset caches with no hand-edit. The static `TSJxx` in `app.php` is
> only the fallback for a **plain local `docker build`** without the bake arg —
> bump it by hand only in that case. (A forgotten manual bump shipped stale
> booking JS to prod on 2026-06-24; the auto-stamp removes that footgun.)

## E2E Testing

Run Playwright tests against the local dev instance.

```bash
# Ensure Docker services are running
docker compose up -d

# Run all tests
npm run e2e

# Run specific suite
npx playwright test tests/e2e/auth_bearer.spec.ts

# View interactive report
npm run e2e:report

# Debug mode (browser visible, Playwright Inspector)
npx playwright test --debug
```

**Note:** E2E tests verify:
- Bearer token authentication on /api/v1/* routes
- Cross-tenant data isolation (Tenant A cannot see Tenant B's data)
- Public booking form (/book/{slug}) hardening (Turnstile, PDPA, rate limiting)
- Slug routing and tenant context propagation

See [docs/CODEMAPS/e2e_testing.md](../docs/CODEMAPS/e2e_testing.md) for test documentation.

## Pull Request Process

1. **Branch naming:** Use `feature/*`, `fix/*`, or `docs/*` prefixes.
   - Example: `feature/multi-tenant-settings`

2. **Commits:** Clear, atomic commits with descriptive messages.
   - Example: `feat(multi-tenancy): add tenant_id to services table`
   - Reference relevant issue/task: `chore(S3-4): add MY_Model base class`

3. **Testing:** 
   - Run E2E tests: `npm run e2e`
   - Verify Docker build: `docker build -t test:local .`
   - (Optional) Add unit tests for complex logic (see `phpunit.xml`)

4. **Documentation:** Update relevant codemaps/docs.
   - Edit files in `docs/CODEMAPS/` to reflect architecture changes
   - Environment variables → update `application/config/polaris.php` + `docs/CODEMAPS/INDEX.md`
   - New features → add test cases in `tests/e2e/`

5. **Code style:** Matches Easy!Appointments conventions.
   - PHP: PSR-4 autoloading, PSR-2 style
   - JS: Prettier (`npx prettier --write <file>`)
   - Run before commit: `npx prettier --check .`

6. **Target branch:** `main` (this fork operates on `main`; no separate develop).

7. **PR review:** Author will review and merge upon approval.

## Building for Production

```bash
# Build multi-stage Docker image
./docker/build.sh

# Build and push to GHCR (reads GITHUB_TOKEN from ../polaris/.env, or `docker login` first)
./docker/build.sh --push

# Custom version tag
./docker/build.sh --tag v1.5.2-r2 --push

# Verify the image
docker run ghcr.io/jiun01/polaris-ea:v1.5.2-r1 php -v
```

See [docs/CODEMAPS/docker_build.md](../docs/CODEMAPS/docker_build.md) for details.

## Architecture & Codemaps

Key documentation for contributors:

| Document | Purpose |
|----------|---------|
| [docs/CODEMAPS/INDEX.md](../docs/CODEMAPS/INDEX.md) | Overview + environment variable reference |
| [docs/CODEMAPS/multi_tenancy.md](../docs/CODEMAPS/multi_tenancy.md) | Tenant isolation: MY_Model, MY_Controller, migrations |
| [docs/CODEMAPS/authentication.md](../docs/CODEMAPS/authentication.md) | Bearer JWT auth: BearerAuthHook, from_bearer() |
| [docs/CODEMAPS/public_booking.md](../docs/CODEMAPS/public_booking.md) | /book/{slug} form, Turnstile, PDPA, rate limiting |
| [docs/CODEMAPS/docker_build.md](../docs/CODEMAPS/docker_build.md) | Multi-stage build, GHCR push |
| [docs/CODEMAPS/e2e_testing.md](../docs/CODEMAPS/e2e_testing.md) | Playwright test suite |

## Rebase Notes

This fork extends Easy!Appointments 1.5.2. When rebasing onto a newer upstream version:

1. Rebase `polaris/integration` branch (contains 061/062 migrations)
2. Verify no migration number collisions
3. Check MY_Model/MY_Controller overrides still work
4. Re-run E2E suite

See [POLARIS_FORK.md](../POLARIS_FORK.md) for fork posture + upstream baseline.
