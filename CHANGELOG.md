# Changelog

## 0.5.0 — Conversational email

Read and answer, in the Inbox, the emails the platform started, as threads. Requires the Meta
bundle 0.15+ for the channel; without it Multi Mail stays a transport only.

### Added

- **Conversational SMTP connections.** Optional IMAP settings (`imap_host`, `imap_port` 993 or
  143 with mandatory STARTTLS, `imap_username`, `imap_folder`, `imap_sent_folder`), the secret
  `imap_password` (kept out of every public view) and the `conversational` flag. Older records
  receive the defaults on read. The flag is locked, with an explanation, when the Meta bundle is
  absent.
- **Email account assets.** One `email_account` `MetaAsset` per conversational connection under a
  single inactive internal `MetaConnection` (`multimail-email`); deactivated, never deleted, when
  the flag or the connection goes away; re-ensured on every poll.
- **`email_message` dispatcher** (tag `mautic_meta.channel_dispatcher`, `asset_type:
  email_account`): email DNC check, Message-ID stored on the job before the first attempt,
  pending `MetaMessage` with the scoped `email:` external id, send through the chosen connection
  only (no fallback, no `auto`), outcome mapped to the Meta queue taxonomy.
- **Outgoing MIME**: agent · connection sender name, Reply-To with an HMAC thread token in the
  +tag, explicit stable Message-ID, In-Reply-To/References (10), reply sentinel, text and HTML from
  the composer's markdown subset, one-click `List-Unsubscribe` on campaigns only. No pixel.
- **IMAP reader** `mautic:multimail:imap:poll`: own minimal IMAP client over
  `stream_socket_client` (ext-imap left PHP 8.4), read-only, per-connection lock, `imap-state.json`,
  first pass at UIDNEXT, UID advance after storage, 7-day resync on UIDVALIDITY change, back-off to
  30 minutes, `delayed`/`auth_failed` status on the asset and a one-time administrator notification,
  Sent-folder confirmation of uncertain sends.
- **Parsing and threading**: `zbateson/mail-mime-parser`, quote cutting in text and HTML (sentinel,
  Gmail, Outlook, Apple Mail, Thunderbird in Portuguese and English, `>` quotes, `-- ` signatures,
  interleaved answers kept), HTML-to-markdown for HTML-only mail, triage (echo, DSN bounce, auto-reply,
  bulk) and threading by token, then headers, then sender, then a new conversation with the unique
  matching contact.
- **Private blobs and attachments** in `mail-blobs/` (sha256, 25 MiB per file, 2 GiB total), MIME
  allow-list with signature check, clamd scanning through the `multimail_clamd_socket` parameter
  (no clamd → unavailable). `EmailContentProvider` for the Inbox (tag `mautic.inbox.email_content`).
- **Round-trip test** on the connections page: proves +tag support and records `plus_addressing`.
- `bin/greenmail.sh` and an opt-in GreenMail end-to-end test (`GREENMAIL=1`), also in CI.

### Changed

- `ConfirmedSmtpTransport` remembers which command an SMTP server refused (stage and reply line only).
- `ConnectionBuilder` accepts an optional SMTP stream factory, used only by tests.
- `.gitignore` and `build/package.py` exclude the new private files; the release ZIP bundles the
  MIME parser.

## 0.4.0

Daily and monthly shared sending quotas, hourly quotas and automatic rotation (`multimail://auto`).
See the README and the Git history for this and earlier releases.
