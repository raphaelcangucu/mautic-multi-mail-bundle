<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Simulated IMAP server transcripts, like the simulated SMTP stream in transports.php. No network.
require __DIR__.'/bootstrap.php';

use MauticPlugin\MauticMultiMailBundle\Imap\{ImapAuthenticationFailed, ImapClient, ImapException, ImapRefused, ImapStream};

function imap(bool $ok, string $reason): void { if (!$ok) { throw new RuntimeException($reason); } }
function refusesImap(callable $action, string $class, string $reason): Throwable {
    try { $action(); } catch (Throwable $error) {
        imap($error instanceof $class, $reason.': unexpected '.get_class($error).' '.$error->getMessage());
        imap(!str_contains($error->getMessage(), 'unit-secret'), $reason.': secret in error');
        return $error;
    }
    throw new RuntimeException($reason.': expected '.$class);
}

final class ScriptedImapStream implements ImapStream
{
    public array $writes = [];
    public ?string $tls = null;
    public bool $closed = false;
    public function __construct(private string $server) {}
    public function readLine(int $max): string
    {
        $end = strpos($this->server, "\n");
        if ($end === false) { throw new ImapException('IMAP connection closed.'); }
        if ($end + 1 > $max) { throw new ImapException('IMAP response line too long.'); }
        $line = substr($this->server, 0, $end + 1); $this->server = (string) substr($this->server, $end + 1);
        return $line;
    }
    public function read(int $bytes): string
    {
        if (strlen($this->server) < $bytes) { throw new ImapException('IMAP connection closed.'); }
        $data = substr($this->server, 0, $bytes); $this->server = (string) substr($this->server, $bytes);
        return $data;
    }
    public function write(string $bytes): void { $this->writes[] = $bytes; }
    public function enableTls(string $peerName): void { $this->tls = $peerName; }
    public function close(): void { $this->closed = true; }
    public function sent(): string { return implode('', $this->writes); }
}

$crlf = static fn(string $text): string => str_replace("\n", "\r\n", $text);
$body = $crlf("Message-ID: <a@example.com>\nSubject: literal ) with {5}\n\nOi\n");
$second = $crlf("Message-ID: <b@example.com>\n\nTchau\n");
$server = $crlf("* OK [CAPABILITY IMAP4rev1] ready\n")
    ."mm1 OK LOGIN completed\r\n"
    .$crlf("* 3 EXISTS\n* 0 RECENT\n* OK [UIDVALIDITY 3857529045] UIDs valid\n* OK [UIDNEXT 6] Predicted next UID\n* FLAGS (\\Seen)\nmm2 OK [READ-ONLY] EXAMINE completed\n")
    .$crlf("* SEARCH 5\nmm3 OK SEARCH completed\n")
    .$crlf("* SEARCH 4 5\nmm4 OK SEARCH completed\n")
    .$crlf("* 2 FETCH (UID 4 RFC822.SIZE ".strlen($body)." INTERNALDATE \" 4-Oct-2026 10:00:00 -0300\")\n* 3 FETCH (FLAGS (\\Seen))\n* 3 FETCH (UID 5 INTERNALDATE NIL RFC822.SIZE ".strlen($second).")\nmm5 OK FETCH completed\n")
    ."* 2 FETCH (BODY[] {".strlen($body)."}\r\n".$body." UID 4 INTERNALDATE \"24-Oct-2026 10:00:00 +0000\")\r\n"
    ."* 3 FETCH (UID 5 BODY[] {".strlen($second)."}\r\n".$second.")\r\n"
    .$crlf("mm6 OK FETCH completed\n")
    .$crlf("* SEARCH 9\nmm7 OK SEARCH completed\n")
    .$crlf("* SEARCH\nmm8 OK SEARCH completed\n")
    .$crlf("* BYE logging out\nmm9 OK LOGOUT completed\n");
$stream = new ScriptedImapStream($server);
$client = new ImapClient($stream, 'imap.example.com', false);
$client->login('contato@macro.test', 'unit-secret "quoted" \\ back');
$folder = $client->examine('INBOX');
imap($folder === ['uidvalidity' => 3857529045, 'uidnext' => 6, 'exists' => 3], 'EXAMINE reads UIDVALIDITY, UIDNEXT and EXISTS');
imap($client->uidsAfter(5) === [], 'n:* returning the last message is not new mail');
imap($client->uidsAfter(3) === [4, 5], 'UID search after the stored position');
$summary = $client->fetch([5, 4, 4]);
imap(array_keys($summary) === [4, 5] && $summary[4]['size'] === strlen($body) && $summary[4]['raw'] === null, 'Summary fetch ignores unsolicited FLAGS');
imap($summary[4]['internaldate']?->format(DATE_ATOM) === '2026-10-04T10:00:00-03:00' && $summary[5]['internaldate'] === null, 'INTERNALDATE with padded day and NIL');
$bodies = $client->fetch([4, 5], 'body');
imap($bodies[4]['raw'] === $body && $bodies[5]['raw'] === $second, 'Literal bodies byte-exact, items in any order');
imap($client->uidsByMessageId('<0123456789abcdef0123456789abcdef.31@macro.test>') === [9], 'Search Sent by Message-ID');
imap($client->uidsSince(new DateTimeImmutable('2026-10-17 12:00:00 UTC')) === [], 'Empty SEARCH');
$client->logout();
$client->logout();
$sent = $stream->sent();
imap(str_contains($sent, "mm1 LOGIN \"contato@macro.test\" \"unit-secret \\\"quoted\\\" \\\\ back\"\r\n"), 'LOGIN quotes and escapes');
imap(str_contains($sent, "mm2 EXAMINE \"INBOX\"\r\n") && !str_contains($sent, 'SELECT'), 'Read-only EXAMINE, never SELECT');
imap(str_contains($sent, "mm3 UID SEARCH UID 6:*\r\n") && str_contains($sent, "mm4 UID SEARCH UID 4:*\r\n"), 'UID ranges');
imap(str_contains($sent, "mm5 UID FETCH 4,5 (UID INTERNALDATE RFC822.SIZE)\r\n"), 'Summary items');
imap(str_contains($sent, "mm6 UID FETCH 4,5 (UID INTERNALDATE RFC822.SIZE BODY.PEEK[])\r\n") && !str_contains($sent, 'BODY[]'), 'PEEK never changes \\Seen');
imap(str_contains($sent, "mm7 UID SEARCH HEADER Message-ID \"<0123456789abcdef0123456789abcdef.31@macro.test>\"\r\n"), 'HEADER search');
imap(str_contains($sent, "mm8 UID SEARCH SINCE 17-Oct-2026\r\n") && str_contains($sent, "mm9 LOGOUT\r\n") && $stream->closed, 'SINCE date and LOGOUT');
imap(substr_count($sent, 'LOGOUT') === 1, 'Second logout is a no-op');

// Non-ASCII password and folder: synchronizing literal and modified UTF-7.
$stream = new ScriptedImapStream($crlf("* OK ready\n+ go ahead\nmm1 OK done\n* OK [UIDVALIDITY 7] x\n* 0 EXISTS\nmm2 OK done\n"));
$client = new ImapClient($stream, 'imap.example.com', false);
$client->login('ana', 'unit-secret-çã');
imap($client->examine('Itens Enviados/Conversações') === ['uidvalidity' => 7, 'uidnext' => 1, 'exists' => 0], 'Missing UIDNEXT on an empty folder');
$sent = $stream->sent();
imap(str_contains($sent, "mm1 LOGIN \"ana\" {".strlen('unit-secret-çã')."}\r\n") && str_contains($sent, 'unit-secret-çã'."\r\n"), 'Literal after continuation');
imap(str_contains($sent, 'mm2 EXAMINE "Itens Enviados/Conversa&AOcA9Q-es"'), 'Modified UTF-7 folder name');

// Missing UIDNEXT on a non-empty folder: highest UID + 1.
$stream = new ScriptedImapStream($crlf("* OK ready\n* 2 EXISTS\n* OK [UIDVALIDITY 9] x\nmm1 OK done\n* SEARCH 10 42\nmm2 OK done\n"));
imap((new ImapClient($stream, 'h', false))->examine('INBOX')['uidnext'] === 43, 'UIDNEXT derived from UID SEARCH ALL');

// Authentication refused: dedicated exception, no credential in the message.
$stream = new ScriptedImapStream($crlf("* OK ready\nmm1 NO [AUTHENTICATIONFAILED] Invalid credentials unit-secret\n"));
$client = new ImapClient($stream, 'h', false);
refusesImap(fn() => $client->login('ana', 'unit-secret'), ImapAuthenticationFailed::class, 'LOGIN refusal');

// STARTTLS on 143: upgrade before anything else, and never fall back to plain text.
$stream = new ScriptedImapStream($crlf("* OK ready\nmm1 OK Begin TLS\nmm2 OK LOGIN\n"));
$client = new ImapClient($stream, 'imap.example.com', true);
$client->login('ana', 'x');
imap($stream->tls === 'imap.example.com' && $stream->writes[0] === "mm1 STARTTLS\r\n", 'STARTTLS first, verified against the host name');
$stream = new ScriptedImapStream($crlf("* OK ready\nmm1 BAD STARTTLS not supported\n"));
refusesImap(fn() => new ImapClient($stream, 'h', true), ImapException::class, 'STARTTLS refusal');
imap($stream->closed && $stream->tls === null && count($stream->writes) === 1, 'No credentials sent after a STARTTLS refusal');
refusesImap(fn() => ImapClient::open('imap.example.com', 25), ImapException::class, 'Plain ports refused before connecting');
refusesImap(fn() => new ImapClient(new ScriptedImapStream("* BYE busy\r\n"), 'h', false), ImapException::class, 'Refused greeting');

// Errors in the middle of a batch: tagged NO, dropped connection, unexpected BYE, oversized literal.
$stream = new ScriptedImapStream($crlf("* OK ready\n* 1 FETCH (UID 4 RFC822.SIZE 10)\nmm1 NO [SERVERBUG] fetch failed\n"));
$error = refusesImap(fn() => (new ImapClient($stream, 'h', false))->fetch([4, 5]), ImapRefused::class, 'Tagged NO in a batch');
imap($error instanceof ImapRefused && $error->status === 'NO', 'NO status exposed');
$stream = new ScriptedImapStream("* OK ready\r\n* 1 FETCH (UID 4 BODY[] {100}\r\npartial");
refusesImap(fn() => (new ImapClient($stream, 'h', false))->fetch([4], 'body'), ImapException::class, 'Connection lost inside a literal');
$stream = new ScriptedImapStream($crlf("* OK ready\n* BYE Autologout; idle for too long\n"));
$client = new ImapClient($stream, 'h', false);
refusesImap(fn() => $client->uidsAfter(1), ImapException::class, 'Unexpected BYE');
imap($stream->closed, 'BYE closes the stream');
refusesImap(fn() => $client->uidsAfter(1), ImapException::class, 'No command after BYE');
$stream = new ScriptedImapStream("* OK ready\r\n* 1 FETCH (UID 4 BODY[] {999999999}\r\n");
refusesImap(fn() => (new ImapClient($stream, 'h', false))->fetch([4], 'body'), ImapException::class, 'Oversized literal refused before reading');
refusesImap(fn() => (new ImapClient(new ScriptedImapStream("* OK\r\n"), 'h', false))->examine("INBOX\r\nmm9 DELETE x"), InvalidArgumentException::class, 'No command injection through folder names');
refusesImap(fn() => (new ImapClient(new ScriptedImapStream("* OK\r\n"), 'h', false))->uidsByMessageId("<a@b>\r\nX"), InvalidArgumentException::class, 'No injection through Message-ID');
echo "PASS: IMAP greeting, LOGIN quoting/literals, STARTTLS without fallback, EXAMINE, UID SEARCH ranges/SINCE/HEADER, FETCH literals and unsolicited data, PEEK only, NO/BAD/BYE/drop/oversize errors and injection guards; no network\n";
