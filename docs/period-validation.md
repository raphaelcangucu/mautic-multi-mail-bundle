# Daily/monthly quota validation · 2026-10-08

## Implementation and isolated checks

Hourly, UTC-calendar-day and UTC-calendar-month caps are evaluated together before reserving every envelope recipient. Shared account groups inherit the lowest positive cap independently per period. Monthly aggregates survive the existing 24-hour minute-history cleanup; the old private ledger upgrades in place without resetting counters. Quota groups cannot be changed to bypass charged capped periods or confirmed provider blocks.

The standalone suites passed without a Mautic test kernel, database or live HTTP calls:

- 75 new checks: leap-year UTC midnight/month/year boundaries, conservative pending reservations, completion-time attribution, confirmed rejection release, uncertainty accounting, idempotent baseline imports, legacy ledger upgrade, corrupt-state refusal, strict validation, automatic rotation, Resend daily/monthly refusal blocks and diagnostics.
- Twenty concurrent PHP workers competed for seven recipients separately under daily and monthly caps with an unlimited hourly cap; exactly seven were accepted in each run.
- 58 existing hourly/campaign checks, including a full monthly reschedule interval; the transport, registry, provider, native compatibility and example-send suites also passed.
- Twig strict-variable checks and JavaScript diagnostic/three-period counter-refresh checks passed. The CI matrix includes all suites across PHP 8.2–8.5 and locked/installed-Mautic Symfony versions.

## Production operational validation

Changed runtime files matched their previously deployed hashes before a fresh backup was taken and verified. The private registry and ledger were backed up with 0600 permissions. No schema operation, migration, fixture or database-backed test ran. Campaign dispatch was briefly held through its existing lock and released after normal settings were restored.

The actual Resend **macro** dashboard confirmed Free transactional limits of 100/day and 3,000/month, with 2/day and 10/month used at activation. Those verified counts were seeded once without lowering recorded charges. Resend API and SMTP retain their common `resend-macro` quota group. Both have 100/hour, 100/day and 3,000/month configured. Existing DreamHost accounts retain 100/hour and no locally configured day/month cap; their calendar counts begin from retained ledger history, not a claim of historical provider reconciliation. Credentials, From/Reply-To, priorities, fallbacks and `multimail://auto` were preserved.

Three controlled messages to the existing operator mailbox used the **actual global Mautic transport factory**:

1. Reports SMTP accepted one message. A temporary daily cap, set from its real usage plus one, was then reached.
2. Resend API accepted the next message. A temporary monthly cap, set from its actual observed usage plus one, was then reached.
3. Support SMTP accepted the next message, skipping both capped accounts before handoff.

Each expected connection gained exactly one acceptance. Normal caps were restored in a `finally` block and credential equality verified. No fake traffic or synthetic production counts were used to fill a quota. Provider IDs were recorded privately as hashes. These are provider acceptance checks, not proof of mailbox delivery.

The browser saved edited daily/monthly limits, verified the shared effective values in both Resend rows, then restored 100/day and 3,000/month. Counter refresh updated all three periods without submitting credentials. The shared Resend usage became **3/100 today** and **11/3,000 this month**. Desktop/tablet (768px)/phone (390px) checks confirmed no horizontal page overflow; wide tables scroll within their own containers. Container/Twig cache rebuild and PHP-FPM reload succeeded. The maintenance holder exited and normal campaign dispatch resumed.

![Shared hourly, daily and monthly capacity](screenshots/period-capacity.png)

## Provider usage boundary

The existing Resend sending key returned HTTP 401 from `GET /usage`, which requires full access. No credential permission was expanded. This release counts Multi Mail traffic after the verified baseline and handles documented Resend API quota refusals by blocking the shared account until its UTC reset. It does not continuously synchronize external applications or inbound email usage. A block is not inferred from an ordinary request-rate 429, timeout or undocumented error body.

Sources: [Resend account limits](https://resend.com/docs/knowledge-base/account-quotas-and-limits), [Resend usage API](https://resend.com/changelog/account-usage-api).
