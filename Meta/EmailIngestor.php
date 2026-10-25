<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Meta;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use MauticPlugin\MauticMetaBundle\Application\Support\InboxIntegrationInterface;
use MauticPlugin\MauticMetaBundle\Entity\{MetaAsset, MetaConversation, MetaMessage};
use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use MauticPlugin\MauticMultiMailBundle\Conversation\{InboundBody, InboundEmail, MailBlobs, MessageIds, ThreadResolver, ThreadToken, Triage};
use Psr\Log\LoggerInterface;

/**
 * An incoming email becoming a MetaMessage in the right conversation.
 *
 * Same contract as the WhatsApp ingestors: persist, `ConversationManager::record()`, and
 * only then `InboxIntegrationInterface::messagePersisted()` — record() notifies the Inbox
 * for outgoing messages only, and without the call the conversation never wakes up.
 * Auto-replies, bounces and unreadable mail are events: no unread count, no wake-up.
 */
final class EmailIngestor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConversationManager $conversations,
        private readonly InboxIntegrationInterface $inbox,
        private readonly ThreadResolver $threads,
        private readonly InboundBody $bodies,
        private readonly MailBlobs $blobs,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function ingest(MetaAsset $asset, array $connection, InboundEmail $email, string $raw, \DateTimeImmutable $receivedAt): string
    {
        $assetId = (int) $asset->getId();
        $account = ConnectionStore::accountAddress($connection);
        $sender = strtolower($email->from['address']);
        if (Triage::isEcho($email, $account) || ($sender !== '' && ThreadToken::untagged($sender, $account) === $account)) { return 'echo'; }
        $externalId = $email->messageId !== null ? MessageIds::externalId($assetId, $email->messageId)
            : MessageIds::fallbackExternalId($assetId, $sender, $email->date, $email->subject, $email->text.$email->html);
        // Reading twice is allowed (the UID only advances after this write); storing twice is not.
        if ($this->entityManager->getRepository(MetaMessage::class)->findOneBy(['externalId' => $externalId]) instanceof MetaMessage) { return 'duplicate'; }
        if (Triage::kind($email) === Triage::BOUNCE) { return $this->bounce($asset, $email, $externalId, $receivedAt); }
        if ($sender === '') { return 'ignored'; }
        $autoReply = Triage::kind($email) === Triage::AUTO_REPLY;
        $lookup = new DoctrineThreadLookup($this->entityManager, $asset);
        $thread = $this->threads->resolve($email, $assetId, $account, $lookup);
        if ($thread['invalid_token']) {
            $this->logger?->info('multimail: invalid thread token ignored', ['asset' => $assetId, 'rule' => $thread['rule']]);
        }
        // Lists, bulk mail and auto-replies never open a conversation by themselves.
        if ($thread['rule'] === 'new' && ($autoReply || Triage::isBulk($email))) { return 'ignored'; }
        $conversation = $thread['conversation_id'] === null ? null : $lookup->conversation($thread['conversation_id']);
        $contact = $conversation?->getContact() ?? $this->uniqueContact($sender);
        // Quoted attributions repeat our Reply-To: the token must not reach the screen through them either.
        $body = array_map(static fn(string $value): string => self::withoutTokens($value, $account), $this->bodies->extract($email));
        if ($email->truncated) {
            $body['text'] = '_Mensagem grande demais para exibir aqui. Abra-a na caixa de e-mail._';
        }
        $original = $this->blobs->original($email->html);
        $attachments = $this->blobs->attachments($email->attachments);
        $message = (new MetaMessage())
            ->setAsset($asset)
            ->setExternalId($externalId)
            ->setChannel('email')
            ->setDirection('inbound')
            ->setMessageType('email')
            // The conversation keeps its recipient when the lead writes from another address;
            // the card shows the real sender from the payload.
            ->setRecipient($conversation?->getRecipient() ?? mb_substr($sender, 0, 191))
            ->setContact($contact)
            ->setStatus('received')
            ->setPayload(['email' => [
                'message_id' => $email->messageId,
                'in_reply_to' => $email->inReplyTo,
                'references' => array_slice($email->references, -20),
                'from' => $email->from,
                'to' => self::untagged($email->to, $account),
                'cc' => self::untagged($email->cc, $account),
                'subject' => $email->subject,
                'text' => $body['text'],
                'quoted' => $body['quoted'],
                'html' => $email->truncated ? '' : $body['html'],
                'has_original' => $original !== null,
                'original' => $original,
                'auto_reply' => $autoReply,
                'unverified_sender' => $contact !== null && Triage::dmarcFailed($email),
                'kind' => $autoReply ? 'auto_reply' : 'message',
                'attachments' => $attachments,
            ]]);
        // INTERNALDATE keeps a late read in order, but never in the future.
        $message->setDateAdded(min($receivedAt, new \DateTimeImmutable()));
        if ($conversation !== null) { $message->setConversation($conversation); }
        $this->entityManager->persist($message);
        $this->entityManager->flush();
        if ($autoReply) {
            $this->recordQuietly($message);
            return 'auto_reply';
        }
        $this->conversations->record($message);
        $this->inbox->messagePersisted($message);

        return 'recorded';
    }

    public function unreadable(MetaAsset $asset, array $connection, string $raw, \DateTimeImmutable $receivedAt): string
    {
        $assetId = (int) $asset->getId();
        $externalId = 'email:'.hash('sha256', $assetId."\0".'unreadable'."\0".hash('sha256', $raw));
        if ($this->entityManager->getRepository(MetaMessage::class)->findOneBy(['externalId' => $externalId]) instanceof MetaMessage) { return 'duplicate'; }
        $blob = $this->blobs->raw($raw);
        $sender = self::rawSender($raw);
        $conversation = $sender === '' ? null : $this->entityManager->getRepository(MetaConversation::class)->findOneBy(['asset' => $asset, 'channel' => 'email', 'recipient' => $sender]);
        // A mail nobody can read only becomes an event where a conversation already exists.
        if (!$conversation instanceof MetaConversation) {
            $this->logger?->warning('multimail: unreadable message kept as a private blob', ['asset' => $assetId, 'blob' => $blob]);
            return 'ignored';
        }
        $message = (new MetaMessage())->setAsset($asset)->setExternalId($externalId)->setChannel('email')->setDirection('inbound')
            ->setMessageType('email')->setRecipient($conversation->getRecipient())->setContact($conversation->getContact())->setStatus('received')
            ->setConversation($conversation)
            ->setPayload(['email' => ['message_id' => null, 'in_reply_to' => null, 'references' => [], 'from' => ['name' => '', 'address' => $sender],
                'to' => [['name' => '', 'address' => ConnectionStore::accountAddress($connection)]], 'cc' => [], 'subject' => '',
                'text' => 'E-mail ilegível de '.$sender.'.', 'quoted' => '', 'html' => '', 'has_original' => false, 'original' => null, 'raw' => $blob,
                'auto_reply' => false, 'unverified_sender' => false, 'kind' => 'unreadable', 'attachments' => []]]);
        $message->setDateAdded(min($receivedAt, new \DateTimeImmutable()));
        $this->entityManager->persist($message);
        $this->entityManager->flush();
        $this->recordQuietly($message);

        return 'unreadable';
    }

    /** DSN for one of our messages: the outgoing message fails, the conversation gets an event. */
    private function bounce(MetaAsset $asset, InboundEmail $email, string $externalId, \DateTimeImmutable $receivedAt): string
    {
        $report = $email->report ?? [];
        $originalId = $report['original_message_id'] ?? null;
        if ($originalId === null || !in_array($report['action'] ?? '', ['', 'failed'], true)) { return 'ignored'; }
        $outbound = $this->entityManager->getRepository(MetaMessage::class)->findOneBy(['externalId' => MessageIds::externalId((int) $asset->getId(), $originalId)]);
        if (!$outbound instanceof MetaMessage || $outbound->getDirection() !== 'outbound' || $outbound->getAsset()->getId() !== $asset->getId()) { return 'ignored'; }
        $diagnostic = trim(($report['status'] ?? '').' '.preg_replace('/^smtp;\s*/i', '', (string) ($report['diagnostic'] ?? '')));
        $diagnostic = $diagnostic === '' ? 'sem diagnóstico' : mb_substr($diagnostic, 0, 400);
        $payload = $outbound->getPayload();
        $payload['email']['bounce'] = ['status' => $report['status'] ?? '', 'diagnostic' => $report['diagnostic'] ?? '', 'recipient' => $report['recipient'] ?? '', 'at' => $receivedAt->format(DATE_ATOM)];
        $outbound->setStatus('failed')->setError('E-mail devolvido: '.$diagnostic)->setPayload($payload);
        // No DNC here: one bounce is not proof the address is dead, and DNC belongs to Mautic's bounce rules.
        $event = (new MetaMessage())->setAsset($asset)->setExternalId($externalId)->setChannel('email')->setDirection('inbound')->setMessageType('email')
            ->setRecipient($outbound->getRecipient())->setContact($outbound->getContact())->setStatus('received')
            ->setPayload(['email' => ['message_id' => $email->messageId, 'in_reply_to' => $originalId, 'references' => [$originalId], 'from' => $email->from,
                'to' => [], 'cc' => [], 'subject' => $email->subject, 'text' => 'O e-mail para '.($report['recipient'] ?? '') .' voltou: '.$diagnostic,
                'quoted' => '', 'html' => '', 'has_original' => false, 'original' => null, 'auto_reply' => false, 'unverified_sender' => false,
                'kind' => 'bounce', 'attachments' => [], 'bounced_message_id' => $originalId]]);
        if ($outbound->getConversation() !== null) { $event->setConversation($outbound->getConversation()); }
        $event->setDateAdded(min($receivedAt, new \DateTimeImmutable()));
        $this->entityManager->persist($outbound);
        $this->entityManager->persist($event);
        $this->entityManager->flush();
        $this->recordQuietly($event);

        return 'bounce';
    }

    /** record() for an event: it must not count as unread, reopen the conversation or move the reply window. */
    private function recordQuietly(MetaMessage $message): void
    {
        $existing = $this->entityManager->getRepository(MetaConversation::class)->findOneBy(['asset' => $message->getAsset(), 'channel' => 'email', 'recipient' => $message->getRecipient()]);
        $before = $existing instanceof MetaConversation ? [$existing->getUnreadCount(), $existing->getStatus(), $existing->getLastInboundAt()] : [0, 'open', null];
        $conversation = $this->conversations->record($message);
        $conversation->setUnreadCount($before[0])->setStatus($before[1])->setLastInboundAt($before[2]);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();
    }

    /** The contact only when exactly one shares the address, as the Meta ContactMatcher does. */
    private function uniqueContact(string $email): ?Lead
    {
        $leads = $this->entityManager->getRepository(Lead::class)->findBy(['email' => $email], null, 2);

        return count($leads) === 1 && $leads[0] instanceof Lead ? $leads[0] : null;
    }

    /** Best effort for mail the parser refused: the first address in the (folded) From header. */
    public static function rawSender(string $raw): string
    {
        $end = strpos($raw, "\r\n\r\n");
        $headers = substr($raw, 0, $end === false ? 65536 : min($end, 65536));
        if (!preg_match('/^From:(.*(?:\r?\n[ \t].*)*)/mi', $headers, $from)
            || !preg_match('/[A-Za-z0-9._%+\'-]{1,64}@[A-Za-z0-9-]{1,63}(?:\.[A-Za-z0-9-]{1,63})+/', $from[1], $address)) { return ''; }
        $address = strtolower($address[0]);

        return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : '';
    }

    public static function withoutTokens(string $text, string $account): string
    {
        $at = strrpos($account, '@');
        if ($at === false) { return $text; }

        return preg_replace('/('.preg_quote(substr($account, 0, $at), '/').')\+r[a-z2-7]{24}(@'.preg_quote(substr($account, $at + 1), '/').')/i', '$1$2', $text) ?? $text;
    }

    /** The account address appears without its +tag: the token never reaches the screen. */
    private static function untagged(array $addresses, string $account): array
    {
        return array_map(static fn(array $address): array => ['name' => $address['name'], 'address' => ThreadToken::untagged($address['address'], $account)], $addresses);
    }
}
