# Pre-Phase-7 — EA Fork Integration (`polaris-ea-fork`)

> **Where this file lives:** `polaris-ea-fork/docs/INTEGRATION_CHECKLIST.md` (this repo, the EA fork).
> **Where the work happens:** also this repo. The checklist describes work to do *here*, in PHP/CodeIgniter 3, against the EA fork's MySQL.
> **Where the Polaris-side follow-on work goes:** the sibling `polaris/` repo, after this checklist closes — see [polaris/phase-checklist/phase-07-handoff.md](../../polaris/phase-checklist/phase-07-handoff.md) (to be authored in S3-0).

**Status**: **not started** — prerequisite for the Polaris monorepo's Phase 7 (handoff + booking) | **Duration**: ~2 weeks (1 dev) per [EA_Whitelabel/HANDOFF.md §7](../../docs/Polaris%20V2/EA_Whitelabel/HANDOFF.md) | **Depends on**: Polaris monorepo Phases 1, 2 (tenants table + slug column live in Polaris Postgres)
**SSOT root**: [docs/Polaris V2/EA_Whitelabel/](../../docs/Polaris%20V2/EA_Whitelabel/) | **Driving ADRs**: ADR-013 + Amendment 2026-05-16, ADR-024, ADR-030 Amendment
**Stack**: PHP 8 / CodeIgniter 3 / MySQL — different from the Polaris monorepo (Python / FastAPI / Postgres).

**Goal**: Finish the EA fork to a tagged, GHCR-published image (`ghcr.io/jiun01/polaris-ea:v1.5.2-r1`) with `tenant_id`-enforced multi-tenancy on every multi-tenant table, slug-routed public booking at `calendar.getpolaris.my/book/{tenant-slug}`, and Bearer-token-scoped REST API for Polaris-side `services/booking.py` to consume. After this lands, Phase 7's booking deliverable in the monorepo has a real contract to integrate against.

> **End of Pre-Phase-7 = EA image is contract-stable**: a Polaris tenant (with `tenants.slug='acme'`) can be issued a Bearer JWT against `ea-fork`, call `GET /api/v1/availabilities`, and get back ONLY acme's slots. Public visitor hits `calendar.getpolaris.my/book/acme`, sees acme's services + Turnstile + PDPA opt-in, books a slot, and the resulting `ea_appointments` row carries `tenant_id=acme`. Zero cross-tenant leakage in §6 E2E sweep.

## Why this is its own session (not Phase 7)

Per [04_BUILD_PHASES_AND_SKILLS.md:15–16 + line 419](../../docs/Polaris%20V2/04_BUILD_PHASES_AND_SKILLS.md) the EA fork build was scheduled to run **parallel during Phases 1–4** per ADR-030 Amendment so it would not extend Polaris's critical path. In practice only `polaris/whitelabel` (B1–B18) + a partial `polaris/defaults` pass (B21–B24, merged direct to `main`) landed before Phase 6. The `polaris/integration` branch — the multi-tenancy work the Polaris monorepo's `services/booking.py` calls into — is still 🚧 in [EA_Whitelabel/FEATURELIST.md §Multi-tenancy primitives](../../docs/Polaris%20V2/EA_Whitelabel/FEATURELIST.md). This checklist closes that gap.

## Context loadout

- [EA_Whitelabel/PRD.md](../../docs/Polaris%20V2/EA_Whitelabel/PRD.md) — signed product spec for the fork (problem, MoSCoW, success metrics, in-spec decisions)
- [EA_Whitelabel/HANDOFF.md](../../docs/Polaris%20V2/EA_Whitelabel/HANDOFF.md) — execution playbook + session-by-session progress log; §3 branch strategy, §6 E2E scenarios, §7 acceptance criteria, §8 production cutover
- [EA_Whitelabel/FEATURELIST.md](../../docs/Polaris%20V2/EA_Whitelabel/FEATURELIST.md) — per-feature kept/rebranded/blocked status; flip 🚧 → ✅ as items land
- [EA_Whitelabel/FORK_MAINTENANCE.md](../../docs/Polaris%20V2/EA_Whitelabel/FORK_MAINTENANCE.md) — file-category map for upstream rebases; G2 collision note
- [EA_Whitelabel/EA_Whitelabel_Scrub_Inventory.md](../../docs/Polaris%20V2/EA_Whitelabel/EA_Whitelabel_Scrub_Inventory.md) — exhaustive brand-string audit (mostly closed by B1–B18)
- [10_FORK_INTEGRATION.md §5–§6](../../docs/Polaris%20V2/10_FORK_INTEGRATION.md) — EA fork integration spec from the Polaris side
- [09_DECISION_LOG.md](../../docs/Polaris%20V2/09_DECISION_LOG.md) — **ADR-013** (fork-and-extend, with Amendment 2026-05-16 keeping public booking page); **ADR-024** (shared MySQL schema + `tenant_id` column + `MY_Model` enforcement); **ADR-030 Amendment** (parallel EA build)
- [00b_PRD_API_DATA.md](../../docs/Polaris%20V2/00b_PRD_API_DATA.md) §"EA fork API" — Bearer-token contract surface that `services/booking.py` will call
- Repo on disk (this repo): [POLARIS_FORK.md](../POLARIS_FORK.md), `../application/`, `../docker/`

## ECC skills for this session

EA fork is CodeIgniter 3 (PHP 8). The Polaris-native skills don't all map cleanly. Use:

**Phase-specific:**

- `ecc:plan` — PHASE-START — produce an EA-fork-specific execution plan (analogue of a `04*_PHASE_*_DEEP.md`) covering branch order, migration ordinal, slug-routing controller patch, Bearer-token auth path, image build. Save as `../docs/Polaris V2/EA_Whitelabel/INTEGRATION_PLAN.md` (new doc) and link from HANDOFF.md §Session 3.
- `ecc:database-migrations` — PRE-CODE on the EA-side `tenant_id` migration — append-only, NOT NULL with backfill, composite indexes; CI3 migration numbering must continue past upstream's last index (verify via `application/migrations/` listing)
- `ecc:security-review` — DURING-IMPL on the Bearer-token JWT decoder + slug context-setter — these are the auth boundary between Polaris and EA; must fail-closed on missing/invalid tenant context; must reject token reuse across tenants
- `ecc:docker-patterns` — PRE-CUTOVER on the Phase 6 image build — gulp build inside the Docker layer (so the published image already has compiled SCSS + minified JS), multi-stage build to keep image lean, GHCR push with the `polaris/v1.5.2-r1` tag
- `ecc:e2e-testing` — POST-IMPL on HANDOFF §6 scenarios (9 tests) — Playwright or `pytest` HTTP harness driving `localhost:8080` + the public slug path; covers admin boot, public slug 200/404, root redirect, cross-tenant isolation (API + public), email render, Bearer-token integration
- `ecc:documentation-lookup` — DURING-IMPL when touching unfamiliar CI3 surfaces — `MY_Controller` extension pattern, `CI_Migration` numbering, `MY_Model` override idioms, slug-to-context middleware analogues in CI3
- `ecc:verification-loop` — PRE-CUTOVER — runs §6 E2E sweep against the locally booted image before tagging

**Cross-phase (still applicable):**

- `ecc:tdd-workflow` — PRE-CODE on `MY_Model` overrides + the slug-resolution helper — write failing PHPUnit cases first (EA has `phpunit.xml` already)
- `ecc:simplify` — PRE-PR on every Polaris-introduced PHP file — CI3 idioms, no over-abstraction
- `ecc:quality-gate` — PRE-MERGE on `polaris/integration` before tagging
- `ecc:architecture-decision-records` — DURING-IMPL — any new EA-side decision (e.g. JWT lib choice, slug-cache layer, Bearer-token revocation strategy) gets an ADR in the EA whitelabel decision log (or rolls up to a Polaris ADR if it changes the integration contract)
- `ecc:production-audit` — PRE-CUTOVER on the published image — secret-leak scan, Dockerfile review, healthcheck shape

## Pre-flight checklist

- [ ] Polaris monorepo Phase 6 (Outbound) exit criteria green or explicitly waived — see [polaris/phase-checklist/phase-06-outbound.md](../../polaris/phase-checklist/phase-06-outbound.md). EA work doesn't depend on Phase 6 code, but the Polaris monorepo should be in a clean post-PR-9 state before pivoting.
- [ ] Confirm `tenants.slug` column exists in Polaris Postgres with UNIQUE constraint — verify via `packages/db-migrations/` and a `\d tenants` against the dev DB. Slug-routing in EA controllers depends on this. (HANDOFF.md Session 1 §"Phase 4 prerequisite identified".)
- [ ] Confirm `tenants.id` PK type (UUID vs BIGINT) — locks the column type of the EA-side `tenant_id` migration. UUID = `VARCHAR(36)` on MySQL side or use `BINARY(16)` for storage; BIGINT = direct mapping.
- [ ] Confirm `tenant_secrets.ea_api_token_secret_ref` column exists in Polaris Postgres — `services/booking.py` reads this to fetch the encrypted Bearer token. (HANDOFF.md Session 1 §"Phase 7 prerequisites identified".)
- [ ] Confirm `polaris-ea-fork` working tree is clean and on `main` — `git status` shows nothing pending; `git log -1` is `681b2ac2 feat(phase3): Polaris install defaults...`. Branch `polaris/defaults` does NOT exist as a separate ref because B21–B24 landed direct on main; that's intentional and documented as a deviation from HANDOFF §3.
- [ ] Confirm GHCR push credentials are configured for `ghcr.io/jiun01/*` — `gh auth status` shows `read:packages, write:packages` scopes.
- [ ] Confirm Cloudflare Turnstile site-key + secret exist for `calendar.getpolaris.my` — needed by the public booking page.
- [ ] Decide Bearer-token JWT library — `firebase/php-jwt` (Composer) is the obvious pick; capture the choice in an EA-side ADR (per [EA_Whitelabel/PRD.md](../../docs/Polaris%20V2/EA_Whitelabel/PRD.md) decision log).
- [ ] Decide Polaris marketing-stub controller name (HANDOFF.md Session 1) — proposed `application/controllers/Home.php` redirects `GET /` → marketing site so the EA fork's root URL does not surface the default-tenant booking page.

## Tasks

### S3-0 — Session-start artifacts (no code)

- [ ] **Author `../docs/Polaris V2/EA_Whitelabel/INTEGRATION_PLAN.md`** — Ref: `ecc:plan` PHASE-START — mirror the 11-section template of [04g_PHASE_6_DEEP.md](../../docs/Polaris%20V2/04g_PHASE_6_DEEP.md) but scoped to the EA fork integration work. Sections: 1) goal + non-goals, 2) branch + migration ordering, 3) MySQL schema deltas, 4) MY_Model contract, 5) slug routing patch points, 6) Bearer-token JWT decoder, 7) public booking page (PDPA + Turnstile + rate limit), 8) Dockerfile + GHCR push, 9) E2E scenarios mapping (§6.1–6.9), 10) rollback, 11) open questions.
- [ ] **Author the Polaris-monorepo Phase 7 skeleton** — quick `polaris/phase-checklist/phase-07-handoff.md` skeleton — captures the Polaris monorepo work that picks up AFTER this checklist closes: handoff service, booking service (against the tagged EA image), messenger profile, Chatwoot label sync. Mark IG/FB comment work `~~struck~~` per GA trim. Don't fully author yet; this task just plants the file in the sibling repo.
- [ ] **Update [polaris/phase-checklist/README.md](../../polaris/phase-checklist/README.md)** — add a row pointing at this checklist (in the EA fork) as a Pre-Phase-7 prerequisite; flip Phase 7 row to "not started" once `phase-07-handoff.md` is planted.

### S3-1 — `polaris/defaults` branch reconciliation (clean-up, no behavior change)

The B21–B24 work landed direct on `main` in the EA fork instead of on `polaris/defaults` per HANDOFF §3. Two paths — pick one and document in INTEGRATION_PLAN.

- [ ] **Option A — accept the deviation** — leave defaults on `main`, amend HANDOFF §3 to note the branch was inlined into `main` after Session 2 because the defaults were small enough not to warrant a topic branch. Branch `polaris/integration` then forks off `main` directly. Simpler; preferred if the B21–B24 commits are clean.
- [ ] **Option B — retroactively reconstruct `polaris/defaults`** — `git checkout -b polaris/defaults <pre-B21-sha>`; cherry-pick B21–B24; force-update HANDOFF to match. Only do this if the B21–B24 commits are noisy and need re-shaping.
- [ ] **Whichever path, push the result** — branch ref + tag if applicable; update HANDOFF §3 + §11 (session log).

### S3-2 — `polaris/integration` branch init

- [x] **`git checkout -b polaris/integration main`** — forks from the post-defaults `main`.
- [x] **`composer require firebase/php-jwt`** (or the JWT library chosen at pre-flight) — vendor-locked; commit `composer.json` + `composer.lock`.
- [x] **Initial commit** — `chore(polaris/integration): branch from main + add JWT lib` — empty otherwise; gives a clean base for subsequent migration + model + controller commits.

### S3-3 — `tenant_id` migration + composite indexes

CI3 migrations are numbered files under `application/migrations/`. Verify the last upstream ordinal and continue from there.

- [x] **List upstream's last migration number** — `ls application/migrations/ | sort -n | tail -5` — locks the new migration's prefix.
- [x] **Migration 061 — `add_tenant_id_to_multi_tenant_tables`** — `tenant_id VARCHAR(36) NOT NULL` + `tenant_branch_id VARCHAR(36) NULL` on 10 tables; composite index `(tenant_id, id)`; `UNIQUE(tenant_id, email)` on `ea_users`.
- [x] **Migration 062 — `create_ea_polaris_tenants`** — shadow table `ea_polaris_tenants (tenant_id PK, slug UNIQUE, status ENUM, synced_at)`.
- [ ] **Backfill seed** — for any pre-existing fixture rows, write a one-shot seed setting `tenant_id` to a single test-tenant UUID. Fresh tenant deploys never run this seed.
- [x] **Rollback script** — `down()` method included in both migrations.
- [ ] **PHPUnit case** — `tests/integration/TenantIdMigrationTest.php` — boots the migration, asserts column exists + NOT NULL + index present + rejects NULL inserts.

### S3-4 — `MY_Model` auto-scoping base class

- [x] **`application/core/MY_Model.php`** — extends `EA_Model`; `find()`, `get_where()`, `insert()`, `update()`, `delete()` auto-scope by `MY_Controller::$tenant_id`; throws `TenantContextMissingException` when context unset.
- [ ] **Convert upstream models** — `application/models/{Appointments_model.php, Services_model.php, ...}` change `extends EA_Model` → `extends MY_Model`. Deferred — upstream models have bespoke query patterns; safe to do incrementally post-merge.
- [x] **PHPUnit case** — `tests/Unit/Core/MyModelTest.php` — 4 cases: get_where scopes to tenant, insert injects tenant_id, update is cross-tenant no-op, missing context throws.

### S3-5 — Tenant context middleware (Bearer + slug paths)

`MY_Controller` is upstream's base controller for backend + booking flows. Extend it (or create a new base) to set `tenant_id` from EITHER the JWT Bearer token (API path) OR the URL slug (public booking path).

- [x] **`application/core/MY_Controller.php`** — static `$tenant_id`/`$tenant_branch_id`; `set_tenant_context()`, `clear_tenant_context()`; `from_bearer()` decodes HS256 JWT (iss/aud/UUID validation); `from_slug()` delegates to `Polaris_tenant_model`.
- [x] **Auth filter on `/api/v1/*`** — `BearerAuthHook` (pre_controller): clears stale FPM static state, extracts Bearer token (8 KB cap, case-insensitive headers), calls `from_bearer()`, responds 401 on failure.
- [x] **Slug filter on `/book/{slug}`** — `Booking::slug()` validates slug regex, calls `from_slug()`, falls through to `index()`. Route: `$route['book/([a-z][a-z0-9-]{0,98})'] = 'booking/slug/$1'`.
- [x] **Root URL redirect** — `application/controllers/Home.php` → 302 `https://getpolaris.my`. `default_controller = 'home'`.
- [ ] **PHPUnit cases** — `tests/integration/TenantContextBearerTest.php` + `TenantContextSlugTest.php` — deferred; requires live DB + JWT fixture setup.

### S3-6 — Tenant catalog (slug → tenant_id resolution)

The slug path needs to resolve `acme` → `tenant_id` without round-tripping to Polaris Postgres on every request. Two options — pick in INTEGRATION_PLAN.

- [x] **Option A chosen — Shadow `ea_polaris_tenants` table inside EA's MySQL** — migration 062 creates the table; Polaris webhook syncs rows on tenant create/update/suspend.
- [ ] **Option B** — not chosen.
- [x] **Implement chosen option** — `application/models/Polaris_tenant_model.php`: `resolve_slug()` queries `ea_polaris_tenants WHERE slug=? AND status='active'`.
- [ ] **Document the choice in EA-side ADR** — to be captured in HANDOFF §11 / PRD decision log.

### S3-7 — Public booking page (PDPA + Turnstile + rate limit)

Per ADR-013 Amendment 2026-05-16, the public booking surface stays — multi-tenanted via the slug routing from S3-5, hardened with PDPA opt-in + Turnstile + IP rate limit.

- [x] **PDPA opt-in checkbox** — `booking_final_step.php`: `#accept-to-pdpa-polaris` checkbox with link to `https://getpolaris.my/privacy`.
- [ ] **`audience_members` opt-in capture** — deferred to Phase 7; Polaris `POST /api/internal/audience/opt-in` not yet deployed.
- [x] **Cloudflare Turnstile** — `cf-turnstile` widget in final step; server-side verify in `Booking::register()` via `verify_turnstile()`; sitekey + secret from `config/polaris.php`.
- [x] **IP rate limit** — `rate_limit($ip, 10, 3600)` at top of `Booking::register()` using existing EA file-cache helper.
- [ ] **PHPUnit + integration** — `tests/integration/PublicBookingTest.php` — deferred; requires running CI3 HTTP stack.

### S3-8 — Dockerfile + image build + GHCR push

Per HANDOFF §8 production cutover.

- [x] **Multi-stage Dockerfile** — `Dockerfile` (repo root): stage 1 Node 18 assets (gulp build), stage 2 Composer --no-dev, stage 3 PHP 8.4-fpm runtime extensions only (no xdebug). `.dockerignore` excludes dev artefacts.
- [x] **`docker/build.sh`** — buildx wrapper; `--push` flag uploads to GHCR; auto-reads version from git tag `polaris/v*`.
- [ ] **Build the image** — `bash docker/build.sh` — run after merge + tag.
- [ ] **Smoke-boot locally** — run §6.1 Playwright scenario against booted image.
- [ ] **GHCR push** — `bash docker/build.sh --push` after confirming `write:packages` scope.
- [x] **Tag the source** — `git tag polaris/v1.5.2-r1` applied at S3-10 merge commit.

### S3-9 — E2E sweep + acceptance

Run HANDOFF §6 scenarios against the boot of the just-tagged image. All must pass before this checklist closes.

Playwright specs written in `tests/e2e/booking_sweep.spec.ts`. Run with `BASE_URL=http://localhost:8080 E2E_JWT_SECRET=<secret> npx playwright test` against the booted image.

- [ ] **§6.1 Admin boot** — Polaris chrome, no upstream brand leaks
- [ ] **§6.2 Public booking via slug** — `GET /book/acme` renders acme's slot picker + PDPA + Turnstile
- [ ] **§6.3 Public booking invalid slug** — `GET /book/badslug` → 404 with branded error page
- [ ] **§6.4 Root URL redirect** — `GET /` → 302 to marketing
- [ ] **§6.5 Cross-tenant API isolation** — tenant-A Bearer cannot read tenant-B rows on `/api/v1/services`, `/api/v1/providers`, `/api/v1/customers`, `/api/v1/appointments`
- [ ] **§6.6 Cross-tenant public isolation** — `GET /book/acme` HTML contains zero of tenant-B's services / providers / slots
- [ ] **§6.7 Email rendering** — appointment-save email title = `"X | Polaris Calendar"`, footer = `"Powered by Polaris"`, useragent header = `"Polaris Calendar"`
- [ ] **§6.8 Bearer-token API integration** — a Polaris monorepo test harness (or curl) gets slots back via Bearer JWT, books a slot, `ea_appointments` row carries correct `tenant_id`
- [ ] **§6.9 No upstream brand leftovers** — grep over rendered HTML of full admin click-through AND full public booking flow → zero "Easy!Appointments" except the 1 acknowledged license-attribution line on About page

### S3-10 — Docs + handoff back to Polaris monorepo

- [ ] **Flip `FEATURELIST.md` Multi-tenancy primitives row 🚧 → ✅** — in Polaris monorepo; do after GHCR push confirmed.
- [ ] **Append HANDOFF.md §11 — "Session 3"** — in Polaris monorepo; note: migrations 061+062, MY_Model, BearerAuthHook, slug routing, PDPA+Turnstile, Dockerfile, §6 E2E specs, image tag `polaris/v1.5.2-r1`.
- [x] **Update `POLARIS_FORK.md`** — branches updated; image reference + build instructions added.
- [ ] **Write [polaris/phase-checklist/phase-07-handoff.md](../../polaris/phase-checklist/phase-07-handoff.md)** in the sibling repo (the real Phase 7 checklist) — now that the EA contract is locked, the Polaris monorepo Phase 7 can be planned with concrete API shapes. Mark IG/FB comment auto-reply tasks `~~struck~~` per the 2026-05-15 GA trim.
- [ ] **Update [polaris/phase-checklist/README.md](../../polaris/phase-checklist/README.md)** in the sibling repo — add a row pointing at this checklist as a Pre-Phase-7 prerequisite; flip Phase 7 row to **not started** with `phase-07-handoff.md` linked.

## Exit criteria

- [ ] `polaris/integration` branch on `polaris-ea-fork` carries all S3-3 through S3-7 commits, pushed to origin
- [ ] `polaris/v1.5.2-r1` git tag points at the latest `polaris/integration` HEAD
- [ ] `ghcr.io/jiun01/polaris-ea:v1.5.2-r1` exists in GHCR, pullable from a machine other than the dev box
- [ ] §6.1–§6.9 E2E sweep all green
- [ ] [EA_Whitelabel/FEATURELIST.md](../../docs/Polaris%20V2/EA_Whitelabel/FEATURELIST.md) §Multi-tenancy primitives all ✅
- [ ] Zero cross-tenant data leakage in fuzz tests (run §6.5 + §6.6 across 50 random tenant pairs)
- [ ] HANDOFF.md §11 closed with Session 3 entry
- [ ] [polaris/phase-checklist/phase-07-handoff.md](../../polaris/phase-checklist/phase-07-handoff.md) exists in the sibling repo (skeleton OK; full content drafted at start of that session)

## Out of scope for this checklist (defer to Phase 7 or v2.1)

- IG / FB comment private-reply (deferred to v2.1 per [polaris/phase-checklist/README.md:54](../../polaris/phase-checklist/README.md) GA trim banner)
- Telegram channel (deferred to v2.1)
- The Polaris monorepo `services/booking.py`, `services/handoff.py`, `services/messenger_profile.py`, Chatwoot label sync — these are Phase 7 work; this checklist only delivers their EA contract dependency
- Per-tenant calendar themes, custom branch palettes, branding override per tenant — `tenants.brand_color` already exists; theme propagation into EA is post-GA
- Embedded EA inside the Polaris portal (iframe vs. SSO bridge) — out of GA scope; tenants navigate to `calendar.getpolaris.my` directly

## Risk register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| EA's upstream rebases break `MY_Model` overrides because upstream changed model contracts | Med | Med | Pin to `1.5.2`; never auto-rebase; `FORK_MAINTENANCE.md` documents rebase recipe |
| `polaris/defaults` deviation (landed on main) confuses future devs | Low | Low | Document explicitly in HANDOFF §3 amendment |
| Slug-cache staleness on tenant suspend leaks an opt-out tenant's booking page to public | Med | High | If Option B (cache + Polaris HTTP), set TTL to 60s on suspend signal AND emit cache-bust event from Polaris; if Option A (shadow table), reconcile cron + check `status='active'` on every resolve |
| Bearer JWT secret leak compromises every tenant's EA data | Low | Critical | Store secret in Polaris `tenant_secrets` (encrypted) AND in EA `config/polaris.php` outside repo; rotate quarterly; log Bearer-token auth failures |
| GHCR rate-limit during image push from a slow connection | Low | Low | Retry with `docker push`; image immutability makes the operation idempotent |
| Phase 7 (Polaris monorepo) starts before this checklist closes and codes against a phantom EA contract | Med | High | This checklist is the merge gate; no Phase 7 booking work begins until S3-10 lands |

---

**Authoring conventions** — same as [polaris/phase-checklist/README.md](../../polaris/phase-checklist/README.md) in the sibling repo. Every task line carries the SSOT/ADR ref that authorises it. No new ADRs introduced by this checklist; new decisions taken in-session go into EA-side PRD decision log or [09_DECISION_LOG.md](../../docs/Polaris%20V2/09_DECISION_LOG.md) as appropriate. `~~Strikethrough~~` = deferred per GA trim.
