# Polaris EA Fork

This repository is a **white-labelled fork of [Easy!Appointments](https://github.com/alextselegidis/easyappointments)** maintained as the booking subsystem for [Polaris](https://getpolaris.my).

## Upstream

| Field | Value |
|---|---|
| Upstream repo | `alextselegidis/easyappointments` |
| Baseline tag | `1.6.0` (rebased from `1.5.2` on 2026-05-30 — security release) |
| License | **GPL-3.0** (`LICENSE` file untouched) |
| Fork posture | SaaS-only — never ship this image to tenant-owned infrastructure (would trigger GPL-3.0 source-disclosure) |

## Branches

| Branch | Purpose |
|---|---|
| `main` | Carries the Polaris patch-set on the current EA baseline (`1.6.0`). Advances when rebased onto a newer EA tag. |
| `polaris/whitelabel` | Brand sweep across user-facing strings, views, emails, assets. English locale only at GA. |
| `polaris/defaults` | Polaris install configs (timezone, currency, sender email); installer seed step disabled. Merged into main. |
| `polaris/integration` | `tenant_id` migration + `MY_Model` enforcement + Bearer-token / slug routing + PDPA/Turnstile + multi-stage Dockerfile + §6 E2E sweep. |
| `polaris/v1.6.0-r1` | Rebase of the full patch-set onto upstream `1.6.0` (security release). Merged into main → tagged `polaris/v1.6.0-r1`. |

Tagging convention: `polaris/v{ea_version}-r{rev}` — current `polaris/v1.6.0-r1` (first Polaris revision atop upstream `1.6.0`).

## Production image

```
ghcr.io/jiun01/polaris-ea:v1.6.0-r1
```

Build and push: `bash docker/build.sh --push`  
JWT secret, Turnstile keys, and CORS origin are sourced from environment variables — see `application/config/polaris.php`.

## Documentation

### This Repository (Polaris EA Fork)

Architectural + developer documentation:

| Doc | Path |
|---|---|
| **Codemaps INDEX** (entry point) | `docs/CODEMAPS/INDEX.md` |
| Multi-tenancy architecture | `docs/CODEMAPS/multi_tenancy.md` |
| Bearer JWT authentication | `docs/CODEMAPS/authentication.md` |
| Public booking (/book/{slug}) | `docs/CODEMAPS/public_booking.md` |
| Docker multi-stage build | `docs/CODEMAPS/docker_build.md` |
| Playwright E2E test suite | `docs/CODEMAPS/e2e_testing.md` |
| Developer setup | `.github/CONTRIBUTING.md` |

### Polaris Monorepo (External)

Operational + maintenance docs live in the Polaris SSOT:

| Doc | Path |
|---|---|
| Signed PRD | `docs/Polaris V2/EA_Whitelabel/PRD.md` |
| Execution playbook + progress log | `docs/Polaris V2/EA_Whitelabel/HANDOFF.md` |
| Per-feature decision register | `docs/Polaris V2/EA_Whitelabel/FEATURELIST.md` |
| File categorization + rebase gotchas | `docs/Polaris V2/EA_Whitelabel/FORK_MAINTENANCE.md` |
| Exhaustive brand-string audit | `docs/Polaris V2/EA_Whitelabel/EA_Whitelabel_Scrub_Inventory.md` |

## License attribution

GPL-3.0 attribution headers in source files (`/* * Easy!Appointments - Online Appointment Scheduler ... */`) are **preserved unchanged** across the fork — this is a copyright-attribution requirement, not a brand string. Only user-facing strings (UI text, email bodies, page titles, logos) are swept.

The original `LICENSE` file, `CHANGELOG.md`, and all upstream contributor names remain unmodified.
