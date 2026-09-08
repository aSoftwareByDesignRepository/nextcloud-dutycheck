# DutyCheck Round 6 — companion Detox (AVD)

**Proved at:** 2026-09-08T05:32:13Z  
**AVD:** DutyCheck_Atlas_R2_API_33 (`emulator-5602`)  
**Command:** `npx detox test --configuration android.emu.debug --maxWorkers 1 e2e/smoke.e2e.js e2e/login-reachability.e2e.js e2e/home.e2e.js e2e/journeys.e2e.js`  
**Overall exit:** 0  
**Suites:** 4 passed / 4 total · **Tests:** 14 passed / 14 total · **Time:** 166.564 s  
**Evidence:** `r6-companion-detox.txt`  
**Product fixes:** none (legacy-safe; no product bugs revealed)

| Part | Status | Exit | Proof mapping |
|------|--------|------|---------------|
| companion-detox-suite | proved | 0 | All 4 suites PASS (smoke + login-reachability + home + journeys) |
| companion-auth-gates | proved | 0 | `login-reachability.e2e.js` PASS; home License gate + Unofficial server PASS |
| companion-home-today | proved | 0 | home “duty roster home” PASS; journeys Home/Week/Today tab paths PASS |
| companion-marketplace | proved | 0 | home Marketplace claim PASS; journeys Marketplace claim + pool swap PASS |
| companion-settings-stack | proved | 0 | journeys Settings → Absences / About / Sign out PASS |

## Suite detail

| Suite | Result | Exit |
|-------|--------|------|
| e2e/journeys.e2e.js (8 tests) | PASS | 0 |
| e2e/home.e2e.js (4 tests) | PASS | 0 |
| e2e/smoke.e2e.js (1 test) | PASS | 0 |
| e2e/login-reachability.e2e.js (1 test) | PASS | 0 |

## Critic handoff

- Status is **proved** only — not accepted until harsh critic.
- store-screenshots.e2e.js intentionally skipped (optional / flaky-slow).
- companion-i18n-theme left untouched (already accepted). companion-ios left env_gap.
