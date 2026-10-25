<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Tests\Fixtures;

use MauticPlugin\MauticMultiMailBundle\Imap\{ImapException, ImapStream};

/**
 * In-memory IMAP server behind the ImapStream seam: enough of RFC 3501 for the poller and
 * the round-trip test (LOGIN, EXAMINE, UID SEARCH UID/SINCE/HEADER/ALL, UID FETCH, LOGOUT).
 *
 * $folders: name => ['uidvalidity' => int, 'messages' => [uid => ['raw' => string, 'date' => 'Y-m-d H:i:s', 'size' => ?int]]]
 */
final class FakeImapServer implements ImapStream
{
    public array $commands = [];
    public ?\Closure $intercept = null;
    private string $in = '';
    private string $out = "* OK [CAPABILITY IMAP4rev1] fake ready\r\n";
    private ?string $selected = null;
    private bool $closed = false;

    public function __construct(public array $folders, private string $password = 'secret') {}

    /** A new TCP session on the same mailbox: greeting again, nothing selected. */
    public function reconnect(): self
    {
        $this->in = '';
        $this->out = "* OK [CAPABILITY IMAP4rev1] fake ready\r\n";
        $this->selected = null;
        $this->closed = false;

        return $this;
    }

    public function readLine(int $max): string
    {
        $end = strpos($this->out, "\n");
        if ($end === false) { throw new ImapException('IMAP connection closed.'); }
        $line = substr($this->out, 0, $end + 1);
        $this->out = (string) substr($this->out, $end + 1);
        return $line;
    }

    public function read(int $bytes): string
    {
        if (strlen($this->out) < $bytes) { throw new ImapException('IMAP connection closed.'); }
        $data = substr($this->out, 0, $bytes);
        $this->out = (string) substr($this->out, $bytes);
        return $data;
    }

    public function write(string $bytes): void
    {
        if ($this->closed) { throw new ImapException('IMAP connection closed.'); }
        $this->in .= $bytes;
        while (($end = strpos($this->in, "\r\n")) !== false) {
            $line = substr($this->in, 0, $end);
            $this->in = (string) substr($this->in, $end + 2);
            $this->handle($line);
        }
    }

    public function enableTls(string $peerName): void {}

    public function close(): void { $this->closed = true; }

    private function handle(string $line): void
    {
        [$tag, $rest] = explode(' ', $line, 2) + [1 => ''];
        $this->commands[] = $rest;
        if ($this->intercept !== null && ($response = ($this->intercept)($rest, $tag)) !== null) {
            $this->out .= $response;
            return;
        }
        if (preg_match('/^LOGIN "((?:[^"\\\\]|\\\\.)*)" "((?:[^"\\\\]|\\\\.)*)"$/', $rest, $match)) {
            $this->out .= stripcslashes($match[2]) === $this->password ? "$tag OK LOGIN completed\r\n" : "$tag NO [AUTHENTICATIONFAILED] Invalid credentials\r\n";
            return;
        }
        if (preg_match('/^EXAMINE "([^"]*)"$/', $rest, $match)) {
            $folder = $this->folders[$match[1]] ?? null;
            if ($folder === null) { $this->out .= "$tag NO [NONEXISTENT] Unknown folder\r\n"; return; }
            $this->selected = $match[1];
            $uids = array_keys($folder['messages']);
            $this->out .= '* '.count($uids)." EXISTS\r\n* OK [UIDVALIDITY ".$folder['uidvalidity']."] UIDs valid\r\n"
                .'* OK [UIDNEXT '.(($uids === [] ? 0 : max($uids)) + 1)."] next\r\n$tag OK [READ-ONLY] EXAMINE completed\r\n";
            return;
        }
        if ($this->selected === null) { $this->out .= "$tag BAD No folder selected\r\n"; return; }
        $messages = $this->folders[$this->selected]['messages'];
        ksort($messages);
        if (preg_match('/^UID SEARCH (.+)$/', $rest, $match)) {
            $criteria = $match[1];
            $found = [];
            if (preg_match('/^UID (\d+):\*$/', $criteria, $range)) {
                $found = array_values(array_filter(array_keys($messages), fn(int $uid): bool => $uid >= (int) $range[1]));
                // RFC 3501: n:* always includes the highest UID, even when n is above it.
                if ($found === [] && $messages !== []) { $found = [max(array_keys($messages))]; }
            } elseif (preg_match('/^SINCE (\d{1,2}-[A-Za-z]{3}-\d{4})$/', $criteria, $since)) {
                $from = strtotime($since[1].' 00:00:00 UTC');
                $found = array_keys(array_filter($messages, fn(array $message): bool => strtotime($message['date'].' UTC') >= $from));
            } elseif (preg_match('/^HEADER Message-ID "(.+)"$/', $criteria, $header)) {
                $found = array_keys(array_filter($messages, fn(array $message): bool => (bool) preg_match('/^Message-ID:\s*'.preg_quote($header[1], '/').'\s*$/mi', $message['raw'])));
            } elseif ($criteria === 'ALL') {
                $found = array_keys($messages);
            }
            $this->out .= '* SEARCH'.($found === [] ? '' : ' '.implode(' ', $found))."\r\n$tag OK SEARCH completed\r\n";
            return;
        }
        if (preg_match('/^UID FETCH ([\d,]+) \((.+)\)$/', $rest, $match)) {
            $sequence = array_flip(array_keys($messages));
            foreach (array_map('intval', explode(',', $match[1])) as $uid) {
                if (!isset($messages[$uid])) { continue; }
                $message = $messages[$uid];
                $size = $message['size'] ?? strlen($message['raw']);
                $date = gmdate('d-M-Y H:i:s', strtotime($message['date'].' UTC')).' +0000';
                $item = '* '.($sequence[$uid] + 1).' FETCH (UID '.$uid.' RFC822.SIZE '.$size.' INTERNALDATE "'.$date.'"';
                if (str_contains($match[2], 'BODY.PEEK[HEADER]')) {
                    $headers = substr($message['raw'], 0, (int) strpos($message['raw'], "\r\n\r\n") + 4);
                    $item .= ' BODY[HEADER] {'.strlen($headers)."}\r\n".$headers;
                } elseif (str_contains($match[2], 'BODY.PEEK[]')) {
                    $item .= ' BODY[] {'.strlen($message['raw'])."}\r\n".$message['raw'];
                }
                $this->out .= $item.")\r\n";
            }
            $this->out .= "$tag OK FETCH completed\r\n";
            return;
        }
        if ($rest === 'LOGOUT') { $this->out .= "* BYE bye\r\n$tag OK LOGOUT completed\r\n"; return; }
        $this->out .= "$tag BAD Unsupported\r\n";
    }
}
