<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Meta/Doctrine stand-ins, the .eml corpus, a fake clamd child process and a temporary private directory.
require __DIR__.'/bootstrap.php';
require __DIR__.'/Fixtures/MetaStubs.php';

if (($argv[1] ?? '') === 'clamd-child') {
    // Minimal clamd: INSTREAM chunks until a zero length, then OK or FOUND for EICAR content.
    $server = stream_socket_server('unix://'.$argv[2], $errno, $error);
    while ($client = @stream_socket_accept($server, 30)) {
        $command = fread($client, 10);
        $content = '';
        while (strlen($size = fread($client, 4)) === 4 && ($length = unpack('N', $size)[1]) > 0) {
            $chunk = '';
            while (strlen($chunk) < $length) { $chunk .= fread($client, $length - strlen($chunk)); }
            $content .= $chunk;
        }
        fwrite($client, $command === "zINSTREAM\0" && !str_contains($content, 'EICAR') ? "stream: OK\0" : "stream: Eicar-Test-Signature FOUND\0");
        fclose($client);
    }
    exit;
}

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\{MetaAsset, MetaConnection, MetaConversation, MetaMessage, MetaOutboundJob};
use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use MauticPlugin\MauticMultiMailBundle\Conversation\{BlobStore, ClamdScanner, InboundBody, InboundParser, MailBlobs, MessageIds, ThreadResolver, ThreadToken};
use MauticPlugin\MauticMultiMailBundle\Inbox\EmailContentProvider;
use MauticPlugin\MauticMultiMailBundle\Meta\{EmailIngestor, MetaConversationBridge};
use MauticPlugin\MauticMultiMailBundle\Tests\Fixtures\{MemoryEntityManager, RecordingInbox};

function ingested(bool $ok, string $reason): void { if (!$ok) { throw new RuntimeException($reason); } }

$root = sys_get_temp_dir().'/multimail_ingest_test_'.bin2hex(random_bytes(8));
mkdir($root.'/project', 0700, true);
$clamd = null;
try {
    $socket = $root.'/clamd.sock';
    $pipes = [];
    $clamd = proc_open([PHP_BINARY, __FILE__, 'clamd-child', $socket], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    for ($i = 0; $i < 50 && !file_exists($socket); ++$i) { usleep(100000); }
    ingested(file_exists($socket), 'Fake clamd started');

    $project = $root.'/project';
    $store = new ConnectionStore($project);
    $smtp = ['provider' => 'smtp', 'name' => 'Contato', 'from_email' => 'contato@macro.test', 'from_name' => 'Macro Markets', 'reply_to' => '', 'fallback' => '',
        'conversational' => '1', 'settings' => ['host' => 'smtp.macro.test', 'port' => '465', 'encryption' => 'ssl', 'username' => 'contato@macro.test', 'imap_host' => 'imap.macro.test'],
        'secrets' => ['password' => 'unit-secret']];
    $view = $store->save($smtp, 0, 1);
    $view = $store->save(array_replace($smtp, ['name' => 'Transport only', 'conversational' => '0']), 1, 1);
    [$connection] = $store->conversationalConnections();
    $em = new MemoryEntityManager();
    $inbox = new RecordingInbox();
    $conversations = new ConversationManager($em, $inbox);
    $tokens = new ThreadToken('unit-secret');
    $blobStore = new BlobStore($project);
    $blobs = new MailBlobs($blobStore, new ClamdScanner(new CoreParametersHelper(['multimail_clamd_socket' => 'unix://'.$socket])));
    $ingestor = new EmailIngestor($em, $conversations, $inbox, new ThreadResolver($tokens), new InboundBody(), $blobs);
    $bridge = new MetaConversationBridge($em, $ingestor, $conversations, $inbox);
    $provider = new EmailContentProvider($blobs);
    $parser = new InboundParser();

    // Assets: one per conversational connection, under one internal inactive MetaConnection.
    ingested($bridge->available(), 'Meta bridge available');
    $bridge->synchronize($store->overview()['connections']);
    $bridge->synchronize($store->overview()['connections']);
    $internals = array_values($em->objects[MetaConnection::class]);
    $assets = array_values($em->objects[MetaAsset::class]);
    ingested(count($internals) === 1 && $internals[0]->appId === 'multimail-email' && $internals[0]->businessId === '' && $internals[0]->status === 'inactive'
        && $internals[0]->encryptedAccessToken === '', 'One internal inactive connection without secrets');
    ingested(count($assets) === 1 && $assets[0]->externalId === $connection['id'] && $assets[0]->getType() === AssetType::EmailAccount && $assets[0]->status === 'active' && $assets[0]->isPublished, 'One active published email asset');
    $asset = $assets[0];
    ingested($asset->settings === ['email_inbound_status' => 'ok', 'email_inbound_since' => null, 'email_address' => 'contato@macro.test', 'email_from_name' => 'Macro Markets', 'email_plus_addressing' => true], 'Asset settings contract: '.json_encode($asset->settings));
    ingested(count($em->locks) === 4 && str_contains($em->locks[0], 'GET_LOCK'), 'Synchronization serialized with a named lock');
    $asset->setSettings($asset->getSettings() + ['inbox_default_assignee_id' => 7]);
    $bridge->synchronize([]);
    ingested($asset->status === 'inactive' && count($em->objects[MetaAsset::class]) === 1, 'Removed connection: asset inactive, never deleted');
    $bridge->synchronize($store->overview()['connections']);
    ingested($asset->status === 'active' && ($asset->settings['inbox_default_assignee_id'] ?? null) === 7, 'Reactivated, foreign settings kept');

    $lead = new Lead('joao@example.com', 'João Lead');
    $rita = new Lead('rita@example.net', 'Rita');
    $twinA = new Lead('twin@example.com'); $twinB = new Lead('twin@example.com');
    $joao = (new MetaConversation())->setAsset($asset)->setChannel('email')->setRecipient('joao@example.com')->setContact($lead);
    foreach ([$lead, $rita, $twinA, $twinB, $joao] as $entity) { $em->persist($entity); }
    $em->flush();
    $token = $tokens->encode($asset->getId(), $joao->getId());
    $fixture = static function (string $name, string $ourId = '<0123456789abcdef0123456789abcdef.31@macro.test>') use ($token): string {
        return str_replace(["\r\n", "\n", '{{TOKEN}}', '{{OUR_ID}}', '{{OUR_ID_BARE}}'], ["\n", "\r\n", $token, $ourId, trim($ourId, '<>')], file_get_contents(__DIR__.'/Fixtures/mail/'.$name.'.eml'));
    };
    $ingest = static fn(string $raw, string $date = '2026-10-23 10:00:00') =>$bridge->ingest($connection, $parser->parse($raw), $raw, new DateTimeImmutable($date.' UTC'));
    $last = static fn(): MetaMessage => end($em->objects[MetaMessage::class]);

    // Token in the +tag: the reply lands in the right conversation and wakes the Inbox.
    ingested($ingest($fixture('gmail-web-pt')) === 'recorded', 'Gmail reply recorded');
    $message = $last();
    $email = $message->getPayload()['email'];
    ingested($message->getConversation() === $joao && $message->getDirection() === 'inbound' && $message->getStatus() === 'received' && $message->getMessageType() === 'email'
        && $message->getChannel() === 'email' && $message->getContact() === $lead, 'Inbound message fields');
    ingested($message->getExternalId() === MessageIds::externalId($asset->getId(), '<CAF3xGmailWebPt+abc123@mail.gmail.com>'), 'Scoped external id');
    ingested($message->getDateAdded()->format('Y-m-d H:i') === '2026-10-23 10:00', 'INTERNALDATE as date');
    ingested($email['to'] === [['name' => 'Ana · Macro Markets', 'address' => 'contato@macro.test']] && !str_contains(json_encode($email), $token), 'The token never reaches the payload');
    ingested($email['text'] === 'Oi Ana, ainda estou decidindo. *Qual o depósito mínimo?*' && str_starts_with($email['quoted'], '> Em qui.') && $email['kind'] === 'message'
        && $email['has_original'] === true && $email['auto_reply'] === false && $email['unverified_sender'] === false && $email['subject'] === 'Re: Por que você ainda não depositou?', 'Payload contract');
    ingested(array_keys($email) === ['message_id', 'in_reply_to', 'references', 'from', 'to', 'cc', 'subject', 'text', 'quoted', 'html', 'has_original', 'original', 'auto_reply', 'unverified_sender', 'kind', 'attachments'], 'Payload keys');
    ingested(str_contains((string) $provider->originalHtml($message), 'gmail_quote') && $provider->supports($message), 'Full original HTML from the private blob');
    ingested($inbox->persisted === [$message] && $joao->getUnreadCount() === 1 && $joao->getStatus() === 'open', 'record() then messagePersisted()');
    ingested($ingest($fixture('gmail-web-pt')) === 'duplicate' && count($inbox->persisted) === 1, 'Second read is not a second message');
    $future = $parser->parse(str_replace('CAF3xGmailWebPt', 'Future', $fixture('gmail-web-pt')));
    $bridge->ingest($connection, $future, '', new DateTimeImmutable('+2 days'));
    ingested($last()->getDateAdded() <= new DateTimeImmutable(), 'Never dated in the future');

    // Reply from another address: the token keeps the original conversation and recipient.
    ingested($ingest($fixture('gmail-ios-en')) === 'recorded' && $last()->getConversation() === $joao && $last()->getRecipient() === 'joao@example.com'
        && $last()->getPayload()['email']['from']['address'] === 'john.lead@example.org', 'Other-address reply stays in the thread');

    // Attachments: allowed and scanned clean, refused type, infected content.
    ingested($ingest($fixture('html-only-pt')) === 'recorded', 'HTML-only with attachments');
    $files = $last()->getPayload()['email']['attachments'];
    ingested(array_map(fn(array $file): string => $file['index'].':'.$file['type'].':'.$file['mime'].':'.($file['available'] ? 'yes' : 'no'), $files)
        === ['0:image:image/png:yes', '1:document:application/pdf:yes', '2:document:application/x-dosexec:no'] || array_column($files, 'available') === [true, true, false], 'Attachment policy: '.json_encode($files));
    ingested($files[0]['cid'] === 'logo-001@example.com' && $files[2]['blob'] === null, 'cid kept; refused type not stored');
    $pdf = $provider->attachment($last(), 1);
    ingested($pdf !== null && $pdf['mime'] === 'application/pdf' && $pdf['name'] === 'extrato.pdf' && str_starts_with((string) file_get_contents($pdf['path']), '%PDF'), 'Verified PDF file for the Inbox proxy');
    ingested($provider->attachment($last(), 2) === null && $provider->attachment($last(), 9) === null && $provider->attachment($last(), -1) === null, 'Unavailable or unknown attachments are null');
    $eicar = "From: Joao <joao@example.com>\r\nTo: contato+$token@macro.test\r\nSubject: virus\r\nMessage-ID: <eicar@example.com>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=b\r\n\r\n--b\r\nContent-Type: text/plain\r\n\r\nveja\r\n--b\r\nContent-Type: text/plain; name=leia.txt\r\nContent-Disposition: attachment; filename=leia.txt\r\n\r\nX5O!P%@AP EICAR test\r\n--b--\r\n";
    $ingest($eicar);
    ingested($last()->getPayload()['email']['attachments'][0]['available'] === false && $last()->getPayload()['email']['attachments'][0]['blob'] === null, 'Infected attachment never stored');
    $noScanner = new MailBlobs($blobStore, new ClamdScanner());
    $unscanned = $noScanner->attachments($parser->parse($fixture('html-only-pt'))->attachments);
    $fresh = $blobStore->put("%PDF-1.4\n% never scanned\n", ['kind' => 'attachment', 'mime' => 'application/pdf', 'name' => 'novo.pdf', 'scan' => ClamdScanner::UNAVAILABLE]);
    ingested($unscanned[1]['available'] === false && $noScanner->attachmentFile($fresh) === null && $blobs->attachmentFile($fresh) === null, 'Without clamd nothing is available or served');

    // Auto-reply: an event, not a wake-up.
    $unread = $joao->getUnreadCount();
    $joao->setStatus('resolved'); $em->persist($joao);
    $persisted = count($inbox->persisted);
    ingested($ingest($fixture('auto-reply-pt')) === 'auto_reply', 'Auto-reply classified');
    ingested($joao->getUnreadCount() === $unread && $joao->getStatus() === 'resolved' && count($inbox->persisted) === $persisted && $last()->getPayload()['email']['kind'] === 'auto_reply'
        && $last()->getPayload()['email']['auto_reply'] === true, 'No unread, no reopening, no Inbox wake-up');
    $joao->setStatus('open'); $em->persist($joao);
    ingested($ingest($fixture('outlook-ooo-en')) === 'ignored' && $ingest($fixture('list-en')) === 'ignored', 'Auto-reply or bulk without a conversation is ignored');
    ingested($ingest($fixture('echo', '<9999999999abcdef0123456789abcdef.'.$asset->getId().'@macro.test>')) === 'echo', 'Our own copy is an echo');

    // Headers rule: In-Reply-To of one of our messages.
    $peter = (new MetaConversation())->setAsset($asset)->setChannel('email')->setRecipient('peter@corp.example');
    $em->persist($peter); $em->flush();
    $ours = '<aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.'.$asset->getId().'@macro.test>';
    $sent = (new MetaMessage())->setAsset($asset)->setConversation($peter)->setChannel('email')->setDirection('outbound')->setRecipient('peter@corp.example')->setStatus('sent')
        ->setExternalId(MessageIds::externalId($asset->getId(), $ours))->setPayload(['email' => ['message_id' => $ours]]);
    $em->persist($sent); $em->flush();
    ingested($ingest($fixture('outlook-desktop-en', $ours)) === 'recorded' && $last()->getConversation() === $peter, 'In-Reply-To matches our Message-ID');

    // New sender: new conversation, contact only by a unique match.
    ingested($ingest($fixture('interleaved-en')) === 'recorded' && $last()->getConversation()?->getRecipient() === 'rita@example.net' && $last()->getContact() === $rita, 'New conversation with the unique contact');
    $twin = str_replace(['rita@example.net', 'interleaved-0001'], ['twin@example.com', 'twin-0001'], $fixture('interleaved-en'));
    ingested($ingest($twin) === 'recorded' && $last()->getContact() === null, 'Two contacts with the address: none is linked');
    ingested($ingest($fixture('dmarc-fail')) === 'recorded' && $last()->getPayload()['email']['unverified_sender'] === true, 'DMARC failure on a matched contact');

    // Bounce: the outgoing message fails and the conversation gets an event.
    $lost = (new MetaConversation())->setAsset($asset)->setChannel('email')->setRecipient('lost@example.com');
    $em->persist($lost); $em->flush();
    $bouncedId = '<bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.'.$asset->getId().'@macro.test>';
    $bounced = (new MetaMessage())->setAsset($asset)->setConversation($lost)->setChannel('email')->setDirection('outbound')->setRecipient('lost@example.com')->setStatus('sent')
        ->setExternalId(MessageIds::externalId($asset->getId(), $bouncedId))->setPayload(['email' => ['message_id' => $bouncedId]]);
    $em->persist($bounced); $em->flush();
    ingested($ingest($fixture('bounce-dsn', $bouncedId)) === 'bounce', 'Bounce classified');
    ingested($bounced->getStatus() === 'failed' && str_contains((string) $bounced->getError(), '5.1.1') && $bounced->getPayload()['email']['bounce']['recipient'] === 'lost@example.com', 'Original marked failed with diagnostic');
    ingested($last()->getPayload()['email']['kind'] === 'bounce' && $last()->getConversation() === $lost && $lost->getUnreadCount() === 0, 'Bounce event without unread');

    // Unreadable mail: event where a conversation exists, raw blob kept.
    ingested($bridge->ingestUnreadable($connection, "From: Joao <joao@example.com>\r\nSubject: x\r\n\r\n\x00\x01", new DateTimeImmutable()) === 'unreadable', 'Unreadable event');
    ingested($last()->getPayload()['email']['kind'] === 'unreadable' && str_starts_with((string) $last()->getPayload()['email']['raw'], 'blob:') && $last()->getConversation() === $joao, 'Raw kept privately');
    ingested($bridge->ingestUnreadable($connection, "From: nobody@unknown.example\r\n\r\n\x00", new DateTimeImmutable()) === 'ignored', 'Unknown sender stays out of the Inbox');

    // Oversized: headers only, with the notice.
    $big = $parser->parse("From: Joao <joao@example.com>\r\nTo: contato+$token@macro.test\r\nSubject: Grande\r\nMessage-ID: <big@example.com>\r\n\r\n", true);
    ingested($bridge->ingest($connection, $big, '', new DateTimeImmutable()) === 'recorded' && str_contains($last()->getPayload()['email']['text'], 'grande demais'), 'Too-large notice');

    // Inbound status on the asset.
    $bridge->inboundStatus($connection, 'delayed', '2026-10-24T10:00:00+00:00');
    ingested($asset->settings['email_inbound_status'] === 'delayed' && $asset->settings['email_inbound_since'] === '2026-10-24T10:00:00+00:00', 'Delayed status');
    $bridge->inboundStatus($connection, 'ok', '2026-10-24T10:00:00+00:00');
    ingested($asset->settings['email_inbound_status'] === 'ok' && $asset->settings['email_inbound_since'] === null, 'OK clears since');

    // Uncertain send confirmed by the Sent folder.
    $job = (new MetaOutboundJob())->setAsset($asset)->setStatus('uncertain');
    $em->persist($job); $em->flush();
    $uncertainId = '<cccccccccccccccccccccccccccccccc.'.$asset->getId().'@macro.test>';
    $pending = (new MetaMessage())->setAsset($asset)->setConversation($joao)->setChannel('email')->setDirection('outbound')->setRecipient('joao@example.com')->setStatus('pending')
        ->setExternalId(MessageIds::externalId($asset->getId(), $uncertainId))->setPayload(['email' => ['message_id' => $uncertainId]])->setResponse(['job_id' => $job->getId(), 'delivery' => 'uncertain']);
    $old = (new MetaMessage())->setAsset($asset)->setChannel('email')->setDirection('outbound')->setRecipient('joao@example.com')->setStatus('pending')
        ->setPayload(['email' => ['message_id' => '<old@x>']])->setResponse(['delivery' => 'uncertain'])->setDateAdded(new DateTimeImmutable('-2 days'));
    $em->persist($pending); $em->persist($old); $em->flush();
    ingested($bridge->uncertainMessageIds($connection) === [$uncertainId], 'Only uncertain sends of the last 24 hours are searched');
    ingested($bridge->confirmSent($connection, $uncertainId) && $pending->getStatus() === 'sent' && $job->getStatus() === 'completed' && end($inbox->jobs) === 'completed'
        && end($inbox->persisted) === $pending, 'Sent folder promotes the message and completes the job');
    ingested(!$bridge->confirmSent($connection, $uncertainId), 'Confirmation is idempotent');

    // A hand-deleted asset comes back on the next pass.
    foreach ($em->objects[MetaAsset::class] as $key => $object) { unset($em->objects[MetaAsset::class][$key]); }
    $bridge->inboundStatus($connection, 'ok', null);
    ingested(count($em->objects[MetaAsset::class]) === 1 && reset($em->objects[MetaAsset::class])->status === 'active', 'Missing asset recreated on demand');

    // Private files.
    $private = $root.'/project-multimail-private';
    ingested((fileperms($private.'/mail-blobs') & 0777) === 0700 && (fileperms($pdf['path']) & 0777) === 0600 && (fileperms($pdf['path'].'.json') & 0777) === 0600, 'Blob permissions');
    ingested($blobStore->path('../connections') === null && $blobStore->read(str_repeat('0', 64)) === null && $blobStore->usage()['count'] >= 3, 'Blob paths are sha256 only; usage tracked');
    ingested(!str_contains(json_encode(array_map(fn(MetaMessage $m): array => $m->getPayload(), $em->objects[MetaMessage::class])), 'unit-secret'), 'No credential in payloads');
    ingested($em->unsaved === [], 'Every change is persisted before flush (Mautic DEFERRED_EXPLICIT): '.implode(', ', $em->unsaved));
} finally {
    if (is_resource($clamd)) { proc_terminate($clamd); proc_close($clamd); }
    $remove = function (string $path) use (&$remove): void {
        foreach (new FilesystemIterator($path) as $item) { $item->isDir() && !$item->isLink() ? $remove($item->getPathname()) : unlink($item->getPathname()); }
        rmdir($path);
    };
    $remove($root);
}
echo "PASS: email asset sync (internal inactive connection, activate/deactivate, foreign settings), token/headers/sender/new threading, payload contract without token, original HTML and clamd-checked attachments via the Inbox provider, duplicates, echo, auto-reply/bounce/unreadable events, bulk ignore, unique contact, DMARC, oversize notice, inbound status and Sent confirmation; no kernel/database/network\n";
