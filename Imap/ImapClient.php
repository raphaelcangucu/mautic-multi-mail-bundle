<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Imap;

/**
 * Minimal IMAP4rev1 client for the conversational poller (ext-imap left PHP core in 8.4).
 *
 * Read-only by construction: EXAMINE instead of SELECT and BODY.PEEK[] instead of BODY[],
 * so a person reading the same mailbox on a phone never sees flags change. APPEND and
 * execute() exist only for the end-to-end tests that play the lead's mail server.
 */
final class ImapClient
{
    /** Bodies above this size are fetched as headers only; the literal cap leaves some margin. */
    public const MAX_MESSAGE_BYTES = 26214400;
    private const MAX_LITERAL = 27262976;
    private const MAX_LINE = 16777216;

    private int $sequence = 0;
    private bool $open = true;
    private string $verb = '';

    public function __construct(private readonly ImapStream $stream, private readonly string $host, bool $startTls)
    {
        $greeting = $this->readLogical()['text'];
        if (!preg_match('/^\* (OK|PREAUTH)\b/i', $greeting)) {
            $this->stream->close();
            throw new ImapException('IMAP server refused the session.');
        }
        if ($startTls) {
            // Port 143 is accepted only to upgrade it: a refusal never falls back to plain text.
            try { $this->command('STARTTLS'); }
            catch (ImapRefused) { $this->stream->close(); throw new ImapException('IMAP server does not offer STARTTLS.'); }
            $this->stream->enableTls($this->host);
        }
    }

    public static function open(string $host, int $port, int $timeout = 20): self
    {
        if (!in_array($port, [993, 143], true)) { throw new ImapException('Use IMAP over TLS: port 993, or 143 with STARTTLS.'); }

        return new self(SocketImapStream::open($host, $port, $port === 993, $timeout), $host, $port === 143);
    }

    public function login(string $username, #[\SensitiveParameter] string $password): void
    {
        try { $this->command(['LOGIN ', self::astring($username), ' ', self::astring($password)]); }
        catch (ImapRefused) { throw new ImapAuthenticationFailed('IMAP authentication failed.'); }
    }

    /** @return array{uidvalidity: int, uidnext: int, exists: int} */
    public function examine(string $folder): array
    {
        $result = ['uidvalidity' => null, 'uidnext' => null, 'exists' => 0];
        foreach ($this->command(['EXAMINE ', self::mailbox($folder)]) as $line) {
            if (preg_match('/^\* OK \[UIDVALIDITY (\d{1,10})\]/i', $line['text'], $match)) { $result['uidvalidity'] = (int) $match[1]; }
            elseif (preg_match('/^\* OK \[UIDNEXT (\d{1,10})\]/i', $line['text'], $match)) { $result['uidnext'] = (int) $match[1]; }
            elseif (preg_match('/^\* (\d{1,10}) EXISTS$/i', $line['text'], $match)) { $result['exists'] = (int) $match[1]; }
        }
        if ($result['uidvalidity'] === null || $result['uidvalidity'] < 1) { throw new ImapException('IMAP folder has no UIDVALIDITY.'); }
        if ($result['uidnext'] === null) {
            // UIDNEXT is optional in some servers: derive it from the highest UID.
            $uids = $result['exists'] > 0 ? $this->search('ALL') : [];
            $result['uidnext'] = ($uids === [] ? 0 : max($uids)) + 1;
        }

        return $result;
    }

    /** UIDs strictly above $lastUid. `n:*` returns the last message even when n exceeds it, hence the filter. */
    public function uidsAfter(int $lastUid): array
    {
        return array_values(array_filter($this->search('UID '.($lastUid + 1).':*'), fn(int $uid): bool => $uid > $lastUid));
    }

    public function uidsSince(\DateTimeInterface $date): array
    {
        return $this->search('SINCE '.gmdate('j-M-Y', $date->getTimestamp()));
    }

    public function uidsByMessageId(string $messageId): array
    {
        if (!preg_match('/^<[\x21-\x7E]{1,996}>$/D', $messageId)) { throw new \InvalidArgumentException('Invalid Message-ID.'); }

        return $this->searchParts(['HEADER Message-ID ', self::astring($messageId)]);
    }

    /**
     * @param list<int> $uids
     * @return array<int, array{uid: int, size: int, internaldate: ?\DateTimeImmutable, raw: ?string}>
     */
    public function fetch(array $uids, string $section = 'summary'): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), fn(int $uid): bool => $uid > 0)));
        if ($uids === []) { return []; }
        sort($uids);
        $items = match ($section) {
            'summary' => 'UID INTERNALDATE RFC822.SIZE',
            'body' => 'UID INTERNALDATE RFC822.SIZE BODY.PEEK[]',
            'header' => 'UID INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER]',
            default => throw new \InvalidArgumentException('Invalid fetch section.'),
        };
        $messages = [];
        foreach ($this->command('UID FETCH '.implode(',', $uids).' ('.$items.')') as $line) {
            if (!preg_match('/^\* \d{1,10} FETCH /i', $line['text'], $match)) { continue; }
            $position = strlen($match[0]);
            $values = self::value($line['text'], $position, $line['literals']);
            if (!is_array($values)) { continue; }
            $data = [];
            for ($i = 0; $i + 1 < count($values); $i += 2) {
                if (is_string($values[$i])) { $data[strtoupper($values[$i])] = $values[$i + 1]; }
            }
            $uid = (int) ($data['UID'] ?? 0);
            if (!in_array($uid, $uids, true)) { continue; } // Unsolicited FLAGS updates and other messages.
            $raw = $data['BODY[]'] ?? $data['BODY[HEADER]'] ?? null;
            $messages[$uid] = ['uid' => $uid, 'size' => (int) ($data['RFC822.SIZE'] ?? 0),
                'internaldate' => self::date($data['INTERNALDATE'] ?? null), 'raw' => is_string($raw) ? $raw : null] + ($messages[$uid] ?? []);
        }
        ksort($messages);

        return $messages;
    }

    /** Test helper: the e2e plays the lead's MTA delivering into a folder. */
    public function append(string $folder, string $raw, array $flags = []): void
    {
        $flags = array_filter($flags, fn(string $flag): bool => (bool) preg_match('/^\\\\[A-Za-z]+$/D', $flag));
        $this->command(['APPEND ', self::mailbox($folder), ' ('.implode(' ', $flags).') ', ['literal' => str_replace(["\r\n", "\n"], ["\n", "\r\n"], $raw)]]);
    }

    /** Test helper for CREATE/DELETE/RENAME of a scratch folder. */
    public function execute(string $verb, string ...$folders): void
    {
        if (!in_array($verb, ['CREATE', 'DELETE', 'RENAME'], true) || count($folders) !== ($verb === 'RENAME' ? 2 : 1)) {
            throw new \InvalidArgumentException('Unsupported IMAP command.');
        }
        $parts = [$verb];
        foreach ($folders as $folder) { $parts[] = ' '; $parts[] = self::mailbox($folder); }
        $this->command($parts);
    }

    public function logout(): void
    {
        if (!$this->open) { return; }
        try { $this->command('LOGOUT'); } catch (\Throwable) { /* BYE then OK, or a dropped socket: both end the session. */ }
        finally { $this->open = false; $this->stream->close(); }
    }

    private function search(string $criteria): array
    {
        return $this->searchParts([$criteria]);
    }

    private function searchParts(array $criteria): array
    {
        $uids = [];
        foreach ($this->command(array_merge(['UID SEARCH '], $criteria)) as $line) {
            if (preg_match('/^\* SEARCH((?: \d{1,10})*)\s*$/i', $line['text'], $match)) {
                foreach (preg_split('/\s+/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY) as $uid) { $uids[(int) $uid] = (int) $uid; }
            }
        }
        sort($uids);

        return array_values($uids);
    }

    /**
     * @param string|list<string|array{literal: string}> $parts
     * @return list<array{text: string, literals: list<string>}> untagged lines
     */
    private function command(string|array $parts): array
    {
        if (!$this->open) { throw new ImapException('IMAP session closed.'); }
        $tag = 'mm'.(++$this->sequence);
        $first = is_array($parts) ? $parts[0] : $parts;
        $this->verb = strtoupper((string) strtok(is_string($first) ? $first : '', ' '));
        $untagged = [];
        $buffer = $tag.' ';
        foreach (is_array($parts) ? $parts : [$parts] as $part) {
            if (is_string($part)) { $buffer .= $part; continue; }
            $this->stream->write($buffer.'{'.strlen($part['literal']).'}'."\r\n");
            $buffer = '';
            $this->continuation($tag, $untagged);
            $this->stream->write($part['literal']);
        }
        $this->stream->write($buffer."\r\n");
        $this->complete($tag, $untagged);

        return $untagged;
    }

    private function continuation(string $tag, array &$untagged): void
    {
        while (true) {
            $line = $this->readLogical();
            if (str_starts_with($line['text'], '+')) { return; }
            if (str_starts_with($line['text'], $tag.' ')) { $this->status($tag, $line['text']); throw new ImapException('IMAP server refused a literal.'); }
            $this->untagged($line, $untagged);
        }
    }

    private function complete(string $tag, array &$untagged): void
    {
        while (true) {
            $line = $this->readLogical();
            if (str_starts_with($line['text'], $tag.' ')) { $this->status($tag, $line['text']); return; }
            $this->untagged($line, $untagged);
        }
    }

    private function untagged(array $line, array &$untagged): void
    {
        if (!str_starts_with($line['text'], '* ')) { throw new ImapException('Unexpected IMAP response.'); }
        if ($this->verb !== 'LOGOUT' && preg_match('/^\* BYE\b/i', $line['text'])) {
            // Shutdown or idle timeout on the server side: never wait for a tagged answer.
            $this->open = false;
            $this->stream->close();
            throw new ImapException('IMAP server closed the session.');
        }
        $untagged[] = $line;
    }

    private function status(string $tag, string $text): void
    {
        $rest = substr($text, strlen($tag) + 1);
        $status = strtoupper(strtok($rest, ' ') ?: '');
        if ($status === 'OK') { return; }
        if ($status === 'NO' || $status === 'BAD') { throw new ImapRefused($status, (string) substr($rest, strlen($status) + 1)); }
        throw new ImapException('Malformed IMAP status.');
    }

    /** @return array{text: string, literals: list<string>} one response with its literals out of band */
    private function readLogical(): array
    {
        $text = '';
        $literals = [];
        while (true) {
            $line = $this->stream->readLine(self::MAX_LINE);
            if (str_contains($line, "\0")) { throw new ImapException('Malformed IMAP response.'); }
            if (preg_match('/\{(\d{1,10})\}\r?\n$/D', $line, $match)) {
                $size = (int) $match[1];
                if ($size > self::MAX_LITERAL) { throw new ImapException('IMAP literal exceeds the safe size.'); }
                $text .= substr($line, 0, -strlen($match[0]))."\0".count($literals)."\0";
                $literals[] = $this->stream->read($size);
                continue;
            }

            return ['text' => $text.rtrim($line, "\r\n"), 'literals' => $literals];
        }
    }

    /** Parenthesized lists, quoted strings, literals, NIL and atoms (with bracketed sections). */
    private static function value(string $text, int &$position, array $literals): mixed
    {
        $length = strlen($text);
        while ($position < $length && $text[$position] === ' ') { ++$position; }
        if ($position >= $length) { return null; }
        $char = $text[$position];
        if ($char === '(') {
            ++$position;
            $list = [];
            while (true) {
                while ($position < $length && $text[$position] === ' ') { ++$position; }
                if ($position >= $length) { throw new ImapException('Unterminated IMAP list.'); }
                if ($text[$position] === ')') { ++$position; return $list; }
                $list[] = self::value($text, $position, $literals);
            }
        }
        if ($char === '"') {
            $value = '';
            for (++$position; $position < $length; ++$position) {
                $char = $text[$position];
                if ($char === '\\' && $position + 1 < $length) { $value .= $text[++$position]; continue; }
                if ($char === '"') { ++$position; return $value; }
                $value .= $char;
            }
            throw new ImapException('Unterminated IMAP string.');
        }
        if ($char === "\0") {
            $end = strpos($text, "\0", $position + 1);
            if ($end === false) { throw new ImapException('Malformed IMAP literal.'); }
            $index = (int) substr($text, $position + 1, $end - $position - 1);
            $position = $end + 1;

            return $literals[$index] ?? throw new ImapException('Malformed IMAP literal.');
        }
        $start = $position;
        $depth = 0;
        while ($position < $length) {
            $char = $text[$position];
            if ($char === '[') { ++$depth; }
            elseif ($char === ']') { --$depth; }
            elseif ($depth === 0 && ($char === ' ' || $char === '(' || $char === ')')) { break; }
            ++$position;
        }
        $atom = substr($text, $start, $position - $start);

        return strtoupper($atom) === 'NIL' ? null : $atom;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) { return null; }
        $date = \DateTimeImmutable::createFromFormat('!j-M-Y H:i:s O', trim($value));

        return $date === false ? null : $date;
    }

    /** Quoted string for printable ASCII, synchronizing literal for anything else. */
    private static function astring(#[\SensitiveParameter] string $value): string|array
    {
        if (preg_match('/^[\x20-\x7E]*$/D', $value)) { return '"'.addcslashes($value, '"\\').'"'; }

        return ['literal' => $value];
    }

    private static function mailbox(string $folder): string|array
    {
        if ($folder === '' || strlen($folder) > 200 || preg_match('/[\x00-\x1F\x7F]/', $folder)) { throw new \InvalidArgumentException('Invalid IMAP folder.'); }
        $encoded = strtoupper($folder) === 'INBOX' ? 'INBOX' : mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');

        return self::astring($encoded);
    }
}
