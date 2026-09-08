# DutyCheck Round 6 — API batch status

**Completed:** 2026-09-07T23:34:25Z  
**Overall:** accepted (critic wow=9; no AVD; no product must_fix)  
**Critic:** `critic-api.json` → ACCEPT  
**Critic evidence:** `r6-api-critic.txt` (exact-filter re-run + GA restore)

| Part | Verdict | Exit | Summary | Evidence |
|------|---------|------|---------|----------|
| api-roster-periods-assignments | accepted | 0 | PHPUnit 70/70 + GA | `r6-api-roster.txt`, `r6-api-ga-spot.txt`, `r6-api-critic.txt` |
| api-open-shifts-swaps | accepted | 0 | Swap\|OpenShift 27/27 | `r6-api-open-shifts-swaps.txt`, `r6-api-critic.txt` |
| api-self-service-signals | accepted | 0 | SelfService + extras 24/24 + GA | `r6-api-self-service*.txt`, `r6-api-ga-spot.txt`, `r6-api-critic.txt` |
| api-admin-governance | accepted | 0 | Access/Admin/Isolation/RateLimit 36/36 | `r6-api-admin.txt`, `r6-api-critic.txt` |
| api-mobile | accepted | 0 | MobileCompanionWire et al. 22/22 | `r6-api-mobile.txt`, `r6-api-critic.txt` |
| api-ical | accepted | 0 | IcalToken/PublicIcal narrow 9/9 | `r6-api-ical.txt`, `r6-api-critic.txt` |
| api-license | accepted | 0 | License 42/42 | `r6-api-license.txt`, `r6-api-critic.txt` |

## Critic notes

- Exact fixer filters re-run EXIT=0 matching counts (70/27/24/36/22/9/42).
- GA peer flake from `linked_user_id` seed drift → restored → ALL PASSED (should_fix: pin seed in `_ga_e2e.php`).
- Broader roster filter CancelVersionCas STALE_VERSION → should_fix only.
- Ical filter remains narrowed (`IcalToken|PublicIcal|…`) to avoid case-insensitive false positives.
