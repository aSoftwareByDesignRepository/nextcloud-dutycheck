# DutyCheck Round 6 — Settings batch status

**Completed:** 2026-09-07T23:27:18Z  
**Overall:** proved 14 / failed 0 (no AVD; no product fixes)

| Part | Verdict | Exit | Summary | Evidence |
|------|---------|------|---------|----------|
| web-settings-access | proved | 0 | `#dc-main-content` + no axe critical | `r6-settings-pages-pw.txt` |
| web-settings-duty-roles | proved | 0 | `#dc-main-content` + no axe critical | `r6-settings-pages-pw.txt` |
| web-settings-planning | proved | 0 | `#dc-main-content` + no axe critical | `r6-settings-pages-pw.txt` |
| web-settings-companies | proved | 0 | `#dc-main-content` + no axe critical | `r6-settings-pages-pw.txt` |
| web-settings-conflicts | proved | 0 | `#dc-main-content` + no axe critical | `r6-settings-pages-pw.txt` |
| web-settings-shift-templates | proved | 0 | `#dc-main-content` + no axe critical | `r6-settings-pages-pw.txt` |
| web-settings-qualifications | proved | 0 | `#dc-main-content` + no axe critical | `r6-settings-pages-pw.txt` |
| web-settings-planner-scope | proved | 0 | `#dc-main-content` + no axe critical | `r6-settings-pages-pw.txt` |
| web-settings-operations | proved | 0 | `#dc-main-content` + no axe critical | `r6-settings-pages-pw.txt` |
| web-settings-dienst-team | proved | 0 | `#dc-main-content` + no axe critical | `r6-settings-pages-pw.txt` |
| web-settings-integration | proved | 0 | `#dc-main-content` + no axe critical | `r6-settings-pages-pw.txt` |
| web-settings-privacy | proved | 0 | `#dc-main-content` + no axe critical | `r6-settings-pages-pw.txt` |
| web-settings-license | proved | 0 | `#dc-main-content` + no axe critical | `r6-settings-pages-pw.txt` |
| web-settings-support | proved | 0 | `#dc-main-content` + no axe critical | `r6-settings-pages-pw.txt` |

## Suite totals

| Suite | Result | Evidence |
|-------|--------|----------|
| Playwright `settings-pages-smoke.spec.js` | **17/17 passed** (14 sections + chip/nav @390px + sidebar active) | `r6-settings-pages-pw.txt` |
| PHPUnit `SettingsPagesContractTest` | **14/14 OK** (137 assertions) | `r6-settings-pages-phpunit.txt` |

## Notes

- Planner auth via existing E2E storageState; routes `/apps/dutycheck/settings/{section}`.
- Chip bar asserted at viewport 390×844 (planning → privacy hop); sidebar `aria-current` on companies.
- Spec reuses a11y-smoke / web-pages-smoke patterns (`#dc-main-content`, axe critical=0, theme CSS wait).
- No legacy-safe product fixes required — all pages already healthy.
