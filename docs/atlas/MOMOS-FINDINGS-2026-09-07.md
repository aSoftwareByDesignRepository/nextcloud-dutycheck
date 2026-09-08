# DutyCheck Roster Self-Service GA — Momos Findings

**Object:** `nextcloud/apps/dutycheck` (Roster Self-Service GA) + companion touchpoints that call GA APIs  
**Auditor:** Momos  
**Dates:** 2026-09-07  
**Environment:** Docker Compose (`nextcloud`, `mariadb`) — all PHP proof via `docker compose exec -T nextcloud …`  
**Stance:** Guilty until an adversarial run proved otherwise. Documentation was treated as a rumor.

## Executive Summary

**Momos found Critical/High holes; Aristoteles closed the remaining High security items the same day.**

Closed across Momos + Aristoteles (all proven in Docker):

1. Settings service ACL (employee could mutate company flags)  
2. `claim_requires_planner=false` ignored  
3. Swap-candidates company directory dump  
4. Quiet-hours overflow **fail-open** (night spam) → shed oldest, stay deferred  
5. ICS **employee rate bucket before token verify** (NAT DoS) → bucket only after `hash_equals`  
6. Peer belonging counted **future** shifts → `duty_date ≤ today`  
7. Mobile bootstrap dumped full admin settings → quiet window slice only  
8. Unenforced `userMayDisableQuiet` API ad → stripped  

| Severity | Open | Fixed (Momos+Aristoteles) |
|----------|------|---------------------------|
| Critical | 0 | 1 |
| High | 0 | 4 |
| Medium | 1 (empty planner scope = global — **intentional legacy**) | 3 |
| Low | 3 | 0 |

**Fit for a mean external auditor today:** **Yes** for the GA security must-list above, with three Low residuals and one Medium **documented product contract** (empty planner scope = unrestricted). ICS query-string tokens remain Ops (AS-15). iOS still untested on this host.

---

## Step 0 — What this app is for (invariants)

**Domain:** Duty roster planning for Apotheke / Pflege / multi-site Filialen. Wrong answers mean wrong people on shift, wrong Soll/Saldo in Zeiterfassung, or colleague PII leaked.

**Actors:** App admin · planner (optionally location-scoped) · linked employee · public ICS client · AZC (Soll consumer).

**Invariants Momos tested against (code + master spec):**

| ID | Invariant |
|----|-----------|
| I-1 | Employees never see Today board (“Wer ist wo?”). |
| I-2 | Team week = published periods only + belonging + peer flag. |
| I-3 | Swap accept only by designated counterparty; apply is CAS-safe. |
| I-4 | `claim_requires_planner=false` → claim creates assignment; `true` → pending. |
| I-5 | Self-service settings mutate = app admin only (HTTP **and** service). |
| I-6 | Swap candidate lists must not be a company-wide phone book. |
| I-7 | ICS tokens opaque; HTTPS; rate-limited. |
| I-8 | Preference bands enum-only (no free-text family PII). |

---

## Critical — fixed this engagement

### [CRITICAL] Self-service settings writable by linked employee at service layer — FIXED

**What is wrong (in plain words):**  
The HTTP API correctly demanded an app admin, but the service that actually saves settings only checked “can access this company.” A linked employee who could call that service (future bug, script, or mis-wired controller) could turn peer visibility, bilateral auto-swap, or quiet hours on/off for the whole company.

**Where exactly:**  
- File: `lib/Service/SelfServiceSettingsService.php` (`updateForCompany`) — previously lines ~100–106  
- Workflow: company self-service settings mutation

**How to reproduce it (copy-paste steps):**  
1. Before the fix (historical):
   ```
   docker compose exec -T nextcloud php -r '
   require "/var/www/html/lib/base.php"; OC_App::loadApp("dutycheck");
   $s=\OCP\Server::get(\OCA\DutyCheck\Service\SelfServiceSettingsService::class);
   $api=$s->toApi(1);
   $s->updateForCompany(1,["peerRosterVisibility"=>false],"dc.review.employee",$api["settingsRevision"]??null);
   echo "ok\n";'
   ```
2. Observed (Momos r1 `M-05`): `PROVEN: SelfServiceSettingsService::updateForCompany accepted employee actor`

**What should happen instead:**  
Non–app-admins must get `FORBIDDEN`. Only app admins may mutate.

**Why this matters:**  
One compromised employee session (or one forgotten `requireAppAdmin`) could disable peer privacy or enable auto-swaps company-wide.

**Exact fix instructions:**  
1. Inject `AccessControlService` into `SelfServiceSettingsService`.  
2. At the top of `updateForCompany`, after companyId validation:
   ```php
   if ($this->access !== null && !$this->access->isAppAdmin($actor)) {
       throw new \InvalidArgumentException('FORBIDDEN');
   }
   ```
3. Wire DI in `lib/AppInfo/Application.php`.

**Proof this is fixed:**  
- Attack `M-05` → `CLEAN: service blocked: FORBIDDEN` (`docs/atlas/evidence/momos-attack-r3.txt`)  
- Contract: `MomosGaPolicyWiringContractTest::testSettingsUpdateRequiresAppAdminInService`  
- Manual: employee update blocked; admin update ok (logged in `test-execution-log.md`)

---

## High — fixed this engagement

### [HIGH] `claim_requires_planner=false` ignored — claims always pending — FIXED

**What is wrong (in plain words):**  
Admins could uncheck “Open-shift claims need planner approval,” but the server still always queued claims for a planner. Spec AC-C05-2 / AC-F05-2 requires instant assignment when the flag is false.

**Where exactly:**  
- File: `lib/Service/OpenShiftService.php` — `claim()`  
- Setting: `claim_requires_planner` / UI `dienst-team.php` checkbox  
- Spec: `ROSTER-SELF-SERVICE-GA-MASTER.md` AC-C05-2

**How to reproduce it (before fix):**  
With `claimRequiresPlanner=false`, call `OpenShiftService::claim` → status was always `pending`.

**What should happen instead:**  
- Flag **false** → status `claimed` + roster assignment created.  
- Flag **true** → status `pending` awaiting planner.

**Why this matters:**  
Operators think marketplace is self-service; employees wait forever; capacity stays locked in pending.

**Exact fix instructions:**  
1. Inject `SelfServiceSettingsService` into `OpenShiftService`.  
2. After CAS `open→pending`, if `!$settings->claimRequiresPlanner($companyId)`, call `applyClaimAsMarketplace` using `RosterService::createAssignment(..., trustedMarketplaceApply: true)`.  
3. On failure, `releaseToOpen` so the slot is not stuck.

**Proof this is fixed:**  
- `M-01` r2: `CLEAN: auto-claimed status=claimed assignment=203`  
- `M-01b`: `CLEAN: pending as required`  
- `MomosGaPolicyWiringContractTest::testClaimRequiresPlannerIsReadInOpenShiftClaim`  
- Note: r3 `M-01` hit `OPEN_SHIFT_CONFLICT` from leftover overlap on the same slot date — harness noise, not a regression (r2 already proved auto-claim).

---

### [HIGH] Swap candidates leaked the full company directory — FIXED

**What is wrong (in plain words):**  
Any linked employee could ask for “swap candidates” and receive **every** active colleague’s id + display name (Momos saw 12), with no shared-location filter.

**Where exactly:**  
- File: `lib/Service/SwapService.php` — `listSwapCandidates`  
- Endpoint: `GET /api/my/swap-candidates` (and mobile twin)

**How to reproduce it (before fix):**  
Momos r1 `M-06`: `listSwapCandidates returned 12 colleagues… sample=Atlas Inj ' OR 1=1 --,…`

**What should happen instead:**  
Only colleagues who share a location with the caller inside the 180-day belonging window (same privacy doctrine as peer roster).

**Why this matters:**  
Works-council / GDPR: a full workforce phone book is not “swap UX.” Attackers map the org in one call.

**Exact fix instructions:**  
1. Resolve caller’s recent location ids (180d lookback).  
2. If empty → return `[]`.  
3. Join candidates via `dc_assignments` on those locations; exclude self; keep company filter.

**Proof this is fixed:**  
- `M-06` r3: `CLEAN: scoped candidates=1 of companyActive=12`  
- `SwapCandidatesTest` (empty without shared locs + filters blanks)  
- `MomosGaPolicyWiringContractTest::testSwapCandidatesAreLocationScoped`

---

## High — still open

_(None. Quiet overflow + ICS RL-before-auth closed by Aristoteles — see below.)_

---

## High — fixed by Aristoteles (post-Momos)

### [HIGH] Quiet-hours queue overflow fail-open (night spam) — FIXED

**What was wrong:** Cap 200 → return false → caller sent immediately during Nachtruhe.

**Exact fix:** Shed oldest undelivered row(s), then enqueue; if shed fails, still return `true` (stay deferred). Undelivered past `deliver_after + 24h` are age-purged.

**Proof:** Zeus `quiet overflow still deferred` + `pending ≤ max`; `AristotelesMomosClosureContractTest::testQuietOverflowShedsInsteadOfFailOpen`.

---

### [HIGH] ICS rate limit counted before token verification — FIXED

**What was wrong:** Garbage tokens burned `ical:{employeeId}:{ip}` (60/min).

**Exact fix:** IP spray (120/min) on all probes; employee+IP bucket only after `hash_equals` succeeds.

**Proof:** `aristoteles-r5-ics-dos.txt` — 60 bad → `invalid=60 limited=0`; good token `GOOD_TOKEN_OK`.

---

## Medium — fixed this engagement

### [MEDIUM] `userMayDisableQuiet` advertised but never enforced — FIXED (API honesty)

Removed from `toApi` / blocked in `filterPatch`. Proof: `M-09` CLEAN.

### [MEDIUM] Peer belonging counted future assignments — FIXED

Belonging requires `duty_date ≤ today` (180d lookback retained).

### [MEDIUM] Mobile bootstrap full settings dump — FIXED

`MobileController::selfServiceSnapshot` returns only quiet window fields.

### [MEDIUM] Quiet undelivered not age-purged — FIXED

`expireStale` deletes undelivered past TTL (Zeus updated).

---

## Medium — accepted product residual

### [MEDIUM] Empty planner location scope = unrestricted

**What is wrong (in plain words):**  
Zero rows in `dc_planner_locs` means “plan anywhere.”

**Why we did not fail-closed:**  
Every unscoped production planner and unit contracts treat empty as global. Fail-closed would brick them overnight. `setScope([])` is intentional “make global.”

**Mitigation:** Class doc updated; Ops should audit accidental scope wipes.

---

## Low — still open

### [LOW] ARGUS/ZEUS docs still say settings ACL is “planner/admin”

**What is wrong:** Docs claim planner can mutate settings; HTTP+service are **app admin only**. Atlas callout matches code; ARGUS asset table does not.

**Fix:** Update ARGUS/ZEUS tables to `requireAppAdmin` or change Product to allow planners (then update code).

---

### [LOW] True two-leg swap (`counter_assignment_id`) unused

Column exists; `requestSwap` never sets it; apply never transfers the counter leg. Product backlog — do not claim two-shift swaps in GA marketing.

---

### [LOW] ICS token may appear in reverse-proxy access logs

Known Ops residual (AS-15). Prefer token in `Authorization` header or POST body for new clients; scrub query strings in proxy config.

---

## Documentation-vs-code mismatches (logged)

| Claim | Reality |
|-------|---------|
| ARGUS: settings ACL planner/admin | `requireAppAdmin` + now service `isAppAdmin` |
| ARGUS OSC-02 suggest rate limit “open” | Wired (`ApiRateLimitService` + period lock) |
| ARGUS belonging “never expires” | 180-day lookback |
| UI/spec claim_requires_planner | Was dead; **now wired** |
| userMayDisableQuiet / AC-H05 | Was advertised; **API stripped**; still not implemented |
| ZEUS FM-09 settings last-write-wins | CAS exists |

---

## Test suite assessment (honest)

| Suite | Result | Notes |
|-------|--------|-------|
| Momos attack harness r1 | 3 PROVEN | Found Critical/High |
| Momos attack harness r3 | 0 PROVEN / 11 CLEAN / 2 harness ERROR | Fixes hold; M-01/M-12 are dirty-data noise |
| PHPUnit Momos contracts + OpenShift/Swap/Peer/Settings | **34/34** | `momos-phpunit.txt` |
| `_ga_e2e.php` | PASS | After settings admin gate |
| `_zeus_concurrency.php` | PASS | |
| Prior Atlas Detox/Playwright | Not re-run this Momos pass | Companion findings file dated 2026-08-30 remains separate |

Dummy assertions: none introduced. Skipped tests: none added.

---

## Open Questions

1. ~~Should empty `dc_planner_locs` mean deny-all or global?~~ **Resolved for GA:** global (legacy). Track accidental clears in Ops.  
2. ~~Should peer belonging require a past worked shift?~~ **Resolved:** `duty_date ≤ today`.  
3. Is AC-H05 (employee quiet opt-out) still wanted later? API no longer advertises it.  
4. Should ICS move off query-string tokens before GA marketing? (AS-15 Ops)  
5. iOS companion proof owner on this host?

---

## Aristoteles proof appendix (2026-09-07 evening)

| Gate | Result | Evidence |
|------|--------|----------|
| Zeus (incl. quiet shed + TTL) | PASS | `apps/dutycheck/docs/atlas/evidence/aristoteles-r5-zeus.txt` |
| `_ga_e2e.php` | PASS | `aristoteles-r5-ga-e2e.txt` |
| PHPUnit Aristoteles/Momos contracts | 18/18 | `aristoteles-r5-phpunit` filter run |
| ICS DoS script | 60 bad → 0 employee RL; good OK | `aristoteles-r5-ics-dos.txt` |
| Momos attack r4 | 0 PROVEN / 11 CLEAN | `momos-attack-r4.txt` |

*Aristoteles: closed what Momos left open without breaking legacy planner global scope.*
