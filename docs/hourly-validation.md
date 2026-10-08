# Hourly quota validation · 2026-10-08

## Isolated checks

- Existing registry, provider adapters, diagnostics, native transport compatibility and Send Example checks passed.
- 57 quota checks covered priority rotation, all envelope recipients, shared-account limits, rolling-window expiry, failed/uncertain handoffs, idempotent finalization, corrupt storage, credentials redaction and native campaign rescheduling.
- Twenty concurrent PHP processes competed for seven slots; exactly seven obtained capacity.
- Twig rendered empty and populated registries with strict variables. The existing JavaScript contract checks passed.
- Checks ran with current locked dependencies and the Symfony 7.4 library versions installed on the target Mautic server. These checks used synthetic contracts, mocked HTTP and temporary files: no Mautic test kernel or database.

## Controlled operational validation

The existing plugin source and private configuration were backed up and verified before deployment. No schema change, migration, destructive test or fixture was run. Campaign execution was briefly held using its existing lock while the global transport changed to `multimail://auto`.

Four existing connections retained their credentials and fallback settings. Priorities were set to Reports SMTP → Resend API → Support SMTP → Resend SMTP. Limits initially use 100 recipients/hour. Resend API and SMTP share one account quota because both credentials belong to the same account. The first SMTP counter was seeded once with 59 actual, non-failed native Mautic sends recorded in the preceding hour; older sends expire normally.

The administrator screen saved an edited limit, refreshed counters without submitting credentials and changed the selected hourly history. Tablet (768px) and phone (390px) checks confirmed that tables scroll within their containers without widening the page.

Two controlled diagnostic messages went to the existing operator mailbox through the **global, real Mautic transport factory**. Reports SMTP accepted the first. A temporary cap then made Reports ineligible; Resend API accepted the second. Each corresponding acceptance counter increased by one, and the shared Resend counter changed in both API/SMTP rows. Provider message IDs were recorded privately as hashes. The normal 100/hour limit was restored and credentials equality verified before releasing campaign execution.

Provider acceptance is evidence of a successful handoff, not a delivery receipt or proof of arrival in the mailbox. All-capped rescheduling was verified through isolated native event contracts; live quota counters were not filled with synthetic traffic to trigger a production failure.

## Deployment persistence

Keep the registry, quota ledger and locks in the private shared directory across releases. Every application instance participating in a quota must use the same ledger and reliable filesystem locking. Separate hosts without shared locking require a central atomic ledger before quota enforcement can span them. Do not reset counters during deployment or rely on this plugin to count messages sent directly through another transport.
