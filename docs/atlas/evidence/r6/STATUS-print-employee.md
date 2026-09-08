# DutyCheck Round 6 — Print / employee / needs-role / l10n batch

**Completed:** 2026-09-07T23:29:04Z  
**Overall:** proved (no AVD; no product fixes; legacy-safe)

| Part | Verdict | Exit | Summary | Evidence |
|------|---------|------|---------|----------|
| web-roster-print | proved | 0 | Route is **admin-only** (`requireAppAdmin`). NC system admin (`dc_atlas_planner` in `admin`) opened `/apps/dutycheck/roster/print?periodId=56` → printable shell (“Druckfähiger Dienstplan”). | `r6-print-employee-pw.txt`, `r6-print-employee-results.json` |
| web-my-roster | proved | 0 | `NC_EMPLOYEE_*` set in `tests/e2e/.env`; employee session reached `#dc-main-content` on `/apps/dutycheck/my-roster`. | `r6-print-employee-pw.txt` |
| web-my-absences | proved | 0 | Same employee session; `#dc-main-content` on `/apps/dutycheck/my-absences`. | `r6-print-employee-pw.txt` |
| web-needs-role | proved | 0 | Live no-role visit skipped (no password for `dc.review.noseat`). Contract: `OpenAccessModeContractTest` (2) + `AccessControlServiceTest` needsRole filter (1) OK. | `r6-needs-role-contract.txt` |
| web-l10n | proved | 0 | Parity-only batch: `check-l10n-parity.php` + placeholders + runtime all exit 0 (1122 keys). | `r6-l10n-parity.txt` |

## Notes

- Docker: `docker compose exec -T -u www-data -w /var/www/html/custom_apps/dutycheck nextcloud php …` from `nextcloud-dev/nextcloud`.
- Print is **not** planner-role enough by code (`PageController::rosterPrint` → `requireAppAdmin`); proof used NC admin membership, not a planner-only account.
- Employee routes were **not** env-skipped: `NC_EMPLOYEE_USER` / `NC_EMPLOYEE_PASS` present in e2e `.env` (Playwright loads it).
- No emulators; no product code changes.
