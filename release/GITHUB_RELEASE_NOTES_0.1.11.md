## DutyCheck 0.1.11

Fixes roster assignment and employee saves that failed with a generic “Something went wrong” message (especially on Snap and reverse-proxy setups), with no entry in `nextcloud.log`.

### Fixed

- API mutations use `application/x-www-form-urlencoded` with CSRF token in header and body (proxies that strip custom headers no longer drop save payloads).
- `createAssignment` transaction scope: conflict refresh commits before roster reload; safe rollback when not in a transaction.
- Acknowledgement payloads from HTML forms normalised server-side (`ApiMutationParams`).
- Assignment form errors stay in the inline feedback region; active period re-synced from the switcher on save.

### Also in 0.1.10 (included if upgrading from older builds)

- Date-first employee list (hides people on approved leave that day).
- CSRF refresh on long-lived tabs; clearer error messages (EN + DE).
- Three-step roster form and plain-language planning checks (“Must fix” / “Confirm to continue”).

**After upgrade:** hard-refresh the browser (Ctrl+F5). Confirm DutyCheck shows version **0.1.11** under Apps.
