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

---

# Round 7 — Standby/On-Call (Rufbereitschaft) surface audit

**Session date:** 2026-10-16  
**Scope:** new standby feature (backend `kind='standby'`, `dc_standby_categories`,
migration `Version1021Date20261020120000`, planner UI, self-service ack, iCal,
companion `StandbyPill`/roster sections) + regression proof of surrounding surface.  
**exit_met:** **true** (all Must-Fix verified; Android Detox + iOS = environment gaps, honestly declared)

## Master Verdict Register — Round 7

| Lens | Must-Fix Open | Must-Fix Verified | Absolute No-Go Open | Should-Fix (backlog) | Self-Critique Status |
|---|---|---|---|---|---|
| 1. Architecture | 0 | 3 (ARCH-01, ARCH-02, concurrency probes) | 0 | `PERIOD_NOT_FOUND` oracle consistency | ✅ fresh re-run |
| 2. Security | 0 | 2 (role gates, flag/invalid/foreign probes) | 0 | — | ✅ peon re-created + re-probed |
| 3. Visual/Theme | 0 | token audit, 26/26 axe, contrast 15–17.6:1 | 0 | — | ✅ contrast recomputed on effective bg |
| 4. UX Simplicity | 0 | 1 (UX-01 missing error copy) | 0 | — | ✅ l10n runtime+parity green |
| 5. Translations | 0 | 1 (TR-01 FR copy) + parity | 0 | — | ✅ mobile jest re-run 53/382 |
| 6. E2E Proof | 0 | full fresh suite 164/166 (2 intentional skips, 0 fail) | 0 | Android Detox + iOS env gaps | ✅ fresh run after last fix |

**Exit condition:** MET — zero Must-Fix/No-Go open, zero open conflicts,
zero unanswered blocking questions. Full suite re-ran fresh **after** the
last fix (roster.js wiring reorder), 164 passed / 2 skipped / 0 failed (20.2m).

## Round 7 findings

| ID | Tier | Finding | Status |
|----|------|---------|--------|
| ARCH-01 | Must-Fix | `#dc-roster-add-assignment` visible+enabled but listener registered post-boot-await → silent dead clicks (CDP `getEventListeners` = 0). Fix: delegated listener moved pre-await. | 🟢 VERIFIED |
| ARCH-02 | Must-Fix | **Partial fix widened the race**: early-wired trigger opened the modal while ALL its internal listeners (Clear, submit, kind toggle, eligibility, template, copy panel, period switcher) stayed post-await → 2 atlas-dialogs-filters e2e failures. Fix: every static-control listener now wired pre-await; only the boot loads remain post-await. | 🟢 VERIFIED (focused tests pass; full suite clean; `EnsureCalendarMonthContractTest` adjacency preserved) |
| UX-01 | Must-Fix | `STANDBY_ACKNOWLEDGE_DISABLED` (422) had no `knownCodeMessage` copy — flag-toggle race would surface bare error. Fix: en+de copy added, l10n regenerated. | 🟢 VERIFIED |
| TR-01 | Should-Fix | fr.json `a11yLabel` "Duty d'astreinte" (English leak) → "Astreinte". | 🟢 VERIFIED |
| SEC-note | Should-Fix | `PERIOD_NOT_FOUND` distinguishes missing vs foreign period — pre-existing roster contract the UI depends on; consistent, not a standby regression. | backlog |
| ENV-01 | env_gap | Android `DutyCheck_Atlas_API_36` failed to boot twice (farm RAM-saturated; other agents' emulators not touched per iron law). Mobile coverage: 53 suites/382 jest + typecheck + mockApi contract. | NOT RUN — ENVIRONMENT GAP |
| ENV-02 | env_gap | iOS simulation impossible on Linux host. | NOT RUN — ENVIRONMENT GAP |

## Round 7 proofs executed this session

- **Concurrency (live):** 6 concurrent identical standby creates → exactly 1
  winner (id 3634, `kind='standby'`), 5× `STANDBY_OVERLAP`/409; DB ground
  truth = 1 row; retry-after-win → 409; cancel frees slot (re-create
  succeeded). 24h equal-time standby accepted. Standby excluded from
  target-hours minutes + export queries (code + schema verified).
- **Security (live):** occ-created unprivileged user → uniform
  `403 INSUFFICIENT_ROLE` on all standby endpoints (self-critique re-ran
  fresh: recreated user, re-probed, deleted). `STANDBY_DISABLED` 422 when
  flag off; `INVALID_KIND` 422; `STANDBY_CATEGORY_NOT_FOUND` 404;
  `STANDBY_SHIFT_COLLISION` 422 on kind-immutable update attempt.
  (`e2e_employee` is an NC system admin — earlier "bypass" probe was an
  env artifact, invalidated.)
- **DB standards:** `check-custom-apps-db.sh --gate=critical` 0 issues;
  migration guards/short-names/smallint clean; `dc_standby_categories`
  registered in `UninstallDropTables`; repair-step DI check OK (3 steps,
  51 factories).
- **Visual:** token-first CSS (hex only as `var()` fallbacks); 26/26 axe
  tests 0 violations; standby badge/kind-card contrast 15.04–17.6:1;
  44px touch targets.
- **E2E:** fresh full run — 164 passed / 2 skipped (intentional) /
  0 failed, 20.2m, evidence `evidence/r7/r7-lens6-full-e2e.txt`.
- **Companion:** 53 jest suites / 382 tests green after fr.json fix;
  typecheck clean (prior run this session).
- **PHP:** unit 1035 tests/5962 assertions; integration 78/156; mutation
  gauntlets all killed; Infection security subset 61% MSI ≥ 54% floor.
- **l10n:** runtime+parity green — 1356 runtime strings, 1426 keys × 11
  locales; real de translation for new copy.

Evidence: `docs/atlas/evidence/r7/` · bug doc
`documentation/dutycheck/bugs/20261016-add-assignment-dead-click-during-boot.md`
· learned class `post_await_listener_dead_window` added to
`.cursor/atlas-farm-v3/checklists/LEARNED-REGRESSIONS.md`.

## Round 7 residual risk

- Android Detox + iOS native coverage remain environment gaps — mobile
  standby UI rests on jest/contract coverage only.
- `PERIOD_NOT_FOUND` existence-oracle consistency (pre-existing, Should-Fix).
- Farm-level disk pressure (~3 GB free after cleanup) limits artifact
  retention on future runs; emulator farm saturation blocks companion
  native proof.

---

# Round 8 — Adversarial re-verification + localization deep-quality

**Session date:** 2026-10-16  
**Scope:** independent re-derivation of R7 verdicts (fresh probes, not summary
re-reads) + the gap R7's parity gate could not see: key *existence* was green
while msgstrs were still English.  
**exit_met:** **true** (TR-02 fixed + proven; env gaps unchanged)

## Master Verdict Register — Round 8

| Lens | Must-Fix Open | Must-Fix Verified | Absolute No-Go Open | Should-Fix (backlog) | Self-Critique Status |
|---|---|---|---|---|---|
| 1. Architecture | 0 | fresh concurrency re-run (1 winner; post-cancel race 1 winner) | 0 | `PERIOD_NOT_FOUND` oracle | ✅ fresh probe, DB ground truth |
| 2. Security | 0 | CSRF: missing+forged `requesttoken` → 412; `OCS-APIRequest` bypass → 412 | 0 | — | ✅ fresh negative probes |
| 3. Visual/Theme | 0 | contrast recomputed 15.04:1 on effective bg | 0 | — | ✅ fresh measurement |
| 4. UX Simplicity | 0 | carried (no surface change this round) | 0 | — | — |
| 5. Translations | 0 | 1 (TR-02: 36–37 standby strings were English fallbacks × 10 locales) | 0 | tone/native review by speaker | ✅ formal gate re-run green × 11 |
| 6. E2E Proof | 0 | focused post-translation run 12/12 (standby-planner + web-pages-smoke) | 0 | Android Detox + iOS env gaps | ✅ fresh run after last fix |

## Round 8 findings

| ID | Tier | Finding | Status |
|----|------|---------|--------|
| TR-02 | Must-Fix | **Parity gate blind spot**: key-existence parity was green while 36–37 standby msgstrs were untranslated English in every non-EN locale incl. German (primary market) — the DE UI shipped English standby copy. Fix: real translations for ~50 standby/category/ack/conflict keys across da/de/es/fr/it/nb/nl/pl/pt/pt_BR + regional variants (`pl_PL`, `de_DE`) synced. | 🟢 VERIFIED (formal-quality gate OK × 11, runtime parity 0 missing) |
| TR-03 | Must-Fix (checker) | Allowlist gaps turned the fixed gate red on *legit* identicals: `'24 h'` (universal unit) and de loanword msgids (`Bereitschaft`, `Reaktionszeit`, `Rufbereitschaft`, `Standort`). Added to `L10N_ALLOW_EXACT` / `L10N_DACH_ALLOW_PATTERNS` (de-scoped, not global). | 🟢 VERIFIED |
| TR-04 | Should-Fix | pl informal register: `'ci użytkownicy'` (demonstrative false-positive, rephrased) and `'Twoja'` → `'Pańska'`. | 🟢 VERIFIED |
| TEST-01 | Should-Fix | `standby-planner.spec.js` save-button regex had aspirational de alternates (`Zuordnung speichern`, capital-S) that never matched real German (`Einsatz speichern`, lowercase verb). Now `/Save assignment\|[Ss]peichern/`. | 🟢 VERIFIED (12/12 focused run) |
| ENV-03 | env_gap | `dc_atlas_planner` `core lang` was left `de` by the r8 render probe; restored to `en` (suite's designed env). | 🟢 fixed |

## Round 8 proofs executed this session

- **l10n deep-quality:** `check-l10n-formal-quality.php --app=dutycheck` →
  OK for all 11 locales (1426 keys each); runtime parity 0 missing
  (`check-all-apps-l10n-runtime.php`, dutycheck row). Generated `*.js`
  bundles regenerated and in sync (incl. `pl_PL`, `de_DE` variants).
- **E2E:** 12/12 pass post-translation (`standby-planner` 4 + `web-pages-smoke`
  8); the pre-fix run caught TEST-01 as a real failure — regex, not UI
  (snapshot showed correct "Einsatz speichern" button).
- **Live render (de):** planner UI confirmed rendering real German
  (`htmlLang=de`, "Einsatz speichern", "Rufbereitschaft" gridcell labels)
  via failing-test page snapshot.
- **Security (fresh):** mutating POST without/with forged `requesttoken` →
  412; `OCS-APIRequest` header does not bypass → 412.
- **Concurrency (fresh):** re-ran probe — exactly-one-winner + cancel frees
  slot + post-cancel race exactly-one-winner; DB verified clean after.

Evidence: `docs/atlas/evidence/r8/r8-lens5-l10n-quality.txt`.

## Round 8 residual risk

- Same env gaps as R7: Android Detox / iOS NOT RUN (farm RAM saturation).
- Translation *quality* (tone/register beyond formal/informal regexes) rests
  on scripted review; a native-speaker pass is the honest ceiling for prose
  polish — flagged as Should-Fix owner-note, not a blocker.
- Full web suite was **not** re-run end-to-end in R8 (data-only l10n delta +
  env restore); R7's 164/166 fresh run stands, focused post-translation run
  is green. A full fresh re-run is cheap insurance if time/disk allow.

---

# Round 9 — Full-suite re-proof + companion native coverage

**Session date:** 2026-10-17  
**Scope:** close the R8 residual (full suite after last fix) and retry the
Android gap — the farm was idle this session (0/4 emulators, 30 GB RAM).  
**exit_met:** **true**

## Master Verdict Register — Round 9

| Lens | Must-Fix Open | Must-Fix Verified | Absolute No-Go Open | Should-Fix (backlog) | Self-Critique Status |
|---|---|---|---|---|---|
| 1. Architecture | 0 | carried + category-delete guard re-read | 0 | delete-vs-assign TOCTOU (narrow, degrades to NULL via LEFT JOIN) | ✅ code + live re-verified |
| 2. Security | 0 | secrets scan clean; IN_USE guard + uniform company predicates | 0 | — | ✅ fresh probes |
| 3. Visual/Theme | 0 | carried | 0 | — | — |
| 4. UX Simplicity | 0 | carried | 0 | — | — |
| 5. Translations | 0 | carried (R8 gate green) | 0 | native-speaker prose review | — |
| 6. E2E Proof | 0 | **Android Detox 14/14 real emulator + full web suite** | 0 | iOS (needs macOS) | ✅ fresh runs |

## Round 9 findings

| ID | Tier | Finding | Status |
|----|------|---------|--------|
| ENV-01 | env_gap → CLOSED | Android `DutyCheck_Atlas_API_36` AVD was reclaimed between sessions; recreated (system-image survived), booted via `emulator_acquire`, debug+androidTest APKs built (Gradle, 1m55s), Detox on real emulator: **14/14 pass** (smoke, journeys incl. acknowledge flow, home incl. license gate, login). | 🟢 VERIFIED — real run |
| ENV-04 | env (fixed) | Full suite showed 3 `e2e_employee` login failures — root cause was a **parallel arbeitszeitcheck playwright run** whose `admin-users-profile` spec rotates the shared `e2e_employee` password mid-flight (occ reset→200→401 while they ran→200 after). Re-ran the 3 tests in a quiet window: all pass. Not an app defect. | 🟢 VERIFIED |
| ARCH-03 | Should-Fix | Category `delete()` checks `inUseCount` then deletes — TOCTOU vs a concurrent assign leaves a dangling `standby_category_id` (LEFT JOIN renders NULL; cosmetic, narrow window). Backlog: wrap check+delete in a transaction or FK-guard. | backlog |

## Round 9 proofs executed this session

- **Android (real):** `detox test --configuration android.emu.debug` on
  emulator-5600 — smoke 1/1, journeys 8/8, home 4/4, login-reachability 1/1.
- **Web (fresh, end-to-end):** full suite 161 passed / 2 skipped / 3 failed
  where all 3 were the parallel-suite fixture race; focused re-run after the
  sibling suite finished: 4/4 green → effective **164/2/0**.
- **Secrets:** regex scan of shipped JS + generated l10n bundles — no literals.
- **Fixture repair:** `e2e_employee` password reset to `.env` SSOT; verified
  200 via OCS + form login path.

Evidence: `docs/atlas/evidence/r9/r9-detox-and-e2e.txt`.

## Round 9 residual risk

- iOS remains NOT RUN — no macOS host exists (hard environment gap).
- Shared-fixture races are inherent while parallel agents run e2e on the same
  NC instance; the azc suite mutates `e2e_employee` password — a future quiet
  window or fixture namespacing (per-app users) would remove it.
- Emulator was left running for idle-reclaim (dead-holder lock self-heals at
  ~10 min stale grace; no other session's resources were touched).
