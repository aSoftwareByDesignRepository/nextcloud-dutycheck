# DutyCheck Round 6 — companion-i18n-theme

**Proved at:** 2026-09-07T23:38:05Z  
**Verdict:** proved (needs_avd=false; no AVD / Detox / emulator-lock)  
**Overall exit:** 0

| Gate | Command | Result | Exit |
|------|---------|--------|------|
| Locale key parity | `npm run i18n:parity` | PASS 341 keys × 11 locales | 0 |
| WCAG contrast (light+dark) | `npm run a11y:contrast` | ALL PASS (21 checks) | 0 |
| Jest i18n+theme | `npx jest --runInBand` localeParity + usedKeys + azcUiParity | 3 suites / 8 tests passed | 0 |

## Evidence

- Full stdout: `r6-companion-i18n-theme.txt`

## Scope notes

- Locales covered by parity script/tests: en, de, fr, es, da, nl, it, pl, sv, nb, pt.
- Contrast auditor covers LIGHT and DARK palettes from companion theme tokens (not Detox UI).
- `azcUiParity` asserts Check-family light token contract + `@arbeitszeitcheck/ui` wiring.
- No product code changes required.

## Critic handoff

- Status is **proved** only — do not treat as accepted until harsh critic runs.
- Soft gaps (not blockers for this gate): contrast script does not separately section `highContrast` mode; theme mode switching is not a dedicated Jest suite beyond AZC light parity.
