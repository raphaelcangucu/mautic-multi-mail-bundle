<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Meta/Doctrine stand-ins, simulated SMTP and a temporary private directory. No kernel, database or network.
require __DIR__.'/bootstrap.php';
require __DIR__.'/Fixtures/MetaStubs.php';

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact;
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use MauticPlugin\MauticMetaBundle\Application\Exception\ChannelTemporarilyUnavailable;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\{MetaAsset, MetaConnection, MetaConversation, MetaMessage, MetaOutboundJob};
use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use MauticPlugin\MauticMultiMailBundle\Conversation\{ConversationalSender, MessageIds, OutboundEmailBuilder, ThreadToken};
use MauticPlugin\MauticMultiMailBundle\Mailer\ConnectionBuilder;
use MauticPlugin\MauticMultiMailBundle\Meta\EmailChannelDispatcher;
use MauticPlugin\MauticMultiMailBundle\Tests\Fixtures\{MemoryEntityManager, RecordingInbox, SimulatedSmtpServer};
use Symfony\Component\Mailer\Exception\TransportException;

function dispatched(bool $ok, string $reason): void { if (!$ok) { throw new RuntimeException($reason); } }
function failsWith(callable $action, string $class, string $reason): Throwable {
    try { $action(); } catch (Throwable $error) {
        dispatched($error::class === $class, $reason.': got '.$error::class.' '.$error->getMessage());
        dispatched(!str_contains($error->getMessage(), 'unit-secret'), $reason.': secret in error');
        return $error;
    }
    throw new RuntimeException($reason.': no exception');
}

$root = sys_get_temp_dir().'/multimail_dispatch_test_'.bin2hex(random_bytes(8));
mkdir($root.'/project', 0700, true);
try {
    $store = new ConnectionStore($root.'/project');
    $input = ['provider' => 'smtp', 'name' => 'Contato', 'from_email' => 'contato@macro.test', 'from_name' => 'Macro Markets', 'reply_to' => '', 'fallback' => '',
        'conversational' => '1', 'settings' => ['host' => 'smtp.macro.test', 'port' => '465', 'encryption' => 'ssl', 'username' => 'contato@macro.test', 'imap_host' => 'imap.macro.test'],
        'secrets' => ['password' => 'unit-secret-smtp']];
    $view = $store->save($input, 0, 1);
    $connectionId = $view['connections'][0]['id'];
    $servers = [];
    $script = static fn(SimulatedSmtpServer $server): SimulatedSmtpServer => $server;
    $builder = new ConnectionBuilder(null, function (array $connection) use (&$servers, &$script): SimulatedSmtpServer {
        return $servers[] = $script(new SimulatedSmtpServer());
    });
    $em = new MemoryEntityManager();
    $inbox = new RecordingInbox();
    $dnc = new DoNotContact();
    $tokens = new ThreadToken('unit-secret');
    $dispatcher = new EmailChannelDispatcher($em, new ConversationalSender($store, $builder), new OutboundEmailBuilder(), $tokens, new ConversationManager($em, $inbox), $dnc);

    $internal = (new MetaConnection())->setName('E-mail (Multi Mail)')->setAppId('multimail-email')->setStatus('inactive');
    $asset = (new MetaAsset())->setConnection($internal)->setExternalId($connectionId)->setType(AssetType::EmailAccount)->setName('Contato')->setStatus('active');
    $lead = new Lead('joao@example.com', 'João Lead');
    $conversation = (new MetaConversation())->setAsset($asset)->setChannel('email')->setRecipient('joao@example.com')->setContact($lead);
    foreach ([$internal, $asset, $lead, $conversation] as $entity) { $em->persist($entity); }
    $em->flush();
    $previous = (new MetaMessage())->setAsset($asset)->setConversation($conversation)->setChannel('email')->setDirection('inbound')->setRecipient('joao@example.com')
        ->setStatus('received')->setExternalId(MessageIds::externalId($asset->getId(), '<lead-1@example.com>'))
        ->setPayload(['email' => ['message_id' => '<lead-1@example.com>', 'references' => ['<a@x>'], 'kind' => 'message']]);
    $autoReply = (new MetaMessage())->setAsset($asset)->setConversation($conversation)->setChannel('email')->setDirection('inbound')->setRecipient('joao@example.com')
        ->setStatus('received')->setPayload(['email' => ['message_id' => '<ooo@example.com>', 'kind' => 'auto_reply']])->setDateAdded(new DateTimeImmutable('+1 second'));
    $em->persist($previous); $em->persist($autoReply); $em->flush();
    $job = static function (array $payload = []) use ($asset, $lead, $conversation, $em): MetaOutboundJob {
        $job = (new MetaOutboundJob())->setAsset($asset)->setContact($lead)->setOperation('email_message')->setPayload($payload + ['recipient' => 'Joao@Example.com',
            'subject' => 'Re: Por que você ainda não depositou?', 'text' => "Oi **João**, o mínimo é R$ 100.\n[Formulário](https://macro.test/inbox/f/t)", 'author_name' => 'Ana',
            'from_name' => null, 'unsubscribe_url' => null, '_origin' => 'inbox_human', '_inbox_conversation_id' => $conversation->getId()]);
        $em->persist($job); $em->flush();
        return $job;
    };

    // Accepted: Message-ID stored on the job and pending message persisted before the body is sent.
    $first = $job();
    $before = null;
    $script = function (SimulatedSmtpServer $server) use (&$before, $em, $first): SimulatedSmtpServer {
        $server->onDataEnd = function () use (&$before, $em, $first): void {
            $pending = array_filter($em->objects[MetaMessage::class] ?? [], fn(MetaMessage $message): bool => $message->getDirection() === 'outbound' && $message->getStatus() === 'pending');
            $before = count($pending) === 1 && isset($first->getPayload()['_email_message_id']) && $em->flushes > 0;
        };
        return $server;
    };
    $message = $dispatcher->dispatch($first);
    $messageId = $first->getPayload()['_email_message_id'];
    dispatched($before === true, 'Message-ID and pending message stored before DATA');
    dispatched(preg_match('/^<[a-f0-9]{32}\.'.$asset->getId().'@macro\.test>$/D', $messageId) === 1, 'Message-ID format');
    dispatched($message->getStatus() === 'sent' && $message->getExternalId() === MessageIds::externalId($asset->getId(), $messageId), 'Scoped external id, sent');
    dispatched($message->getConversation() === $conversation && $message->getRecipient() === 'joao@example.com' && $message->getChannel() === 'email' && $message->getMessageType() === 'email', 'Message fields');
    dispatched($inbox->persisted === [$message], 'record() notifies the Inbox of the outgoing message');
    $data = $servers[0]->data;
    dispatched(str_contains($data, 'Message-ID: '.$messageId) && str_contains($data, 'In-Reply-To: <lead-1@example.com>') && str_contains($data, 'References: <a@x> <lead-1@example.com>'), 'Thread headers from the last real inbound message: '.substr($data, 0, 600));
    preg_match('/^Reply-To: contato\+(r[a-z2-7]{24})@macro\.test\r$/m', $data, $reply);
    dispatched(isset($reply[1]) && $tokens->decode($reply[1], $asset->getId()) === $conversation->getId(), 'Reply-To token points at the conversation');
    dispatched(str_contains(iconv_mime_decode(preg_match('/^From: (.+)\r$/m', $data, $from) ? $from[1] : '', 0, 'UTF-8'), 'Ana · Macro Markets'), 'From is agent · connection');
    dispatched(!str_contains($data, 'List-Unsubscribe') && str_contains($data, '##- Responda acima desta linha -##'), 'Human reply: sentinel, no List-Unsubscribe');
    dispatched(in_array('RCPT TO:<joao@example.com>', $servers[0]->commands, true) && in_array('MAIL FROM:<contato@macro.test>', $servers[0]->commands, true), 'Envelope');
    $payload = $message->getPayload()['email'];
    dispatched($payload['from'] === ['name' => 'Ana · Macro Markets', 'address' => 'contato@macro.test'] && $payload['to'] === [['name' => 'João Lead', 'address' => 'joao@example.com']]
        && $payload['text'] === $first->getPayload()['text'] && str_contains($payload['html'], '<strong>João</strong>') && $payload['kind'] === 'message' && $payload['subject'] === 'Re: Por que você ainda não depositou?', 'Outgoing payload contract');
    dispatched($store->overview()['connections'][0]['hourly']['accepted'] === 1, 'Quota reservation finished as accepted');

    // The Inbox "try again" copies the payload: the accepted message is not sent twice.
    $copy = $job($first->getPayload());
    $count = count($servers);
    dispatched($dispatcher->dispatch($copy) === $message && count($servers) === $count, 'Accepted Message-ID is never resent');

    // 4xx: retry later with the same Message-ID and the same MetaMessage.
    $temporary = $job();
    $script = function (SimulatedSmtpServer $server): SimulatedSmtpServer { $server->replies['rcpt'] = "450 4.2.0 Mailbox busy, try later\r\n"; return $server; };
    failsWith(fn() => $dispatcher->dispatch($temporary), ChannelTemporarilyUnavailable::class, '4xx retries');
    $retryId = $temporary->getPayload()['_email_message_id'];
    $pending = $em->getRepository(MetaMessage::class)->findOneBy(['externalId' => MessageIds::externalId($asset->getId(), $retryId)]);
    dispatched($pending->getStatus() === 'pending' && str_contains((string) $pending->getError(), '450 4.2.0'), 'Pending with the server reason');
    $script = fn(SimulatedSmtpServer $server): SimulatedSmtpServer => $server;
    dispatched($dispatcher->dispatch($temporary) === $pending && $pending->getStatus() === 'sent' && str_contains(end($servers)->data, 'Message-ID: '.$retryId), 'Retry reuses Message-ID and message');

    // 5xx for the recipient: definitive failure with the server's text.
    $permanent = $job(['recipient' => 'lost@example.com', '_inbox_conversation_id' => null]);
    $script = function (SimulatedSmtpServer $server): SimulatedSmtpServer { $server->replies['rcpt'] = "550 5.1.1 mailbox not found\r\n"; return $server; };
    $error = failsWith(fn() => $dispatcher->dispatch($permanent), DomainException::class, '5xx fails');
    dispatched(str_contains($error->getMessage(), '550 5.1.1 mailbox not found'), 'Reason visible on the card');
    $failed = $em->getRepository(MetaMessage::class)->findOneBy(['recipient' => 'lost@example.com', 'direction' => 'outbound']);
    dispatched($failed->getStatus() === 'failed' && $failed->getConversation()?->getRecipient() === 'lost@example.com', 'A conversation is created before sending, so the token has an id');
    $quota = $store->overview()['connections'][0]['hourly'];
    dispatched($quota['rejected'] === 2 && $quota['accepted'] === 2, 'Refusals release capacity');

    // Timeout after DATA: uncertain, never retried automatically.
    $uncertain = $job();
    $script = function (SimulatedSmtpServer $server): SimulatedSmtpServer { $server->replies['data_end'] = new TransportException('unit-secret timeout'); return $server; };
    failsWith(fn() => $dispatcher->dispatch($uncertain), RuntimeException::class, 'Timeout after DATA is uncertain');
    $unknown = $em->getRepository(MetaMessage::class)->findOneBy(['externalId' => MessageIds::externalId($asset->getId(), $uncertain->getPayload()['_email_message_id'])]);
    dispatched($unknown->getStatus() === 'pending' && $unknown->getResponse()['delivery'] === 'uncertain' && $unknown->getResponse()['job_id'] === $uncertain->getId(), 'Uncertain message waits for the Sent folder');
    dispatched($uncertain->messageLogId === $unknown->getId() && $permanent->messageLogId === $failed->getId(), 'Unfinished jobs already point at their message, so the Inbox never shows it twice');
    $script = fn(SimulatedSmtpServer $server): SimulatedSmtpServer => $server;

    // Campaign: action sender name and one-click unsubscribe; no +tag when the mailbox refused it.
    $store->recordPlusAddressing($connectionId, false);
    $campaign = $job(['_origin' => 'inbox_campaign', 'from_name' => 'Equipe Macro', 'unsubscribe_url' => 'https://macro.test/inbox/u/signed', 'author_name' => null]);
    $dispatcher->dispatch($campaign);
    $data = end($servers)->data;
    dispatched(str_contains($data, "List-Unsubscribe: <https://macro.test/inbox/u/signed>\r\n") && str_contains($data, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'), 'Campaign List-Unsubscribe');
    dispatched(str_contains($data, "Reply-To: contato@macro.test\r\n") && str_contains($data, 'From: Equipe Macro <contato@macro.test>'), 'No token without plus addressing; campaign From name');

    // Refusals before any SMTP session.
    $count = count($servers);
    $dnc->blocked = [$lead];
    failsWith(fn() => $dispatcher->dispatch($job()), DomainException::class, 'Email DNC');
    $dnc->blocked = [];
    failsWith(fn() => $dispatcher->dispatch($job(['recipient' => 'not an address'])), InvalidArgumentException::class, 'Invalid recipient');
    failsWith(fn() => $dispatcher->dispatch($job(['subject' => ''])), InvalidArgumentException::class, 'Empty subject');
    $asset->setStatus('inactive');
    failsWith(fn() => $dispatcher->dispatch($job()), DomainException::class, 'Inactive account');
    $asset->setStatus('active');
    $store->save(array_replace($input, ['id' => $connectionId, 'conversational' => '0', 'secrets' => ['password' => '']]), $store->overview()['revision'], 1);
    failsWith(fn() => $dispatcher->dispatch($job()), DomainException::class, 'Connection no longer conversational');
    $store->save(array_replace($input, ['id' => $connectionId, 'hourly_limit' => 1, 'secrets' => ['password' => '']]), $store->overview()['revision'], 1);
    $error = failsWith(fn() => $dispatcher->dispatch($job()), ChannelTemporarilyUnavailable::class, 'Hourly quota retries');
    dispatched(str_contains($error->getMessage(), 'Nova tentativa a partir de'), 'Quota retry time in the reason');
    chmod($root.'/project-multimail-private/connections.json', 0644);
    failsWith(fn() => $dispatcher->dispatch($job()), ChannelTemporarilyUnavailable::class, 'Unreadable private registry retries, never uncertain');
    chmod($root.'/project-multimail-private/connections.json', 0600);
    $asset->setExternalId(str_repeat('f', 32));
    failsWith(fn() => $dispatcher->dispatch($job()), DomainException::class, 'Removed connection fails, never uncertain');
    dispatched(count($servers) === $count, 'No SMTP session for refusals, quota or removed connections');
} finally {
    foreach (glob($root.'/project-multimail-private/*') ?: [] as $file) { unlink($file); }
    if (is_dir($root.'/project-multimail-private')) { rmdir($root.'/project-multimail-private'); }
    rmdir($root.'/project'); rmdir($root);
}
echo "PASS: email_message dispatcher: Message-ID/pending stored before DATA, scoped id, thread headers, token Reply-To, agent From, no resend after acceptance, 4xx retry with same id, 5xx failure text, uncertain after DATA, campaign unsubscribe, no +tag fallback, DNC/inactive/removed/quota refusals and secret-safe errors; no kernel/database/network\n";
