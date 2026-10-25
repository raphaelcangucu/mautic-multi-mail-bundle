<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Opt-in: real SMTPS/IMAPS against GreenMail on 127.0.0.1:465/993 (bin/greenmail.sh start). Verified TLS only.
if (getenv('GREENMAIL') !== '1') {
    echo "SKIP: GreenMail end-to-end; run bin/greenmail.sh start and set GREENMAIL=1\n";
    exit(0);
}
require __DIR__.'/bootstrap.php';

use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use MauticPlugin\MauticMultiMailBundle\Conversation\{ConversationalSender, ConversationBridgeInterface, InboundBody, InboundEmail, InboundParser, MessageIds,
    OutboundEmailBuilder, RoundTripTester, SendOutcome, ThreadLookup, ThreadResolver, ThreadToken};
use MauticPlugin\MauticMultiMailBundle\Imap\{ImapClient, ImapConnector, ImapStateStore, MailboxPoller};
use MauticPlugin\MauticMultiMailBundle\Mailer\ConnectionBuilder;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\{Address, Email};

function e2e(bool $ok, string $reason): void { if (!$ok) { throw new RuntimeException($reason); } }

/** Threading against in-memory conversations: the Meta-backed bridge has its own kernel-less test. */
final class MemoryThreadBridge implements ConversationBridgeInterface, ThreadLookup
{
    public array $records = [], $stored = [], $conversations = [];
    public function __construct(private ThreadResolver $threads, private int $assetId) {}
    public function available(): bool { return true; }
    public function synchronize(array $connections): void {}
    public function ingest(array $connection, InboundEmail $email, string $raw, DateTimeImmutable $receivedAt): string
    {
        $externalId = MessageIds::externalId($this->assetId, (string) $email->messageId);
        if (isset($this->stored[$externalId])) { $this->records[] = ['outcome' => 'duplicate', 'message_id' => $email->messageId]; return 'duplicate'; }
        $thread = $this->threads->resolve($email, $this->assetId, ConnectionStore::accountAddress($connection), $this);
        $conversation = $thread['conversation_id'] ?? (1000 + count($this->conversations));
        $this->conversations[$conversation] ??= strtolower($email->from['address']);
        $this->stored[$externalId] = $conversation;
        $body = (new InboundBody())->extract($email);
        $this->records[] = ['outcome' => 'recorded', 'rule' => $thread['rule'], 'conversation' => $conversation, 'message_id' => $email->messageId] + $body;
        return 'recorded';
    }
    public function ingestUnreadable(array $connection, string $raw, DateTimeImmutable $receivedAt): string { $this->records[] = ['outcome' => 'unreadable']; return 'unreadable'; }
    public function inboundStatus(array $connection, string $status, ?string $since): void {}
    public function uncertainMessageIds(array $connection): array { return []; }
    public function confirmSent(array $connection, string $messageId): bool { return false; }
    public function hasConversation(int $conversationId): bool { return isset($this->conversations[$conversationId]); }
    public function conversationForExternalIds(array $externalIds): ?int { foreach ($externalIds as $id) { if (isset($this->stored[$id])) { return $this->stored[$id]; } } return null; }
    public function conversationForRecipient(string $recipient): ?int { $found = array_search($recipient, $this->conversations, true); return $found === false ? null : $found; }
}

/** Polls until $done() or ten seconds pass: GreenMail delivers asynchronously. */
function eventually(callable $poll, callable $done): void
{
    for ($i = 0; $i < 40; ++$i) { $poll(); if ($done()) { return; } usleep(250000); }
    throw new RuntimeException('Timed out waiting for GreenMail');
}

$run = bin2hex(random_bytes(4));
$root = sys_get_temp_dir().'/multimail_greenmail_e2e_'.$run;
mkdir($root.'/project', 0700, true);
$project = $root.'/project';
try {
    $store = new ConnectionStore($project);
    $input = ['provider' => 'smtp', 'name' => 'Contato GreenMail', 'from_email' => 'contato@macro.test', 'from_name' => 'Macro Markets', 'reply_to' => '', 'fallback' => '',
        'conversational' => '1', 'settings' => ['host' => '127.0.0.1', 'port' => '465', 'encryption' => 'ssl', 'username' => 'contato@macro.test',
        'imap_host' => '127.0.0.1', 'imap_port' => '993', 'imap_folder' => 'INBOX'], 'secrets' => ['password' => 'secret']];
    $view = $store->save($input, 0, 1);
    $id = $view['connections'][0]['id'];
    [$connection] = $store->conversationalConnections();
    $tokens = new ThreadToken('greenmail-e2e-secret');
    $sender = new ConversationalSender($store, new ConnectionBuilder());
    $bridge = new MemoryThreadBridge(new ThreadResolver($tokens), 31);
    $bridge->conversations[812] = 'lead@example.test';
    $states = new ImapStateStore($project);
    $poller = new MailboxPoller($store, $bridge, $states, new InboundParser(), new ImapConnector(), $project);

    e2e($poller->poll()[$id]['status'] === 'initialized', 'First pass starts at UIDNEXT');

    // Outgoing over real SMTPS with the token in Reply-To and an explicit Message-ID.
    $messageId = MessageIds::generate(31, 'contato@macro.test');
    $bridge->stored[MessageIds::externalId(31, $messageId)] = 812; // What the dispatcher persists before sending.
    $replyTo = OutboundEmailBuilder::replyTo('contato@macro.test', $tokens->encode(31, 812));
    $subject = 'Por que você ainda não depositou? '.$run;
    $outcome = $sender->send($connection, (new OutboundEmailBuilder())->build(['from_email' => 'contato@macro.test', 'from_name' => 'Ana · Macro Markets',
        'reply_to' => $replyTo, 'to_email' => 'lead@example.test', 'to_name' => 'João Lead', 'subject' => $subject,
        'text' => "Oi **João**, o depósito mínimo é R$ 100.", 'message_id' => $messageId]));
    e2e($outcome->status === SendOutcome::ACCEPTED, 'GreenMail accepted the conversational send: '.$outcome->reason);

    // The lead's mailbox over real IMAPS.
    $lead = ImapClient::open('127.0.0.1', 993);
    $lead->login('lead@example.test', 'secret');
    $uids = [];
    eventually(function () use ($lead, $messageId, &$uids): void { $lead->examine('INBOX'); $uids = $lead->uidsByMessageId($messageId); },
        function () use (&$uids): bool { return $uids !== []; });
    $raw = $lead->fetch([max($uids)], 'body')[max($uids)]['raw'];
    $lead->logout();
    $received = (new InboundParser())->parse($raw);
    e2e($received->messageId === $messageId && $received->subject === $subject, 'Message-ID and subject survive the trip');
    e2e(str_starts_with($received->text, OutboundEmailBuilder::SENTINEL) && str_contains($received->html, '<strong>João</strong>'), 'Sentinel and HTML part');
    e2e(preg_match('/^Reply-To: contato\+(r[a-z2-7]{24})@macro\.test\r?$/mi', $raw, $match) === 1 && $tokens->decode($match[1], 31) === 812, 'Token in Reply-To decodes to the conversation');

    // The lead answers. GreenMail has no +tags, so play the MTA: envelope to the base mailbox, +tag in To/Delivered-To.
    $leadSmtp = (new ConnectionBuilder())->build(['provider' => 'smtp', 'settings' => ['host' => '127.0.0.1', 'port' => '465', 'encryption' => 'ssl', 'username' => 'lead@example.test'],
        'secrets' => ['password' => 'secret']]);
    $reply = static function (string $to, string $text, string $tag) use ($messageId, $subject): Email {
        $email = (new Email())->from(new Address('lead@example.test', 'João Lead'))->to($to)->subject('Re: '.$subject)->text($text);
        $email->getHeaders()->addIdHeader('Message-ID', $tag.'@example.test');
        $email->getHeaders()->addIdHeader('In-Reply-To', trim($messageId, '<>'));
        $email->getHeaders()->addIdHeader('References', [trim($messageId, '<>')]);
        $email->getHeaders()->addTextHeader('Delivered-To', $to);
        return $email;
    };
    $envelope = new Envelope(new Address('lead@example.test'), [new Address('contato@macro.test')]);
    $leadSmtp->transport->send($reply($replyTo, "Vou depositar amanhã.\n\nEm 24/10/2026, Ana · Macro Markets <$replyTo> escreveu:\n> ".OutboundEmailBuilder::SENTINEL."\n>\n> Oi João", 'reply-token-'.$run), $envelope);
    eventually(fn() => $poller->poll(), fn(): bool => count($bridge->records) >= 1);
    $record = $bridge->records[0];
    e2e($record['outcome'] === 'recorded' && $record['rule'] === 'token' && $record['conversation'] === 812, 'Reply threaded by token: '.json_encode($record));
    e2e($record['text'] === 'Vou depositar amanhã.' && str_contains($record['quoted'], OutboundEmailBuilder::SENTINEL), 'Quote cut at the sentinel');

    // No +tag at all: In-Reply-To still finds the conversation.
    $leadSmtp->transport->send($reply('contato@macro.test', 'Outra pergunta: qual o prazo?', 'reply-headers-'.$run), $envelope);
    eventually(fn() => $poller->poll(), fn(): bool => count($bridge->records) >= 2);
    e2e($bridge->records[1]['rule'] === 'headers' && $bridge->records[1]['conversation'] === 812, 'Reply threaded by In-Reply-To');
    $leadSmtp->close();

    // UIDVALIDITY changes when the folder is recreated: re-read 7 days, deduplicate.
    $folder = 'Conversas '.$run;
    $admin = ImapClient::open('127.0.0.1', 993);
    $admin->login('contato@macro.test', 'secret');
    $admin->execute('CREATE', $folder);
    $view = $store->save(array_replace_recursive($input, ['id' => $id, 'settings' => ['imap_folder' => $folder], 'secrets' => ['password' => '']]), $view['revision'], 1);
    [$connection] = $store->conversationalConnections();
    e2e($poller->poll()[$id]['status'] === 'initialized', 'New folder starts at its UIDNEXT');
    $mail = static fn(string $tag): string => "From: João Lead <lead@example.test>\r\nTo: contato@macro.test\r\nSubject: $tag\r\nMessage-ID: <$tag@example.test>\r\nIn-Reply-To: $messageId\r\nDate: ".gmdate(DATE_RFC2822)."\r\n\r\nCorpo $tag\r\n";
    $admin->append($folder, $mail('folder-a-'.$run));
    eventually(fn() => $poller->poll(), fn(): bool => count($bridge->records) >= 3);
    $uidvalidity = $states->get($id)['uidvalidity'];
    // GreenMail 2.1.3 drops the connection on DELETE of a folder that earlier sessions examined
    // (stale listener, NullPointerException); moving it away renumbers the name just the same.
    sleep(1); // UIDVALIDITY is the creation second.
    $admin->execute('RENAME', $folder, $folder.' old');
    $admin->execute('CREATE', $folder);
    $admin->append($folder, $mail('folder-a-'.$run));
    $admin->append($folder, $mail('folder-b-'.$run));
    eventually(fn() => $poller->poll(), fn(): bool => count($bridge->records) >= 5);
    e2e($states->get($id)['uidvalidity'] !== $uidvalidity, 'GreenMail gave the recreated folder a new UIDVALIDITY');
    e2e(array_column(array_slice($bridge->records, 2), 'outcome') === ['recorded', 'duplicate', 'recorded']
        && $bridge->records[4]['message_id'] === '<folder-b-'.$run.'@example.test>', 'Resync re-reads, deduplicates and stores only the new message');
    $admin->logout();

    // Round trip: GreenMail keeps contato+teste…@ in a separate mailbox, exactly like a server without +tags.
    $result = (new RoundTripTester($store, $sender, new ImapConnector(), new InboundParser()))->run($id, $view['revision'], 6);
    e2e($result['status'] === 'not_arrived' && $result['plus_addressing'] === false && $store->overview()['connections'][0]['plus_addressing'] === false, 'Round trip without +tag support: '.json_encode($result));
} finally {
    $remove = function (string $path) use (&$remove): void {
        foreach (new FilesystemIterator($path) as $item) { $item->isDir() && !$item->isLink() ? $remove($item->getPathname()) : unlink($item->getPathname()); }
        rmdir($path);
    };
    $remove($root);
}
echo "PASS: GreenMail e2e over verified TLS: SMTPS send with token and Message-ID, IMAPS read and parse, reply threaded by token and by In-Reply-To, sentinel cut, UIDVALIDITY change with 7-day resync and dedup, round trip without +tag\n";
