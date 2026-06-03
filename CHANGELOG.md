# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
