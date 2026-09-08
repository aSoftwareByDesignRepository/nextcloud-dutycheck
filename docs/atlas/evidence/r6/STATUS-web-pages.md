# DutyCheck Round 6 — Web pages batch status

**Completed:** 2026-09-07T23:25:39Z  
**Overall:** proved (no AVD; no product fixes)  
**Playwright:** `tests/e2e/web-pages-smoke.spec.js` — 8 passed, EXIT=0 (`#dc-main-content` + no axe critical)

| Part | Verdict | Exit | Summary | Evidence |
|------|---------|------|---------|----------|
| web-periods | proved | 0 | PW periods + Periods page PHPUnit 18/18 | `r6-web-pages-pw.txt`, `r6-web-periods-phpunit-narrow.txt` |
| web-today-board | proved | 0 | PW /today + TodayBoard filter 2/2 | `r6-web-pages-pw.txt`, `r6-web-today-phpunit.txt` |
| web-dashboard | proved | 0 | PW /dashboard + setup-progress-a11y EXIT=0 | `r6-web-pages-pw.txt`, `r6-web-dashboard-setup-a11y.txt` |
| web-patterns | proved | 0 | PW /patterns + RotationPattern 2/2 | `r6-web-pages-pw.txt`, `r6-web-patterns-phpunit.txt` |
| web-absences-planner | proved | 0 | PW /absences | `r6-web-pages-pw.txt` |
| web-employees | proved | 0 | PW /employees | `r6-web-pages-pw.txt` |
| web-locations | proved | 0 | PW /locations | `r6-web-pages-pw.txt` |

## Notes

- Spec visits each planner route as authenticated planner (`auth.js` / storageState), workers=1 retries=1.
- Broad `--filter Period` had 1 error in `PublishNotificationServiceTest` (null query builder) — not page-load; narrow Periods* suite is green.
- `DashboardTemplateRenderTest` still expects removed `dc-scope-strip` / "Start of week" copy; live dashboard loads and setup-progress a11y passes — left as stale unit asserts (no legacy product restore).
- Setup-progress live CTA tests skipped (setup already complete on instance).
