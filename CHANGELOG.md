# Changelog

## 0.1.37 - 2026-07-31

### UX / navigation

- **Settings split into sub-pages (DeskCheck pattern).** The single long settings document is now 13 focused pages (`/settings/{section}`), each with its own title, lead, and sidebar sub-navigation entry (`aria-current` on the active one). `SettingsSectionCatalog` is the single source of truth; routes, controller, template dispatch, and client JS all derive from it and contract tests pin them together.
- **No dead bookmarks.** `/settings` 303-redirects to `/settings/access`; old `/settings#anchor` links are forwarded client-side (`settings-legacy-redirect.js`) to the owning sub-page with the fragment preserved, so the browser still scrolls to the section.
- The in-page “On this page” jump nav is gone (replaced by the sidebar sub-navigation); breadcrumb gains a Settings parent on sub-pages.
- **In-page settings chip bar** (DeskCheck parity) so sibling pages stay reachable when Nextcloud collapses `#app-navigation` on phones/tablets. Short `navLabel()` chips in the sidebar and chip bar; longer `label()` titles stay on the page H1.
- Access-page copy no longer says “section below” — it deep-links to Duty roles and Employees.

### Accessibility

- Muted copy nested in tinted callouts (`.dc-callout .dc-field__hint` et al.) is promoted to full-contrast ink — axe measured `#6b6b6b` on `#d6e7ef` at **4.19:1** (fails WCAG 1.4.3); CSS contract test locks the rule.

### i18n

- Locale catalogs repaired: six directory-picker strings translated into all 9 non-English locales, four stale keys dropped, key order normalized, regional variants (`de_DE`, `fr_FR`, …) re-mirrored; parity/placeholder/runtime checks green at 1031 keys × 10 locales.

### Tests

- New: `SettingsSectionCatalogTest`, `SettingsPagesContractTest` (cross-artifact drift), `SettingsTemplateRenderTest` (renders every partial; escaping + anchor checks), `settings-pages.test.mjs` (executes the redirect module: fail-closed on malformed payloads, prototype-pollution-safe hash handling), and `run-settings-pages-mutations.php` (catalog/dispatcher/JS/nav mutations — all killed).
- Playwright: axe smoke covers all 13 settings sub-pages plus redirect/anchor-forward/sidebar/chip-bar journeys; theme matrix pins `/settings/access`.
- License status strip: meter labels use full-contrast ink on `--color-background-darker` (axe caught `#999` on `#3b3b3b` at 3.93:1).
- Version lockstep **0.1.37**.

## 0.1.36 - 2026-07-29

### Accessibility / UX

- **Setup progress WCAG fix (NC34).** Done-step checkmarks no longer paint `--color-primary-element-text` (white) on `--color-success`. On Nextcloud 34, `--color-success` is a pale surface (`#D8F3DA`) — white-on-success measured **~1.18:1** (fails 1.4.11). Done status now uses `--color-success-text` on the success surface with an `--color-element-success` ring (same contract as status badges).
- **One next step.** Setup checklist collapses done rows, highlights only the current gate, and shows a single primary CTA. Misleading “each item links” copy removed. Quick start is suppressed while setup gates remain so onboarding is not duplicated.
- E2E fixture proves done-status contrast ≥ 4.5:1 under live theme tokens; JS + CSS contract tests lock the ink token.

## 0.1.35 - 2026-07-27

### Security / integrity

- Swap **transfer** rewrites `slot_key` for the recipient, bumps `version`, and CAS-guards donor `employee_id` + non-cancelled status (`ASSIGNMENT_TRANSFER_STALE`).
- Second pending swap on the same assignment is rejected (`SWAP_ALREADY_PENDING`).
- GDPR `purgeUser` also deletes `dc_company_members`, `dc_planner_locs`, and `dc_user_preferences` for the UID.

### Docs / honesty

- CORE-APP-PLAN §0 aligned with shipped Waves A–C + companion; GDPR §11.6 describes unlink/scrub (no phantom anonymize API).
- Version lockstep **0.1.35**.

## 0.1.34 - 2026-07-27

### Security / integrity

- Snapshot retention protects the **latest close tip per period** (not only still-closed `close_snapshot_id`), so close→reopen→prune cannot delete chain tips needed for the next close.
- Assignment **update** and **cancel** fail closed without `status` / `version` / `slot_key` (aligned with create); cancel always frees `slot_key`.
- Planner location `setScope` throws `SCHEMA_NOT_READY` when `dc_planner_locs` is missing (no silent no-op).

### Docs / honesty

- Roster SoT: employee×date grid + list (no overclaimed shift×date pivot); multi-company empty membership → Default company transitional catch-all documented in code.
- Version lockstep **0.1.34**.

### Tests

- `SnapshotRetentionServiceTest` reopen tip protection; planner scope fail-closed unit; integrity mutations for retention + update/cancel/scope gates.

## 0.1.33 - 2026-07-27

### Security / integrity

- Assignment **create** fail-closed when `status` / `version` / `slot_key` columns are missing (`SCHEMA_NOT_READY`) — aligned with update CAS.
- `EnsureDutyCheckSchema` verifies unique index `dc_asg_skey_uidx` (post-1017) and re-runs migrations when it is absent.
- `SchemaProbe::hasIndex()` for portable unique-index probes.

### Docs / honesty

- Planning SoT aligned: multi-company, planner location scope, bulk fill, snapshot retention prune (C2) vs audit append-only (no fake `audit_retention_days`).

### Tests / a11y

- Integrity mutations cover create fail-closed + repair index gate.
- Employee a11y smoke includes `/my-roster` (requires `NC_EMPLOYEE_*`).
- Playwright login fail-fast on “Wrong login or password”, with `loginWithFallback` / `plannerCredsCandidates` (E2E_* preferred over stale NC_ADMIN_*).
- `EnsureDutyCheckSchemaTest` seeds index probe cache; `SchemaProbeTest` covers `hasIndex`.
- Create-assignment guard unit tests seed `SchemaProbe` so consecutive QB mocks are not stolen by column probes (Infection-stable).
- Infection: security subset floor **54%** MSI (measured ~54–55%); broad suite **~66–67%** (see `infection*.json5`).

## 0.1.32 - 2026-07-27

### Security / integrity

- Soft-cancel **frees the assignment slot** via portable `slot_key` unique column (`a:…` active / `c:{id}` cancelled) so cancel→recreate no longer hits `ASSIGNMENT_DUPLICATE_SLOT`.
- Assignment updates **fail closed** when the `version` column is missing (`SCHEMA_NOT_READY`) — no CAS fail-open.
- `EnsureDutyCheckSchema` now verifies critical columns (`version`, `slot_key`, frozen thresholds, `min_headcount`) and re-runs migrations when they are absent.
- Version lockstep restored (`appinfo/version` = `info.xml` = `package.json` = **0.1.32**).
- Dark-theme WCAG contrast: theme-dependent `--dc-*` surface tokens resolve on `.dc-app` / `.dc-modal` (not `:root`).

### Tests

- `AssignmentSlotKeyTest`; integration `testCancelThenRecreateSameSlotSucceeds`.
- Integrity mutation gauntlet asserts slot-key + fail-closed CAS + repair column gate.

## 0.1.31 - 2026-07-27

### Added

- In-app **Support & Us** admin surface (Partner CTAs, locale-safe links) with unit coverage and mutation gauntlet.

## 0.1.30 - 2026-07-27

### Fixed

- Support & Us safer locales/URLs; render tests hardened.

## 0.1.29 - 2026-07-27

### Fixed

- Support & Us section styling and locale refinements.

## 0.1.28 - 2026-07-27

### Fixed

- Uninstall / upgrade-backup repair alignment with shared Nextcloud app standards.

## 0.1.27 - 2026-07-27

### Security / integrity

- Assignment updates **require** `expectedVersion` when the version column exists (`EXPECTED_VERSION_REQUIRED` → 422); null/omitted no longer silently coerces to `0`.
- Cancel uses status CAS (`neq cancelled`) so concurrent cancels cannot double-audit; sequential re-cancel stays idempotent.
- Bulk grid fill refuses templates without a location (no silent fallback to `locations[0]`).

### UX / accessibility

- Roster grid: filled cells are single focusable gridcells (no nested buttons); Space selects empty cells only; read-only periods announce instead of opening edit.
- Template `min_headcount` field in Settings; publish readiness surfaces `INTEGRATION_PUBLISH_STALE`.
- Companion: biometric + auto-lock toggles on Settings; My week reads/writes the shared offline roster cache.

### Tests

- Integration CAS close-out (stale race, required version, understaffed soft conflict).
- Integrity mutation gauntlet expanded (version-required, cancel CAS, bulk location, publish-stale UX).
- Playwright + axe WCAG 2.1 A/AA smoke (skips without `NC_*` credentials).


## 0.1.26 - 2026-07-27

### Security / integrity

- Assignment updates use optimistic locking (`version` CAS → `STALE_VERSION` 409) so concurrent edits cannot silently clobber each other.
- Periods freeze conflict thresholds at create time (`conflict_thresholds_json`); live policy changes no longer rewrite open/published history.
- GDPR user-delete scrub: roles, app-admin/allow lists, mobile seats, and employee `linked_user_id` are cleared (ledger rows stay for audit).
- Calendar-week hard/soft caps (ISO week) complement period totals; soft `break_too_short` and `understaffed_shift` (template `min_headcount`) added.
- Printable roster footer shows publish/close snapshot integrity hash when available.
- PHPUnit bootstrap no longer stubs Symfony `Command` when Nextcloud is bootstrapped (fixes container resolution of OCC commands).

### UX / accessibility

- Roster employee×day **grid** (ARIA `role="grid"`, keyboard arrows/Enter/Space) with list toggle and bulk fill from shift templates.
- Settings: **Privacy & words we use** section (GDPR honesty + glossary).
- Companion: `LICENSE_REQUIRED` maps to LicenseGate (not Unofficial); store review walkthrough rewritten for DutyCheck shifts.

### Tests

- Integrity close-out contract suite (CAS, freeze, GDPR, grid/print markup, migration 1014).
- Catalog assertions updated for new conflict messages; SchemaProbe caches seeded for new columns.


## 0.1.25 - 2026-07-26

### Security / integrity

- Snapshot retention protects the full `prev_snapshot_id` hash chain (close→reopen→close no longer breaks verify after prune).
- Open-shift approve accepts soft-conflict `acknowledgements` and returns `CONFLICT_ACK_REQUIRED` (planner can confirm; no marketplace dead-end).
- Qualifications: update, deactivate, attach upsert, and detach (ops can correct mistakes).
- Companion: AC-API.2 walls when `dutycheck.companion.min` is missing; P2 DutyCheck absence request when AZC is not effective; P3 A↔B colleague swap + location names on open shifts.

### Accessibility / UX

- Dashboard: page/section titles and breadcrumbs wrap instead of clipping on very narrow viewports (`min-width: 0` + `overflow-wrap: anywhere` on header text tracks).
- Dashboard: unavailable KPI values (`—` after a failed load) now carry an accessible "Not available" name; new string translated in all shipped catalogs.
- Dashboard summary and planning-status requests load in parallel (faster first paint, unchanged per-request error handling).
- `dashboard.js` fallback DOM helper honours the `attrs` prop, so `aria-hidden` markup survives even if `components.js` fails to load.
- App-wide: muted text prefers Nextcloud `--color-text-maxcontrast` (BudgetCheck parity); dialogs use theme-safe overlays; settings jump nav (“On this page”); scope strip is a definition list (no middot noise); text-style buttons keep 44×44 hit targets; entity listbox keeps a visible focus ring; license seat table no longer forces overflow on phones.
- All page scripts fail closed if `DutyCheckComponents`/`DutyCheckDom` is missing; catalog forms (employees/locations/absences) ignore double-submit while in flight.

### Security / integrity

- Period and absence transitions use status CAS (`PERIOD_STATUS_CONFLICT` / `ABSENCE_STATUS_CONFLICT` → 409) so concurrent publish/approve cannot silently double-apply.
- `COMPANY_MISMATCH` maps to HTTP 403 (was 400).

### Tooling

- `sync-l10n-from-runtime.php` keeps catalogs in the sorted key order that `check-l10n-parity.php` enforces, and mirrors base catalogs into regional variants (`de_DE`, `da_DK`, …); `regenerate-l10n-js.php` now emits variant `.js` files too.

### Tests

- Retention chain protection, open-shift soft-ack, qualification deactivate/detach, companion.min honesty, marketplace candidates.
- Dashboard: setup-state truth table + summary count mapping (`RosterServiceDashboardSummaryTest`), access-denial envelope, full template render contract (escaping, hidden baselines, landmarks), and a targeted mutation gauntlet (`tests/Mutation/run-dashboard-setup-mutations.php`, 10/10 mutants killed).
- App-wide: design-system CSS contract, settings TOC/section-id contract, CAS source contract, absence race behavioural test, HTTP mapping tests, mutation gauntlet `tests/Mutation/run-appwide-theme-cas-mutations.php`.


## 0.1.24 - 2026-07-26

### Security

- Open-shift **reject** fails closed when pending→open CAS loses to approve (no false “rejected” success).
- Dashboard / bootstrap summary counts are company-scoped for the acting planner.
- Shift templates reject foreign-company `locationId` on create/update.
- Companion claim / pool-swap / acknowledge use the offline mutation guard (no fake success offline).

## 0.1.23 - 2026-07-26

### Security

- Pool-swap approve creates the open shift **before** cancelling the assignment; cancel failure discards the open slot (no orphaned cancellations).
- MaintenanceCheck on-duty hook scopes results to the caller’s companies when multi-company is active.
- Open-shift create rejects location↔period company mismatches before insert.

### Tests

- Regression coverage for pool-swap create-before-cancel, open-shift location company gate, on-duty company scope, and conflict-ack company assert.

## 0.1.22 - 2026-07-26

### Security

- Public iCal feed marked `#[PublicPage]` so calendar clients work without a Nextcloud login.
- iCal errors no longer distinguish missing employees from bad tokens (always `403 ICAL_TOKEN_INVALID`).
- Open-shift **approve** rolls back orphan assignments when the pending→claimed CAS loses a race.
- Swap **review** CAS-locks `pending` before roster mutation; failed mutations revert to pending.
- Multi-company IDOR: period audit, acknowledge stats, CSV export, print view, and conflict-ack assert company access.
- Companion My week screen uses FLAG_SECURE like Home / Marketplace / Shift detail.

## 0.1.21 - 2026-07-26

### Security

- Open-shift **claim** now fails closed on hard conflicts (`ASSIGNMENT_OVERLAP`, `ABSENCE_CONFLICT`, `QUALIFICATION_MISSING`) before CAS — matches Wave B2 “hard conflicts block claim”.
- Mobile 402 wire codes distinguish `SEAT_LIMIT_EXCEEDED` from `NO_MOBILE_SEAT` (middleware + MobileController).
- Removed 1×1 placeholder screenshots 15–17 from the app tree.

### Fixed

- Companion live API path no longer doubles `/apps/dutycheck` (now `appBase` + `/api/mobile/…`, AZC-parity).
- Mid-session 402 handler maps server wire codes (`LICENSE_REQUIRED`, `LICENSE_EXPIRED`, `NO_MOBILE_SEAT`, `SEAT_LIMIT_EXCEEDED`).
- Bootstrap `urls.myRosterWeb`; Play listings retain ArbeitszeitCheck honesty; client `dutycheck.companion.min` wall (`app_outdated`).
- FLAG_SECURE on Home / Shift detail / Marketplace; foreground roster refresh after 60s; ProjectCheck leftover i18n/types stripped.

## 0.1.20 - 2026-07-26

### Security

- **C1 complete:** `company_id` on templates, qualifications, open shifts, swap requests, and absences; list/create/claim/export fail closed across tenants. Single Default company remains unrestricted for legacy installs.
- Pool-swap open-shift create always receives `CompanyService` (no silent stamp to Default company `1`).
- License panel DOM/CSS IDs renamed `pc-license-*` → `dc-license-*` (ProjectCheck leftover cleanup).

### Added

- Settings companies membership management (create / members) — already in 0.1.19 foundation; isolation now covers marketplace + catalogs.
- Companion marketplace screen: claim open shifts + request pool swaps.
- Companion **push registration** via Nextcloud Notifications + push proxy (opt-in; degrades if notifications app missing). Bootstrap reports `pushAvailable`.
- Roster “Add assignment” is **omitted** when the period cannot accept writes (prefer omit over disable).
- Companion **Shift detail** + **My week** screens; `FLAG_SECURE` via `expo-screen-capture` on login / license gate / session unlock.
- Qualifications Settings attach/require forms use named selects (not raw numeric IDs).
- License settings CSS (`license-settings.css`) for WCAG-friendly meter, seats table, and confirm modal.

### Fixed

- License honesty: panel copy matches `MOBILE_APP_STATUS=available` (no “coming soon”).
- `appinfo/version` synced to `0.1.20`.
- Publish / swap / acknowledge unit coverage for fan-out, ownership, and period gates.
- Eliminated `addToAssertionCount` dummy asserts in license/middleware/scope/backup suites; backup tests use `dc_*` tables and reject `pc_projects` via catalog gate.
- Template location + planner scope Settings use named selects/checkboxes (not raw numeric IDs).
- VendorPublicKey test-seed comment corrected to the shared Check-family harness seed.
- Staff swap UI: accessible dialog + `GET /api/my/swap-candidates` (no raw Employee ID prompts; planner-only catalog stays gated).
- Companion Detox mock API rewritten for duty roster/ack/marketplace; E2E smoke no longer expects ProjectCheck `home.logTime`.
- Vendor key config prefers `dcVendorPublicKeyB64` (legacy `pcVendorPublicKeyB64` still accepted).

## 0.1.19 - 2026-07-26

### Added

- **Assignment update and cancel** while a period is open (recomputes conflicts; cancelled shifts leave publish snapshots and My roster).
- **Employee shift acknowledge** on published/closed assignments, with period acknowledge stats for planners.
- **Shift templates** CRUD and **copy-from-previous-period** (dry-run or apply with audit `PERIOD_COPY_APPLIED`).
- **Publish notifications** (Nextcloud Notifications + Activity) to linked employees — no colleague PII in the body.
- **Configurable conflict thresholds** (period totals; honest labels replace misleading “weekly” copy).
- **Qualifications**, **open shifts**, and **swap requests** (Wave B staff self-service).
- **DutyCheck Mobile license (DTY2)** — seat management, companion bootstrap/envelope, 402 gate on `/api/mobile/*` only (web stays free).
- **Soft-cap approach notifications** (opt-in, rate-limited) and **MaintenanceCheck on-duty read hook** (feature-flagged).
- **Snapshot cold-archive retention** (protects close snapshots of still-closed periods).
- Planner UX: edit/cancel assignments, template picker, copy-period dry-run/apply, staff acknowledge %.
- **Staff open-shift claim** (CAS-first → **pending**, planner **approve/reject**) works on **published** periods; swap A↔B or pool with notifications to both parties.
- **Planner location scope** Settings UI + cancel/update/open-shift IDOR enforcement (empty scope = legacy unrestricted).
- **Qualifications** Settings catalog + attach/require forms.
- **Activity provider** for publish events; bootstrap reports real ArbeitszeitCheck effective + absences URL.
- **HR roster-minutes CSV export** (opt-in, audited enable — no salary math).
- Companion **P1 Expo scaffold** (`mobile/dutycheck`) + `@dutycheck/licensing` DTY2 Ed25519 verify.
- Website lifecycle copy corrected to **open → published → closed**; PHP badge 8.2–8.5.

### Fixed

- Open-shift approve never marks `claimed` without a linked assignment id (`createdAssignmentId`).
- Late-change notifications when published assignments are cancelled or transferred.
- Wave **C1** company/workspace foundation (`dc_companies`) — single Default company = legacy behaviour.
- **C1 isolation:** list/create/update stamp + filter by membership when multi-company is active; period/assignment/open-shift/swap mutations assert company access; Settings UI for companies + members.
- Mobile P2: offline roster read cache, AZC absences deep-link, marketplace companion routes; P3 capabilities advertised with `/api/mobile` swap/open-shift endpoints.

### Security

- Companion APIs require Basic auth + valid `DTY2` seat; browser session web routes never return license 402.
- Acknowledge is self-only (IDOR closed); cancelled assignments never surface on My roster.

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.17 - 2026-06-12

### Fixed

- **Data loss after Nextcloud upgrade:** `UninstallDropTables` preserves tables and settings on disable; full cleanup runs only on app removal.

## 0.1.16 - 2026-06-04

### Fixed

- **Employee and location saves no longer hit the wrong URL or silently empty the list.** `js/common/api.js` now resolves every app-relative path through `OC.generateUrl` for GET, POST, PUT, and DELETE (mutations previously skipped this and could 404 on hosts that require `/index.php/`). After a successful save, the Employees and Locations pages reload the catalog from the server instead of trusting a stale empty `data` array.
- **Updates use POST as well as PUT** (`/api/employees/{id}`, `/api/locations/{id}`) so reverse proxies that block PUT still persist edits.
- **Incomplete database schema** returns `SCHEMA_NOT_READY` (503) with a clear message instead of a generic failure when `dc_employees` (or other tables) are missing.
- **German translations** for the dashboard “Setup progress” checklist and related setup strings (were still showing English on `de` / `de_DE` locales).

## 0.1.15 - 2026-06-04

### Fixed

- **Install and upgrade path hardened.** `appinfo/info.xml` now declares MySQL/PostgreSQL under `<dependencies>` (required by `MigrationService` for correct schema checks), and **`EnsureDutyCheckSchema`** runs on install and post-migration to recreate any missing tables and fail loudly in `occ upgrade` logs instead of leaving a half-installed app with no UI feedback. Schema repair uses `OC\DB\Connection` for `MigrationService`, matching core `occ upgrade`.
- **Dashboard setup progress.** Planners see a persistent “Setup progress” checklist (schema, employees, locations, open period) with direct links until planning is possible; incomplete database setup shows a critical alert and an audible error message.
- **Integration and policy errors** use targeted messages in `messaging.js` (peer app missing/disabled/too old, sync throttled, legacy absences, access list required) instead of a generic “Something went wrong”.
- **Settings integration load failures** now surface via the shared error handler (toast + live region), not only a silent banner.
- **Navigation boot failures** are logged to `nextcloud.log` instead of being swallowed in `Application::boot()`.

### Added

- **`tests/Integration/UpgradeRepairIntegrationTest.php`** and **`EnsureDutyCheckSchemaTest`** to guard the production upgrade container path.

## 0.1.14 - 2026-06-03

### Fixed

- **Roster "Create assignment" form layout.** On the tablet/desktop grid the start- and end-time fields were placed in columns 2–3, leaving a gap in the first column and pushing the break field out of alignment. Start now sits in column 1 and end in column 2, so the day/time row lines up cleanly with the rest of the form.

## 0.1.13 - 2026-06-02

### Fixed

- **Nextcloud 32 compatibility — employees, roster, absences and the ArbeitszeitCheck integration no longer crash.** Database reads called `IResult::fetchAllAssociative()` / `fetchAssociative()`, which are only available since Nextcloud 33. On Nextcloud 32 this raised `Call to undefined method OC\DB\ResultAdapter::fetchAllAssociative()` and broke creating employees (the catalog reload after insert), the roster API (`GET /api/roster`), period/absence/conflict lookups and the ArbeitszeitCheck mirror. All result access now uses the stable `IResult::fetch()` / `fetchAll()` API (available since Nextcloud 21), and a static test guards against any future use of the Nextcloud 33-only result methods.
- **Settings "Allowed people / groups / app administrators" picker.** The user directory lookup is now available to planners (not only app administrators), skips backend lookups for queries shorter than two characters, and the Employees page no longer eagerly loads the whole directory on open. This keeps the autocomplete responsive and the policy form saveable. The settings page now explains the two-step flow (type, then pick from the list), shows inline search status, supports keyboard selection, and gives audible feedback when someone is added.

### Changed

- **Settings access-control form** — clearer hints under each search field, visible search status while typing, Enter/arrow-key selection in result lists, and a busy state on save so administrators get immediate confirmation.

## 0.1.12 - 2026-06-01

### Changed

- **Mobile-first responsive layout.** The stylesheet now builds up from a small-screen base (`min-width: 480px` / `768px` breakpoints) instead of patching a desktop layout downward. Shell, cards, page header, filter and form grids reflow cleanly from phone to desktop, and `overflow-x: clip` prevents the stray horizontal scrollbar on narrow viewports.
- **Tables collapse to cards on small screens.** Data tables render as stacked, labelled cards below the tablet breakpoint (row headers and `data-cell` labels) and switch back to a normal table on wider screens, keeping rosters and lists readable on phones (WCAG 2.1 AA reflow).
- **Tighter page header** with a smaller icon and a dedicated actions area so primary actions stay reachable on mobile.

### Security

- **Hardened CI workflow and refreshed the Composer lock file** to pull in the patched Symfony release (CVE fix), keeping bundled dependencies current.

## 0.1.11 - 2026-06-01

### Fixed

- **Employee display name auto-fills when linking a Nextcloud account** (UI + API). Picking a user fills the name field only when it is still empty; editing an existing employee never overwrites a stored name. The server derives the name from the linked account when the field is omitted, so direct API calls cannot bypass validation.
- **Employee form field order** — account search first, then display name (matches the natural link-then-confirm workflow).
- **Roster assignment and employee saves work on Snap and reverse-proxy setups.** Mutations now use `application/x-www-form-urlencoded` (the same transport Nextcloud core expects) instead of JSON-only bodies, with the CSRF token duplicated in the body when proxies strip custom headers. This fixes silent save failures that showed only the generic “Something went wrong” toast with no `nextcloud.log` entry.
- **`createAssignment` transaction scope** — conflict refresh commits before reloading roster data; rollback is guarded with `inTransaction()` so a failed save never masks the real error.
- **Acknowledgement payloads** from HTML forms are normalised server-side (`ApiMutationParams::acknowledgements`).
- **Assignment form errors** stay visible in the inline feedback region (not toast-only) and the active period is re-synced from the switcher on every save attempt.

## 0.1.10 - 2026-06-01

### Fixed

- **Roster form only offers employees who can actually be scheduled.** The API now ships approved-absence blocks (DutyCheck + ArbeitszeitCheck mirror) for the active period; the UI picks a **date first**, then lists only employees **not on approved leave** that day, with a live count of hidden names. Overlapping shifts are caught **before save** with a plain-language warning.
- **Default period selection** opens the newest **open** planning period instead of a read-only one.
- **Roster assignment save is reliable end-to-end.** `createAssignment` now validates required IDs before work begins, runs insert + conflict refresh in a **database transaction** (no half-saved shifts), and maps missing fields to explicit API codes (`PERIOD_ID_REQUIRED`, `EMPLOYEE_ID_REQUIRED`, `LOCATION_ID_REQUIRED`) instead of opaque failures.
- **API client surfaces real errors.** `js/common/api.js` treats `ok: false` JSON as an error even on unusual HTTP statuses, normalises error codes consistently, and retries CSRF recovery whenever a fresh token is returned (not only when it changed).
- **Soft-conflict acknowledgement on the roster page** no longer falls through to a generic message when conflict metadata is sparse; the modal defaults to `rest_time_violation` and lists the detected issues.
- **409 handling** in `messaging.js` no longer mis-labels conflict-acknowledgement or integration conflicts as “someone else changed this entry”.
- **Employee save** sends a proper boolean `active` flag; `INVALID_DISPLAY_NAME` / `INVALID_ACTIVE_FLAG` show targeted messages.
- **Proactive CSRF refresh** on page load (`js/common/session.js`) reduces first-save failures on long-lived tabs.
- **Unhandled API faults are logged** to `nextcloud.log` via `ApiJsonErrorResponse` while still showing a safe message in the UI.

### Changed

- **Roster “Create assignment” form** uses a three-step layout (day → who/where → times), step badges, inline hints, and an accessible feedback region (WCAG 2.1 AA, responsive grid).
- **Plain-language planning checks:** conflict badges read **Must fix** / **Confirm to continue** (not “hard/soft”); the conflict panel is titled **Planning checks**; Save-time confirmation points to that section; absence hints link to the **Absences** page.
- **Dashboard, Periods, and Absences** use the same wording via shared `js/common/conflict-labels.js` (planning status pulse, publish readiness line, German l10n); Absences quickstart and planner form no longer say “hard conflict”.
- **`scripts/sync_l10n.py`** now mirrors `de` → `de_DE` so German (Germany) locale files stay current (636 strings, no stale “hard/soft conflict” copy).

## 0.1.9 - 2026-05-29

### Fixed

- **Mutations no longer fail with an opaque `REQUEST_FAILED` after the CSRF token rotates.** The shared API client (`js/common/api.js`) now transparently refreshes the request token from `/csrftoken` and retries a write **once** on `412` — the same recovery `@nextcloud/axios` performs for the rest of the Nextcloud frontend. This fixes "Add employee" (and every other create/update) failing on long-lived or multi-tab sessions, where the failure left **no entry in `nextcloud.log`** (CSRF rejections are handled in middleware and are never logged). Retrying is safe because a 412 is rejected before any database write occurs.
- **Clear, localized error messages** (`js/common/messaging.js`): expired tokens/sessions now tell the user to reload, and internal codes (`REQUEST_FAILED`, `INTERNAL_ERROR`, …) are never shown raw. German translations added.
- **Editing or deactivating an employee whose linked Nextcloud account was deleted no longer fails.** `RosterService::updateEmployee` only re-validates the linked account when it actually changes; an unchanged, now-missing link stays manageable so the record is never frozen. A new (or changed) link to a missing account is still rejected.

### Changed

- App Store listing copy refreshed for broader appeal (`appinfo/info.xml`: summary + long description, EN + DE).

## 0.1.8 - 2026-05-25

### Added

- **Searchable IANA timezone picker** on the locations page: `js/common/timezone-picker.js` with keyboard navigation, live filtering, and accessible listbox semantics; backed by `TimezoneCatalog` and a new read-only catalog API (`CatalogApiController`).
- **`OCA\DutyCheck\Repair\UninstallDropTables`** wired in `appinfo/info.xml` so disabling the app drops all dutycheck tables, migration rows, and app config.

### Changed

- **`TimezoneCatalog`:** expanded IANA coverage and unit tests (`TimezoneCatalogTest`); roster/location flows use the shared picker instead of a plain text field.
- **Locations UI:** timezone field uses the new picker component; styling in `css/app.css` for the combobox pattern (WCAG-friendly focus and contrast).

### Bumped

- **Nextcloud `max-version`:** `33` (latest stable major).

## 0.1.7 - 2026-05-21

### Fixed

- App Store `info.xml`: use `https://` for author homepage, website, and donation URL (matches ProjectCheck; store schema requires `https://.+`).

## 0.1.6 - 2026-05-21

### Fixed

- App Store `info.xml`: comply with schema element order (`screenshot` → `donation` → `dependencies`), cap screenshots at 10, remove non-schema `optional-dependencies` and `keywords` elements.

## 0.1.5 - 2026-05-21

### Added

- App Store listing: seventeen screenshots (`dutycheck-screenshot-01.png` … `17`) referenced from `appinfo/info.xml`; slots 15–17 use placeholder assets for future captures.
- `<donation>` link (Software by Design) in `info.xml`.

### Changed

- Project URLs use `nextcloud.software-by-design.de`.
- ArbeitszeitCheck integration hardened (mirror delete helper, access control, reconciliation job/command, expanded unit and integration tests).
- CI: PHPUnit workflow and Docker-based test target in the release `Makefile`.

### Fixed

- Nextcloud compatibility: `max-version` aligned to 33 (latest stable server).

## [0.1.4] - 2026-05-10

- Initial public packaging: App Store metadata (`info.xml`), `LICENSE`, release `Makefile`, and documentation.
- Placeholder screenshot asset for store listing (replace with real UI captures before marketing).
- Harden repository defaults: expanded `.gitignore` for keys, certificates, and local build/signing output.
