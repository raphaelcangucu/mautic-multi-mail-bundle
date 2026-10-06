# Multi Mail for Mautic

Independent Mautic 7.2 / Symfony Mailer 7.4 plugin (v0.2.0) for multiple SMTP and API connections, with an ordered fallback chain per connection. No Inbox dependency, no core patch, no campaign/use-case routing and no automatic credential expiry.

## Install

Extract `MauticMultiMailBundle` into Mautic's `plugins/` directory. The release ZIP bundles official Symfony bridges for Amazon SES, Resend, Mailgun, SendGrid, Postmark and Brevo; existing framework dependencies retain precedence. Register this plugin and rebuild the container and configured Twig cache (`MAUTIC_TWIG_CACHE_DIR`) using your deployment workflow. ZIPs preserve timestamps: refresh template timestamps if the Twig cache is stored outside the cleared kernel cache. There are no entities, migrations or plugin tables. On installations with custom schema-install subscribers, register only this plugin's metadata and avoid a broad reload of unrelated plugins.

Administrator screen: `/s/mail-connections`. Connections are stored as private JSON outside the web root: `<deployment>/shared/inbox-mail-private` for atomic releases, or `<project-parent>/<project-name>-multimail-private` otherwise. The atomic-release directory name preserves existing credentials; direct checkouts use separate storage per installation. Directory permissions are 0700, files 0600. Include this private directory in your protected backups; never publish it in ZIPs or Git.

## Native Mautic sending

### Test a connection and send native examples

Each saved connection has **Test sending**. Choose a saved connection and one recipient in `/s/mail-connections`; the diagnostic uses that connection's saved From and Reply-To. It does not change the global Mautic configuration. The standalone diagnostic never invokes the registry fallback chain; a native DSN keeps its own explicitly configured composite. The result distinguishes acceptance, confirmed refusal and uncertain handoff. Acceptance is not a delivery receipt: check the recipient's inbox and spam. Do not retry an uncertain result without checking for delivery. Tests are administrator-only, protected by CSRF, optimistic revision checks and a ten-second session cooldown. No provider credentials or debug transcripts are returned.

For administrators, the native email **Send example** modal now requires an explicit sending choice: a saved connection, or the current Mautic transport. Selecting a connection preserves the native email rendering, From, Reply-To, recipients, MIME and attachments. Only that connection's configured fallback applies; an unavailable or failed selection never silently uses the global transport. The selection applies only to this example and does not change campaign routing. Use a provider that authorizes the email's Mautic sender.

The connections page shows the active global transport and marks a saved connection as active only when its ID exactly matches the configured `multimail://` DSN. Registering a connection alone still does not switch the global transport. No arbitrary connection is automatically promoted.

Implementation uses a native Symfony named transport appended after the original transports, a form extension and Mautic's pre-send event. The original default transport and optional batching/bounce interfaces remain intact. Internal routing headers survive a Messenger queue and are removed before provider handoff. No Mautic core file is patched.

Edit a connection to obtain its identifier. In Mautic's email transport configuration use scheme `multimail`, host `<connection-id>` and empty user/password/port: `multimail://<connection-id>`. Saving the connection registry does not activate the global transport. Activation is an explicit operator decision. Mautic keeps its configured From, Reply-To, recipient envelope, MIME, attachments and tracking headers. All configured providers must authorize the sender used by Mautic.

Applications using the injected native Mautic mail helper/transport factory can reuse this transport. Symfony's static `Transport::fromDsn()` does not discover registered plugin factories; use the application's injected factory instead. Existing custom login transports continue using their current configuration until explicitly switched.

### Preserve any installed transport

Choose **Mautic · transporte nativo / DSN** to store the original DSN of any adapter already installed in Mautic, including SMTP options, provider bridges and third-party factories. The full DSN is private; a blank edit preserves it. When selected through `multimail://<connection-id>`, the plugin delegates to Mautic's existing factory and returns the exact original transport object. It does not wrap that object, change its options or duplicate its events. Optional Mautic batching, bounce and unsubscription interfaces remain present when the original adapter implements them.

For a native connection use the original transport's own `failover(...)` or `roundrobin(...)` DSN when appropriate. Native connections are not mixed into Multi Mail's independent fallback graph, which would hide adapter-specific capabilities and send outcome information. Recursive references to `multimail://` are rejected. A bridge must already be installed; this mode does not claim that every Symfony provider is bundled.

The plugin's registered factory accepts only `multimail`; all original DSN schemes and registered factories continue resolving through Mautic as before. Existing global configuration stays unchanged. Third-party integrations that inspect the global DSN text directly or implement additional custom configuration still require their original configuration and their own integration validation; returning the same transport does not invent compatibility for undocumented plugin behavior.

## Fallback

Each connection may point to one reserve; reserves may have their own reserve. Cycles and removal of a referenced connection are rejected. Credentials remain private and blank edits preserve them. Changes use optimistic revisions and an atomic private write.

SMTP requires TLS. Failures before DATA acceptance or explicit final 4xx/5xx refusal allow fallback. API authentication/permission/rate-limit refusals (401/403/429) allow fallback. A timeout, uncertain handoff, malformed successful response, HTTP 5xx or recipient/payload rejection stops the chain to avoid duplicate delivery. Provider exceptions/debug transcripts are not propagated. Existing Mautic retry settings still apply; this plugin does not provide a cross-job idempotency ledger or provider delivery reconciliation.

New bounce, complaint, delivery webhook processing or provider callback endpoints are not added by this plugin. Native mode preserves the original adapter and its existing interfaces/configuration; the six bundled standard Symfony API bridges do not add Mautic-specific batch/callback features. Configuration storage and native sending are the current scope. A configured API bridge is not proof of credentials, sender verification, SES production access or end-to-end delivery.

## Validation

`php Tests/example-sending.php` adds pure diagnostic/routing tests with mocked HTTP, synthetic connections and real Symfony form/CSRF/choice validation. It proves that invalid recipients, stale revisions, invalid CSRF, forged choices and non-admin requests do not select a transport, a failed choice cannot use the global transport, routing headers do not reach the provider, and native MIME/attachments remain intact. Install the isolated development dependencies first. The runtime ZIP excludes these extra test dependencies.

`php Tests/connections.php` tests private storage, credentials, revisions, fallback graph and atomic releases. After `composer install --working-dir=build/dependencies`, `php Tests/transports.php` tests official transport construction, mocked Resend sending/fallback, MIME payload/envelope, event counts and simulated SMTP acceptance boundaries. `php Tests/native-compatibility.php` compares the original factory registry with and without Multi Mail, exercises native composites, compiles the lazy dependency graph, and checks transport object identity, batching/bounce/unsubscription interfaces and private DSN preservation. Tests use temporary filesystem data, mocked HTTP and a simulated stream. They never boot a Mautic kernel, connect to a database or send network traffic.

Release validated against the installed Mautic 7.2.0-rc / Symfony Mailer 7.4.12 environment. No compatibility claim for Mautic 5/6 or undocumented third-party plugin behavior. GitHub CI checks PHP 8.2, 8.3, 8.4 and 8.5 against both the locked dependencies and the installed Mautic baseline (Mailer7.4.12, HttpClient7.4.9, Mime7.4.13, EventDispatcher/DI7.4.14). The current matrix covers 31 registered transport schemes, with no kernel/database/network tests.

## Source and releases

Source: https://github.com/raphaelcangucu/mautic-multi-mail-bundle

Installable release ZIPs are published under GitHub Releases. The repository contains no credentials or private registries; build a ZIP with `python3 build/package.py` after installing the isolated build dependencies.
