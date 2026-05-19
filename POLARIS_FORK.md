# Polaris EA Fork

This repository is a **white-labelled fork of [Easy!Appointments](https://github.com/alextselegidis/easyappointments)** maintained as the booking subsystem for [Polaris](https://getpolaris.my).

## Upstream

| Field | Value |
|---|---|
| Upstream repo | `alextselegidis/easyappointments` |
| Baseline tag | `1.5.2` |
| License | **GPL-3.0** (`LICENSE` file untouched) |
| Fork posture | SaaS-only — never ship this image to tenant-owned infrastructure (would trigger GPL-3.0 source-disclosure) |

## Branches

| Branch | Purpose |
|---|---|
| `main` | Mirrors upstream `1.5.2` exactly. Move forward only when the fork is rebased onto a newer EA tag. |
| `polaris/whitelabel` | Brand sweep across user-facing strings, views, emails, assets. English locale only at GA. |
| `polaris/defaults` | Polaris install configs (timezone, currency, sender email); installer seed step disabled. Merged into main. |
| `polaris/integration` | `tenant_id` migration + `MY_Model` enforcement + Bearer-token / slug routing + PDPA/Turnstile + multi-stage Dockerfile + §6 E2E sweep. Merged into main → tagged `polaris/v1.5.2-r1`. |

Tagging convention: `polaris/v1.5.2-r1` (first Polaris revision atop upstream `1.5.2`).

## Production image

```
ghcr.io/jiun01/polaris-ea:v1.5.2-r1
```

Build and push: `bash docker/build.sh --push`  
JWT secret, Turnstile keys, and CORS origin are sourced from environment variables — see `application/config/polaris.php`.

## Documentation

Operational + maintenance docs live in the Polaris SSOT, not this repo:

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
