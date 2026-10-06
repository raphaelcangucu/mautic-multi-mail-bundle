# Multi Mail for Mautic

Independent Mautic 7.2 / Symfony Mailer 7.4 plugin (v0.3.0) for multiple SMTP and API connections, with an ordered fallback chain per connection. No Inbox dependency, no core patch, no campaign/use-case routing and no automatic credential expiry.

## Install

Extract `MauticMultiMailBundle` into Mautic's `plugins/` directory. The release ZIP bundles official Symfony bridges for Amazon SES, Resend, Mailgun, SendGrid, Postmark, Brevo, Mailjet, MailerSend and Mandrill; SparkPost and SMTP2GO use the existing Symfony HTTP client with plugin-owned adapters; existing framework dependencies retain precedence. Register this plugin and rebuild the container and configured Twig cache (`MAUTIC_TWIG_CACHE_DIR`) using your deployment workflow. ZIPs preserve timestamps: refresh template timestamps if the Twig cache is stored outside the cleared kernel cache. There are no entities, migrations or plugin tables. On installations with custom schema-install subscribers, register only this plugin's metadata and avoid a broad reload of unrelated plugins.

Administrator screen: `/s/mail-connections`. Connections are stored as private JSON outside the web root: `<deployment>/shared/inbox-mail-private` for atomic releases, or `<project-parent>/<project-name>-multimail-private` otherwise. The atomic-release directory name preserves existing credentials; direct checkouts use separate storage per installation. Directory permissions are 0700, files 0600. Include this private directory in your protected backups; never publish it in ZIPs or Git.

## Supported providers

SMTP, Amazon SES, Resend, Mailgun, SendGrid, Postmark and Brevo remain available. Version 0.3.0 adds:

| Provider | Credentials and account requirements | Adapter / notes |
| --- | --- | --- |
| Mailjet | API Key + Secret Key from the same account; verified sender/domain | Official `symfony/mailjet-mailer`, Send API v3.1. Every recipient result must report success. |
| MailerSend | API token with sending access; verified domain | Official `symfony/mailer-send-mailer`. Empty HTTP 202 responses require `x-message-id`; suppression warnings are handled. The standard bridge does **not** send custom tracking/unsubscribe headers. Use SMTP for messages requiring these headers. |
| Mandrill / Mailchimp Transactional | Transactional API key; authenticated sending domain | Official `symfony/mailchimp-mailer`. Every recipient must be sent, queued or scheduled; rejected/invalid recipients cannot be mistaken for acceptance. |
| SparkPost | Transmissions write API key; account region US or EU | Fixed regional HTTPS endpoint, full RFC 822 MIME and explicit delivery envelope. SparkPost manages the return path. Extra open/click tracking is disabled so Mautic links are retained. |
| SMTP2GO | API key with `email/send` permission; verified sender/domain | Fixed HTTPS endpoint, text/HTML, Reply-To, supported custom headers, base64 attachments and CID images. `fastaccept=false` requires actual acceptance counts. At most 100 envelope recipients and at least one To recipient. |

All five appear in **New connection**, use the existing private credential store, and become available to **Test sending**, native **Send example** and per-connection reserves after saving a real account. Their addition does not create accounts, change the global transport, edit existing connections or choose a new fallback order. HTTP redirects are disabled for these API calls. SparkPost/SMTP2GO requests are capped at 20 MiB of serialized JSON, including attachments; their adapters never fetch remote attachment URLs. Provider-specific file/size limits still apply.

Success validation runs before an official bridge can treat an HTTP 200/202 as acceptance. Diagnostic results retain a safe provider message ID and HTTP status. A partial response, missing identifier, malformed JSON, suppression warning, timeout or HTTP 5xx stops the reserve chain. SparkPost HTTP 422 may already include accepted recipients and is always treated conservatively. A fully rejected business response is identified in the standalone diagnostic; it does not widen the existing campaign fallback policy beyond 401/403/429. This is acceptance validation, not bounce processing or a delivery receipt.

Setup references: [Mailjet](https://dev.mailjet.com/docs/email-api/send-api-v31/send-basic-email), [MailerSend](https://developers.mailersend.com/api/v1/email), [Mandrill](https://mailchimp.com/developer/transactional/api/messages/send-new-message/), [SparkPost](https://developers.sparkpost.com/api/transmissions/), [SMTP2GO](https://developers.smtp2go.com/reference/send-standard-email).

## Native Mautic sending

### Test a connection and send native examples

Each saved connection has **Test sending**. Choose a saved connection and one recipient in `/s/mail-connections`; the diagnostic uses that connection's saved From and Reply-To. It does not change the global Mautic configuration. The standalone diagnostic never invokes the registry fallback chain; a native DSN keeps its own explicitly configured composite. The result distinguishes acceptance, confirmed refusal and uncertain handoff. Acceptance is not a delivery receipt: check the recipient's inbox and spam. Do not retry an uncertain result without checking for delivery. Tests are administrator-only, protected by CSRF, optimistic revision checks and a ten-second session cooldown. No provider credentials or debug transcripts are returned.

For administrators, the native email **Send example** modal now requires an explicit sending choice: a saved connection, or the current Mautic transport. Selecting a connection preserves native email rendering and the chosen transport receives Mautic’s From, Reply-To, recipient envelope and attachments. API providers reassemble messages according to their capabilities; see the provider notes below. Only that connection's configured fallback applies; an unavailable or failed selection never silently uses the global transport. The selection applies only to this example and does not change campaign routing. Use a provider that authorizes the email's Mautic sender.

The connections page shows the active global transport and marks a saved connection as active only when its ID exactly matches the configured `multimail://` DSN. Registering a connection alone still does not switch the global transport. No arbitrary connection is automatically promoted.

For Resend API diagnostics the result retains the provider's email ID and HTTP response code. Known error names are allowlisted; raw API bodies, exceptions, credentials and recipients are never included in the result. HTTP 400/404/405/422 is a rejected diagnostic; network failures, malformed successful responses and HTTP 5xx remain uncertain and are never retried through another connection. The campaign fallback policy is unchanged. A successful POST confirms acceptance, not inbox delivery. A sending-only Resend key can send and return an ID but cannot list/retrieve email history (`401 restricted_api_key`); delivery reconciliation requires separately authorized read access or a webhook integration.

The latest completed diagnostic is retained in the operator's session for that connection and registry revision, so reloading after a lost browser response can recover it without sending again. It is not a persistent delivery/audit history and contains no recipient, message content or credentials.

Implementation uses a native Symfony named transport appended after the original transports, a form extension and Mautic's pre-send event. The original default transport and optional batching/bounce interfaces remain intact. Internal routing headers survive a Messenger queue and are removed before provider handoff. No Mautic core file is patched.

Edit a connection to obtain its identifier. In Mautic's email transport configuration use scheme `multimail`, host `<connection-id>` and empty user/password/port: `multimail://<connection-id>`. Saving the connection registry does not activate the global transport. Activation is an explicit operator decision. Multi Mail passes the configured From, Reply-To, recipient envelope and original message to the chosen transport. SMTP and SparkPost carry full MIME; the other APIs reassemble content and headers according to their adapters and account features. All configured providers must authorize the sender used by Mautic.

Applications using the injected native Mautic mail helper/transport factory can reuse this transport. Symfony's static `Transport::fromDsn()` does not discover registered plugin factories; use the application's injected factory instead. Existing custom login transports continue using their current configuration until explicitly switched.

### Preserve any installed transport

Choose **Mautic · transporte nativo / DSN** to store the original DSN of any adapter already installed in Mautic, including SMTP options, provider bridges and third-party factories. The full DSN is private; a blank edit preserves it. When selected through `multimail://<connection-id>`, the plugin delegates to Mautic's existing factory and returns the exact original transport object. It does not wrap that object, change its options or duplicate its events. Optional Mautic batching, bounce and unsubscription interfaces remain present when the original adapter implements them.

For a native connection use the original transport's own `failover(...)` or `roundrobin(...)` DSN when appropriate. Native connections are not mixed into Multi Mail's independent fallback graph, which would hide adapter-specific capabilities and send outcome information. Recursive references to `multimail://` are rejected. A bridge must already be installed; this mode does not claim that every Symfony provider is bundled.

The plugin's registered factory accepts only `multimail`; all original DSN schemes and registered factories continue resolving through Mautic as before. Existing global configuration stays unchanged. Third-party integrations that inspect the global DSN text directly or implement additional custom configuration still require their original configuration and their own integration validation; returning the same transport does not invent compatibility for undocumented plugin behavior.

## Fallback

Each connection may point to one reserve; reserves may have their own reserve. Cycles and removal of a referenced connection are rejected. Credentials remain private and blank edits preserve them. Changes use optimistic revisions and an atomic private write.

SMTP requires TLS. Failures before DATA acceptance or explicit final 4xx/5xx refusal allow fallback. API authentication/permission/rate-limit refusals (401/403/429) allow fallback. A timeout, uncertain handoff, malformed successful response, HTTP 5xx or recipient/payload rejection stops the chain to avoid duplicate delivery. Provider exceptions/debug transcripts are not propagated. Existing Mautic retry settings still apply; this plugin does not provide a cross-job idempotency ledger or provider delivery reconciliation.

New bounce, complaint, delivery webhook processing or provider callback endpoints are not added by this plugin. Native mode preserves the original adapter and its existing interfaces/configuration; the nine bundled standard Symfony API bridges and two direct API adapters do not add Mautic-specific batch/callback features. Configuration storage and native sending are the current scope. A configured API bridge is not proof of credentials, sender verification, SES production access or end-to-end delivery.

## Validation

`php Tests/provider-transports.php` exercises all five new provider protocols using mocked HTTP and a temporary private registry. It verifies endpoints/authentication, region and credential validation, secret preservation/redaction, explicit recipient envelopes, stream text/HTML/attachments, inline CID images, provider IDs, refusal versus partial acceptance, diagnostic classification and reserve boundaries. It also proves oversized direct API requests never reach the HTTP client. No real keys, network or database are used. Actual delivery for each new account still requires its credentials and verified sender; availability in the UI is not evidence of delivery.

`node Tests/connections-ui.cjs` exercises the client with synthetic DOM/HTTP contracts. It covers a form field named `action` shadowing the form's endpoint property, disabled connection serialization, duplicate clicks, safe literal rendering, provider details, session-result visibility and a lost/non-JSON response. The submit handler reads the form's `action` attribute, preserving the hidden operation field without letting it replace the URL.

`php Tests/example-sending.php` adds pure diagnostic/routing tests with mocked HTTP, synthetic connections and real Symfony form/CSRF/choice validation. It proves that invalid recipients, stale revisions, invalid CSRF, forged choices and non-admin requests do not select a transport, a failed choice cannot use the global transport, routing headers do not reach the provider, and native MIME/attachments remain intact. It also checks the Resend send endpoint/authentication, JSON controller results, provider ID/HTTP preservation, error redaction, refusal/timeout/malformed-response handling, session recovery and throttling. The controller harness uses an isolated CommonController contract, never the real Mautic kernel. Install the isolated development dependencies first. The runtime ZIP excludes these extra test dependencies.

`php Tests/connections.php` tests private storage, credentials, revisions, fallback graph and atomic releases. After `composer install --working-dir=build/dependencies`, `php Tests/transports.php` tests official transport construction, mocked Resend sending/fallback, MIME payload/envelope, event counts and simulated SMTP acceptance boundaries. `php Tests/native-compatibility.php` compares the original factory registry with and without Multi Mail, exercises native composites, compiles the lazy dependency graph, and checks transport object identity, batching/bounce/unsubscription interfaces and private DSN preservation. Tests use temporary filesystem data, mocked HTTP and a simulated stream. They never boot a Mautic kernel, connect to a database or send network traffic.

Release validated against the installed Mautic 7.2.0-rc / Symfony Mailer 7.4.12 environment. No compatibility claim for Mautic 5/6 or undocumented third-party plugin behavior. GitHub CI checks PHP 8.2, 8.3, 8.4 and 8.5 against both the locked dependencies and the installed Mautic baseline (Mailer7.4.12, HttpClient7.4.9, Mime7.4.13, EventDispatcher/DI7.4.14). The current matrix covers 43 registered transport schemes, with no kernel/database/network tests.

## Source and releases

Source: https://github.com/raphaelcangucu/mautic-multi-mail-bundle

Installable release ZIPs are published under GitHub Releases. The repository contains no credentials or private registries; build a ZIP with `python3 build/package.py` after installing the isolated build dependencies.
