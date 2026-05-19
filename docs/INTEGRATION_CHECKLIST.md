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

- [ ] **`git checkout -b polaris/integration main`** — forks from the post-defaults `main`.
- [ ] **`composer require firebase/php-jwt`** (or the JWT library chosen at pre-flight) — vendor-locked; commit `composer.json` + `composer.lock`.
- [ ] **Initial commit** — `chore(polaris/integration): branch from main + add JWT lib` — empty otherwise; gives a clean base for subsequent migration + model + controller commits.

### S3-3 — `tenant_id` migration + composite indexes

CI3 migrations are numbered files under `application/migrations/`. Verify the last upstream ordinal and continue from there.

- [ ] **List upstream's last migration number** — `ls application/migrations/ | sort -n | tail -5` — locks the new migration's prefix.
- [ ] **Migration NNN — `add_tenant_id_to_multi_tenant_tables`** — Ref: [10_FORK_INTEGRATION.md §5](../../docs/Polaris%20V2/10_FORK_INTEGRATION.md) + ADR-024 — adds `tenant_id` to: `ea_appointments`, `ea_services`, `ea_service_categories`, `ea_users` (provider role), `ea_users` (customer role), `ea_settings`, `ea_secretaries_providers`, `ea_consents`, `ea_blocked_periods`, `ea_user_settings`. Each column is NOT NULL, `VARCHAR(36)` (UUID) or `BIGINT` (matches Polaris `tenants.id`).
- [ ] **Migration NNN — composite indexes** — `(tenant_id, id)` on every multi-tenant table; replace any unique-on-just-`id` with `(tenant_id, id)` if upstream allowed cross-tenant lookups. Email uniqueness on `ea_users` becomes `(tenant_id, email)` UNIQUE.
- [ ] **Backfill seed** — for any pre-existing fixture rows, write a one-shot seed setting `tenant_id` to a single test-tenant UUID. Fresh tenant deploys never run this seed.
- [ ] **Rollback script** — `down()` method drops the column + indexes; document in HANDOFF §9.
- [ ] **PHPUnit case** — `tests/integration/TenantIdMigrationTest.php` — boots the migration, asserts column exists + NOT NULL + index present + rejects NULL inserts.

### S3-4 — `MY_Model` auto-scoping base class

- [ ] **`application/core/MY_Model.php`** — Ref: ADR-024 — extends `CI_Model`; constructor pulls `tenant_id` from `MY_Controller::tenant_context()` (set by middleware below). Overrides `get($id)`, `get_where($where)`, `insert($data)`, `update($id, $data)`, `delete($id)`:
  - `get`/`get_where`: auto-AND `tenant_id = :ctx` into the WHERE clause; throws `TenantContextMissingException` if context is unset (fails closed).
  - `insert`: auto-inject `tenant_id` into the `$data` array before the actual INSERT; rejects rows that ALREADY have a different `tenant_id` (defense-in-depth).
  - `update`/`delete`: AND `tenant_id = :ctx` into the WHERE clause; refuses to touch rows from other tenants even if `$id` matches.
- [ ] **Convert upstream models** — `application/models/{Appointments_model.php, Services_model.php, Service_categories_model.php, Users_model.php, Settings_model.php, ...}` change `extends CI_Model` → `extends MY_Model`. Verify each model's bespoke queries still work; some will need their hand-written WHERE clauses joined with `tenant_id`.
- [ ] **PHPUnit case** — `tests/integration/MyModelScopingTest.php` — seeds 2 tenants × 2 rows each; switches context; asserts every override returns only the active tenant's rows; asserts cross-tenant `update`/`delete` is a no-op.

### S3-5 — Tenant context middleware (Bearer + slug paths)

`MY_Controller` is upstream's base controller for backend + booking flows. Extend it (or create a new base) to set `tenant_id` from EITHER the JWT Bearer token (API path) OR the URL slug (public booking path).

- [ ] **`application/core/MY_Controller.php`** — adds `protected $tenant_id`; `tenant_context()` getter; static factory `from_bearer($token)` decodes JWT (audience must be `ea-fork`), reads `tenant_id` claim, verifies signature with shared secret from `config/polaris.php`; static factory `from_slug($slug)` reads from a `tenants` shadow table (see S3-6) or calls back to Polaris on cache miss.
- [ ] **Auth filter on `/api/v1/*`** — Ref: [10_FORK_INTEGRATION.md §6](../../docs/Polaris%20V2/10_FORK_INTEGRATION.md) — replaces upstream's static API-token check with the Bearer JWT path. Token shape: `{iss:'polaris', aud:'ea-fork', sub:'tenant:<id>', tenant_id:'<id>', exp:<60min>}`. Failure modes: missing header → 401; expired → 401 with `token_expired`; wrong audience → 401; valid → context set + request proceeds.
- [ ] **Slug filter on `/book/{slug}`** — patches `application/controllers/Appointments.php::index()` to read the URL segment, call `from_slug()`, and route to upstream's booking flow with context set. Invalid slug → 404 branded error page.
- [ ] **Root URL redirect** — `application/controllers/Home.php` (new) handles `GET /` with HTTP 302 → `https://getpolaris.my` (marketing stub). HANDOFF §6.4.
- [ ] **PHPUnit cases** — `tests/integration/TenantContextBearerTest.php` + `TenantContextSlugTest.php` — exhaustive happy/sad paths; checks fail-closed behavior; checks audit-log line emitted on auth failures.

### S3-6 — Tenant catalog (slug → tenant_id resolution)

The slug path needs to resolve `acme` → `tenant_id` without round-tripping to Polaris Postgres on every request. Two options — pick in INTEGRATION_PLAN.

- [ ] **Option A — Shadow `tenants` table inside EA's MySQL** — Polaris pushes `(tenant_id, slug, status)` rows via a one-shot HTTP webhook on tenant create/update/suspend; EA reads locally. Latency: zero per-request. Failure: stale data if webhook fails. Mitigation: nightly reconcile cron.
- [ ] **Option B — In-process cache + Polaris HTTP** — first request for an unseen slug calls Polaris `GET /api/internal/tenants/by-slug/{slug}` (mTLS-protected); cache for 5 min. Failure: cold-start latency; depends on Polaris uptime.
- [ ] **Implement chosen option + tests** — `application/models/Polaris_tenant_model.php` + Tenant lookup case in `tests/integration/`.
- [ ] **Document the choice in EA-side ADR** — captured in [EA_Whitelabel/PRD.md decision log](../../docs/Polaris%20V2/EA_Whitelabel/PRD.md).

### S3-7 — Public booking page (PDPA + Turnstile + rate limit)

Per ADR-013 Amendment 2026-05-16, the public booking surface stays — multi-tenanted via the slug routing from S3-5, hardened with PDPA opt-in + Turnstile + IP rate limit.

- [ ] **PDPA opt-in checkbox** — patches `application/views/pages/booking.php` to add an unticked-by-default checkbox: "I consent to Polaris storing my contact details for booking confirmation + reminders per [Privacy Policy](https://getpolaris.my/privacy)". Booking submit refuses to POST without it.
- [ ] **`audience_members` opt-in capture** — on booking success, if the checkbox was ticked, the booking controller POSTs to Polaris `POST /api/internal/audience/opt-in` with `{tenant_id, phone, name, source:'ea_public_booking'}`. Polaris creates the `audience_members` row (Phase 6 plumbing). HANDOFF §6 acceptance "Public booking opt-in capture".
- [ ] **Cloudflare Turnstile** — sitekey + verification call on the booking submit endpoint; reject if score below threshold. Site-key from `config/polaris.php`.
- [ ] **IP rate limit** — 10 booking attempts per IP per hour; backed by Redis (Polaris-side) or an EA-side counter table. HANDOFF §7.
- [ ] **PHPUnit + integration** — `tests/integration/PublicBookingTest.php` — checkbox required, Turnstile required, rate-limit triggers 429, audience_members POST round-trip works.

### S3-8 — Dockerfile + image build + GHCR push

Per HANDOFF §8 production cutover.

- [ ] **Verify `docker/` directory has a usable Dockerfile** — upstream EA ships one; confirm it includes `npx gulp build` so SCSS/JS compile inside the image. If missing, add a multi-stage build: stage 1 = node:20 + gulp, stage 2 = php:8.2-apache + the built assets.
- [ ] **`docker/build.sh`** (or top-level Makefile) — convenience wrapper around `docker build` + `docker push`.
- [ ] **Build the image** — `docker build -t ghcr.io/jiun01/polaris-ea:v1.5.2-r1 .` — must succeed locally first.
- [ ] **Smoke-boot locally** — `docker compose up` (against an empty MySQL); verify admin login page loads at `localhost:8080/index.php/backend`, shows Polaris chrome, no upstream brand leaks. HANDOFF §6.1.
- [ ] **GHCR push** — `docker push ghcr.io/jiun01/polaris-ea:v1.5.2-r1`. Requires `gh auth status` to include `write:packages`.
- [ ] **Tag the source** — `git tag polaris/v1.5.2-r1 && git push origin polaris/v1.5.2-r1`.

### S3-9 — E2E sweep + acceptance

Run HANDOFF §6 scenarios against the boot of the just-tagged image. All must pass before this checklist closes.

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

- [ ] **Flip `FEATURELIST.md` Multi-tenancy primitives row 🚧 → ✅** — `tenant_id` column, composite indexes, `MY_Model`, slug-based context-setter, Bearer-token enforcement
- [ ] **Append HANDOFF.md §11 — "Session 3"** — what landed, decisions made in-session, image tag confirmed, GHCR push timestamp
- [ ] **Update `POLARIS_FORK.md`** — flip `polaris/defaults` and `polaris/integration` rows from `*(future session)*` to actual branch names + tag; update `fork_tag: polaris/v1.5.2-r1 (planned)` → `polaris/v1.5.2-r1`
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
