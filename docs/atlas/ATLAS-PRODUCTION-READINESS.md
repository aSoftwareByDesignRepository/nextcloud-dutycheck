# Atlas — Production-Readiness Report (DutyCheck)

**Product:** DutyCheck (`nextcloud/apps/dutycheck`) + companion `mobile/dutycheck`  
**Round:** 6 (part-by-part Fixer → Harsh Critic AAA, looped)  
**Session date:** 2026-09-08  
**Auditor posture:** Atlas (execute-or-don’t-claim)  
**Queue:** `.cursor/atlas-dutycheck/queue.json`  
**exit_met:** **true** (46 accepted + 1 env_gap; zero pending/rejected; all critics wow=9 ACCEPT; must_fix empty)

---

## Scope Manifest

| Item | Value |
|------|-------|
| **Docker** | `nextcloud/docker-compose.yml` — service `nextcloud` |
| **Roles** | NC admin; DutyCheck app admin; planner; linked employee; company + location scope |
| **Platforms** | Web + Android companion (Detox) |
| **iOS** | **NOT RUN — ENVIRONMENT GAP** |
| **AVD policy** | Max 4 farm-wide; never steal; companion last; release when done |
| **Locales** | en SOT + da, de, es, fr, it, nb, nl, pl, pt_BR, sv (+ regional). **No RTL** |
| **Theme** | NC `--color-*` + DutyCheck `--dc-*` |
| **Parts** | 47 queued → **46 accepted**, **1 env_gap** |
| **Lenses** | All six apply |

---

## Master Verdict Register — Round 6 (**exit_met**)

| Lens | Must-Fix Open | Must-Fix Verified | Absolute No-Go Open | Should-Fix (backlog) | Self-Critique Status |
|---|---|---|---|---|---|
| 1. Architecture | 0 | Zeus + roster rolling/grid/virt **accepted** wow=9 | 0 | GA suggest-mutex under held lock | ✅ critic-backend / critic-roster |
| 2. Security | 0 | GA + Momos **accepted** wow=9 | 0 | ICS Ops; Momos M-01 capacity seed | ✅ critic-backend |
| 3. Visual/Theme | 0 | theme + 14 settings **accepted** wow=9 | 0 | farm-load flake; theme matrix route coverage | ✅ critic-theme / critic-settings |
| 4. UX Simplicity | 0 | Rolling months + planner pages **accepted** | 0 | Soft planner UX notes in critics | ✅ critic-roster / critic-web-pages |
| 5. Translations | 0 | web-l10n + companion-i18n **accepted** wow=9 | 0 | FR “emplacement”; en.json key order | ✅ critic-theme / critic-companion-i18n |
| 6. E2E Proof | 0 | **46 accepted**; companion Detox critic re-run 14/14 EXIT=0 | 0 | store-screenshots skipped; iOS gap | ✅ all critics; iOS env_gap only |

**Exit condition:** **MET** — every part is `accepted` or `env_gap`; harsh critics wow≥9 with empty `must_fix`; AVD released after companion batch.

---

## Critic ledger (all ACCEPT wow=9)

| Critic | Parts | must_fix | should_fix |
|--------|------:|----------:|------------:|
| critic-backend.json | 3 | 0 | 2 |
| critic-roster.json | 3 | 0 | 3 |
| critic-web-pages.json | 7 | 0 | 4 |
| critic-settings.json | 14 | 0 | 5 |
| critic-theme.json | 3 | 0 | 6 |
| critic-print-employee.json | 4 | 0 | 4 |
| critic-api.json | 7 | 0 | 5 |
| critic-companion-i18n.json | 1 | 0 | 3 |
| critic-companion-detox.json | 5 | 0 | 3 |

Evidence: `docs/atlas/evidence/r6/`.

---

## Residual risk (should_fix backlog only)

- GA suggest-mutex under held lock; Momos M-01 capacity seed; ICS ops discipline  
- Theme matrix route coverage / farm-load flake on long HC settings  
- FR venue wording; en.json msgid order  
- Companion: store-screenshots not in R6 gate; iOS still ENVIRONMENT GAP  

Do not treat Round 5 as Round 6 proof; Round 6 proof is this register + `critic-*.json` + `evidence/r6/`.
