<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// In-memory IMAP server, fake bridge, temporary private directory and real child processes. No network or database.
require __DIR__.'/bootstrap.php';

use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use MauticPlugin\MauticMultiMailBundle\Conversation\{ConversationBridgeInterface, InboundEmail, InboundParser, NoopConversationBridge};
use MauticPlugin\MauticMultiMailBundle\Imap\{ImapClient, ImapConnectorInterface, ImapException, ImapStateStore, MailboxPoller};
use MauticPlugin\MauticMultiMailBundle\Tests\Fixtures\FakeImapServer;

function poller(bool $ok, string $reason): void { if (!$ok) { throw new RuntimeException($reason); } }

final class RecordingBridge implements ConversationBridgeInterface
{
    public array $ingested = [], $statuses = [], $unreadable = [], $confirmed = [], $uncertain = [];
    public ?string $failOn = null;
    public int $syncs = 0;
    public function available(): bool { return true; }
    public function synchronize(array $connections): void { ++$this->syncs; }
    public function ingest(array $connection, InboundEmail $email, string $raw, DateTimeImmutable $receivedAt): string
    {
        if ($email->messageId === $this->failOn) { throw new RuntimeException('database unavailable'); }
        $this->ingested[] = $email->messageId.($email->truncated ? ' truncated' : '').' @'.$receivedAt->format('Y-m-d');
        return 'recorded';
    }
    public function ingestUnreadable(array $connection, string $raw, DateTimeImmutable $receivedAt): string { $this->unreadable[] = strlen($raw); return 'unreadable'; }
    public function inboundStatus(array $connection, string $status, ?string $since): void { $this->statuses[] = $status.($since === null ? '' : ' since'); }
    public function uncertainMessageIds(array $connection): array { return $this->uncertain; }
    public function confirmSent(array $connection, string $messageId): bool { $this->confirmed[] = $messageId; return true; }
}

final class FakeConnector implements ImapConnectorInterface
{
    public int $connections = 0;
    public ?Throwable $failure = null;
    public function __construct(public FakeImapServer $server, private int $holdSeconds = 0) {}
    public function connect(array $connection): ImapClient
    {
        ++$this->connections;
        if ($this->failure !== null) { throw $this->failure; }
        if ($this->holdSeconds > 0) { sleep($this->holdSeconds); }
        $client = new ImapClient($this->server->reconnect(), 'imap.example.com', false);
        $client->login($connection['settings']['imap_username'], $connection['secrets']['imap_password']);
        return $client;
    }
}

function rawMail(string $id, string $date = '2026-10-24 10:00:00', string $from = 'joao@example.com'): array
{
    return ['raw' => "From: Lead <$from>\r\nTo: contato@macro.test\r\nSubject: Mensagem $id\r\nMessage-ID: <$id@example.com>\r\n\r\nCorpo $id\r\n", 'date' => $date];
}

function fixtureStore(string $project): array
{
    $store = new ConnectionStore($project);
    $view = $store->save(['provider' => 'smtp', 'name' => 'Contato', 'from_email' => 'contato@macro.test', 'from_name' => 'Macro', 'reply_to' => '',
        'fallback' => '', 'conversational' => '1', 'settings' => ['host' => 'smtp.macro.test', 'port' => '465', 'encryption' => 'ssl', 'username' => 'contato@macro.test',
        'imap_host' => 'imap.macro.test', 'imap_port' => '993', 'imap_username' => 'contato@macro.test', 'imap_folder' => 'INBOX', 'imap_sent_folder' => 'Sent'],
        'secrets' => ['password' => 'unit-secret-smtp', 'imap_password' => 'secret']], 0, 1);
    return [$store, $view['connections'][0]];
}

if (($argv[1] ?? '') === 'poll-child') {
    [$store] = [new ConnectionStore($argv[2])];
    $server = new FakeImapServer(['INBOX' => ['uidvalidity' => 9, 'messages' => [1 => rawMail('child-1')]], 'Sent' => ['uidvalidity' => 1, 'messages' => []]]);
    $poller = new MailboxPoller($store, new RecordingBridge(), new ImapStateStore($argv[2]), new InboundParser(), new FakeConnector($server, 2), $argv[2]);
    echo json_encode(array_column($poller->poll(), 'status'));
    exit;
}

$root = sys_get_temp_dir().'/multimail_poller_test_'.bin2hex(random_bytes(8));
mkdir($root.'/project', 0700, true);
try {
    $project = $root.'/project';
    [$store, $connection] = fixtureStore($project);
    $id = $connection['id'];
    $server = new FakeImapServer(['INBOX' => ['uidvalidity' => 7, 'messages' => [1 => rawMail('old-1'), 2 => rawMail('old-2'), 3 => rawMail('old-3')]],
        'Sent' => ['uidvalidity' => 1, 'messages' => [5 => rawMail('0123456789abcdef0123456789abcdef.31')]]]);
    $connector = new FakeConnector($server);
    $states = new ImapStateStore($project);
    $bridge = new RecordingBridge();
    $poller = new MailboxPoller($store, $bridge, $states, new InboundParser(), $connector, $project);
    $now = strtotime('2026-10-24 12:00:00 UTC');

    $noop = new MailboxPoller($store, new NoopConversationBridge(), $states, new InboundParser(), $connector, $project);
    poller($noop->poll() === [] && $connector->connections === 0, 'Without the Meta bundle the poller does nothing');

    $result = $poller->poll(null, $now);
    poller($result[$id]['status'] === 'initialized' && $bridge->ingested === [], 'First pass starts at UIDNEXT without importing history');
    poller($states->get($id)['last_uid'] === 3 && $states->get($id)['uidvalidity'] === 7 && $bridge->syncs === 1, 'Position stored, assets synchronized');
    poller(!array_filter($server->commands, fn(string $command): bool => str_starts_with($command, 'SELECT') || str_contains($command, 'BODY[]')), 'Read-only commands');

    $server->folders['INBOX']['messages'] += [4 => rawMail('new-4'), 5 => rawMail('new-5'), 6 => ['raw' => "\x00\x01garbage", 'date' => '2026-10-24 11:00:00'],
        7 => rawMail('new-7'), 8 => rawMail('big-8') + ['size' => 30 * 1024 * 1024]];
    $bridge->failOn = '<new-7@example.com>';
    $result = $poller->poll(null, $now += 60);
    poller($result[$id]['status'] === 'error' && $result[$id]['error'] === 'RuntimeException', 'Storage failure fails the pass without its message: '.json_encode($result));
    poller($bridge->ingested === ['<new-4@example.com> @2026-10-24', '<new-5@example.com> @2026-10-24'] && $bridge->unreadable === [strlen("\x00\x01garbage")], 'Stored in UID order; unreadable mail becomes an event');
    $state = $states->get($id);
    poller($state['last_uid'] === 6 && $state['failures'] === 1 && $state['status'] === 'ok', 'UID advances only after each write: 7 will be read again');
    poller(strtotime($state['next_attempt_at']) === $now + 60 && $bridge->statuses === ['ok'], 'One-minute back-off, not yet delayed');
    poller($poller->poll(null, $now + 30)[$id]['status'] === 'waiting', 'Waiting connection is skipped');
    $bridge->failOn = null;
    $result = $poller->poll(null, $now += 61);
    poller($result[$id]['status'] === 'ok' && $result[$id]['outcomes'] === ['recorded' => 2], 'Retry stores the failed message once');
    poller(array_slice($bridge->ingested, -2) === ['<new-7@example.com> @2026-10-24', '<big-8@example.com> truncated @2026-10-24'], 'Oversized message read as headers only');
    poller(in_array('UID FETCH 8 (UID INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER])', $server->commands, true), 'Header-only fetch for > 25 MiB');
    poller($states->get($id)['last_uid'] === 8 && $states->get($id)['failures'] === 0 && end($bridge->statuses) === 'ok', 'Recovered');

    $bridge->uncertain = ['<0123456789abcdef0123456789abcdef.31@example.com>', '<missing@example.com>'];
    $poller->poll(null, $now += 60);
    poller($bridge->confirmed === ['<0123456789abcdef0123456789abcdef.31@example.com>'], 'Uncertain Message-ID found in the Sent folder is confirmed');
    $bridge->uncertain = [];

    // UIDVALIDITY changed: re-read seven days, the scoped external id drops duplicates downstream.
    $server->folders['INBOX'] = ['uidvalidity' => 8, 'messages' => [1 => rawMail('ancient', '2026-10-10 09:00:00'), 2 => rawMail('new-7', '2026-10-22 09:00:00'), 3 => rawMail('after-reset', '2026-10-24 12:00:00')]];
    $before = count($bridge->ingested);
    $result = $poller->poll(null, $now += 60);
    poller(array_slice($bridge->ingested, $before) === ['<new-7@example.com> @2026-10-22', '<after-reset@example.com> @2026-10-24'], 'New UIDVALIDITY re-reads the last 7 days only');
    poller($states->get($id)['uidvalidity'] === 8 && $states->get($id)['last_uid'] === 3, 'Position follows the new UIDVALIDITY');

    // Refused password: stop until the connection is saved again.
    $server = new FakeImapServer($server->folders, 'rotated');
    $connector->server = $server;
    $result = $poller->poll(null, $now += 60);
    poller($result[$id]['status'] === 'auth_failed' && end($bridge->statuses) === 'auth_failed since', 'Authentication failure reported to the asset');
    $connects = $connector->connections;
    poller($poller->poll(null, $now += 3600)[$id]['status'] === 'auth_failed' && $connector->connections === $connects, 'No retry with the refused password');
    sleep(1); // updated_at has one-second resolution.
    $store->save(['id' => $id, 'provider' => 'smtp', 'name' => 'Contato', 'from_email' => 'contato@macro.test', 'from_name' => 'Macro', 'reply_to' => '', 'fallback' => '',
        'settings' => ['host' => 'smtp.macro.test', 'port' => '465', 'encryption' => 'ssl', 'username' => 'contato@macro.test'], 'secrets' => ['imap_password' => 'rotated']], 1, 1);
    $result = $poller->poll(null, $now += 60);
    poller($result[$id]['status'] === 'ok' && end($bridge->statuses) === 'ok', 'Saving the connection again resumes polling');

    // Network failure for more than 30 minutes: delayed, since the start of the problem.
    $connector->failure = new ImapException('IMAP server timed out.');
    $start = $now += 60;
    $statuses = [];
    for ($i = 0; $i < 12; ++$i) {
        $next = $states->get($id)['next_attempt_at'];
        $now = $next === null ? $now : max($now, strtotime($next));
        $result = $poller->poll(null, $now);
        $statuses[] = $result[$id]['status'];
        if (end($bridge->statuses) === 'delayed since') { break; }
    }
    $state = $states->get($id);
    poller(end($bridge->statuses) === 'delayed since' && $now - $start >= 1800 && $state['status'] === 'delayed', 'Delayed after 30 minutes without success');
    poller($state['last_error'] === 'IMAP server timed out.' && !str_contains(json_encode($state), 'secret'), 'Stored error carries no credentials');
    $connector->failure = null;
    poller(strtotime($state['next_attempt_at']) - $now <= MailboxPoller::MAX_BACKOFF, 'Back-off capped at 30 minutes');
    $poller->poll(null, strtotime($state['next_attempt_at']));
    poller($states->get($id)['status'] === 'ok' && $states->get($id)['problem_since'] === null && end($bridge->statuses) === 'ok', 'Success clears the delay');

    // Another mailbox/folder: its UIDs are meaningless here.
    $states->update($id, ['position' => 'other-folder']);
    $poller->poll(null, $now += 60);
    poller($states->get($id)['uidvalidity'] === 8 && $states->get($id)['last_uid'] === 3, 'Changed folder restarts at UIDNEXT');
    poller((fileperms($root.'/project-multimail-private/imap-state.json') & 0777) === 0600, 'State file is private');

    // Two cron passes at once: one reads, the other sees the lock.
    mkdir($root.'/concurrent', 0700);
    fixtureStore($root.'/concurrent');
    $children = [];
    for ($n = 0; $n < 2; ++$n) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'poll-child', $root.'/concurrent'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $children[] = [$process, $pipes];
        usleep(200000);
    }
    $answers = [];
    foreach ($children as [$process, $pipes]) {
        $answer = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        poller(proc_close($process) === 0 && $error === '', 'Child poll exits cleanly: '.$error);
        $answers = array_merge($answers, json_decode($answer, true));
    }
    sort($answers);
    poller($answers === ['initialized', 'locked'], 'Exactly one process reads a mailbox at a time: '.json_encode($answers));
} finally {
    $remove = function (string $path) use (&$remove): void {
        foreach (new FilesystemIterator($path) as $item) { $item->isDir() && !$item->isLink() ? $remove($item->getPathname()) : unlink($item->getPathname()); }
        rmdir($path);
    };
    $remove($root);
}
echo "PASS: IMAP poller disabled without Meta, UIDNEXT start, read-only fetches, UID advance after write, storage-failure retry, unreadable events, header-only big mail, Sent confirmation, UIDVALIDITY 7-day resync, auth stop/resume, 30-minute delay with capped back-off, folder change and concurrent flock; no network/database\n";
