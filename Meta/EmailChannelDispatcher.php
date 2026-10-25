<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Meta;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\{DoNotContact as DNC, Lead};
use Mautic\LeadBundle\Model\DoNotContact;
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use MauticPlugin\MauticMetaBundle\Application\Exception\ChannelTemporarilyUnavailable;
use MauticPlugin\MauticMetaBundle\Application\Queue\ChannelDispatcherInterface;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\{MetaAsset, MetaConversation, MetaMessage, MetaOutboundJob};
use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use MauticPlugin\MauticMultiMailBundle\Conversation\{ConversationalSender, InboundBody, MessageIds, OutboundEmailBuilder, SendOutcome, ThreadToken};

/**
 * Delivers the `email_message` queue operation (tag `mautic_meta.channel_dispatcher`,
 * asset_type `email_account`).
 *
 * The queue's failure taxonomy decides what the agent sees: DomainException and
 * InvalidArgumentException fail for good, ChannelTemporarilyUnavailable is retried with
 * back-off, and any other exception leaves the job "uncertain", never retried blindly.
 */
final class EmailChannelDispatcher implements ChannelDispatcherInterface
{
    public const MAX_TEXT_BYTES = 65536;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConversationalSender $sender,
        private readonly OutboundEmailBuilder $builder,
        private readonly ThreadToken $tokens,
        private readonly ConversationManager $conversations,
        private readonly DoNotContact $doNotContact,
    ) {}

    public function dispatch(MetaOutboundJob $job): MetaMessage
    {
        $asset = $job->getAsset();
        if ($asset->getType() !== AssetType::EmailAccount || $asset->getStatus() !== 'active') {
            throw new \DomainException('Esta conta de e-mail está desativada.');
        }
        try { $connection = $this->sender->connection($asset->getExternalId()); }
        catch (\DomainException $exception) { throw $exception; }
        catch (\Throwable) {
            // The private registry could not be read: nothing was sent, so this is a retry, never "uncertain".
            throw new ChannelTemporarilyUnavailable('Configuração de e-mail indisponível no momento.');
        }
        if (!$connection['conversational']) { throw new \DomainException('Esta conta de e-mail está desativada.'); }
        $payload = $job->getPayload();
        $recipient = strtolower(trim((string) ($payload['recipient'] ?? '')));
        if ($recipient === '' || strlen($recipient) > 191 || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Cadastre um e-mail válido no contato.');
        }
        $subject = trim((string) ($payload['subject'] ?? ''));
        $text = (string) ($payload['text'] ?? '');
        if ($subject === '' || trim($text) === '' || strlen($text) > self::MAX_TEXT_BYTES) {
            throw new \InvalidArgumentException('Assunto e texto do e-mail são obrigatórios.');
        }
        $conversation = $this->conversation($asset, $payload, $recipient, $job->getContact());
        $contact = $job->getContact() ?? $conversation->getContact() ?? $this->uniqueContact($recipient);
        if ($contact !== null && $this->doNotContact->isContactable($contact, 'email') !== DNC::IS_CONTACTABLE) {
            throw new \DomainException('O contato pediu para não receber e-mails.');
        }
        $assetId = (int) $asset->getId();
        // One Message-ID per job, written before the first attempt: every retry, including the
        // Inbox "try again" that copies the payload, resends the same message.
        $messageId = MessageIds::normalize(is_string($payload['_email_message_id'] ?? null) ? $payload['_email_message_id'] : null);
        if ($messageId === null) {
            $messageId = MessageIds::generate($assetId, $connection['from_email']);
            $payload['_email_message_id'] = $messageId;
            $job->setPayload($payload);
        }
        $externalId = MessageIds::externalId($assetId, $messageId);
        $message = $this->entityManager->getRepository(MetaMessage::class)->findOneBy(['externalId' => $externalId]);
        if ($message instanceof MetaMessage && $message->getStatus() === 'sent') {
            // The server already accepted this exact message; a second copy would be dropped anyway.
            return $message;
        }
        [$inReplyTo, $references] = $this->threadHeaders($conversation);
        $campaign = ($payload['_origin'] ?? null) === 'inbox_campaign';
        $fromName = $campaign
            ? (trim((string) ($payload['from_name'] ?? '')) !== '' ? trim((string) $payload['from_name']) : $connection['from_name'])
            : OutboundEmailBuilder::senderName(is_string($payload['author_name'] ?? null) ? $payload['author_name'] : null, $connection['from_name']);
        $token = ($connection['plus_addressing'] ?? true) ? $this->tokens->encode($assetId, (int) $conversation->getId()) : null;
        $toName = $contact !== null ? trim((string) $contact->getName()) : '';
        $email = $this->builder->build([
            'from_email' => $connection['from_email'], 'from_name' => $fromName,
            'reply_to' => OutboundEmailBuilder::replyTo(ConnectionStore::accountAddress($connection), $token),
            'to_email' => $recipient, 'to_name' => $toName, 'subject' => $subject, 'text' => $text, 'message_id' => $messageId,
            'in_reply_to' => $inReplyTo, 'references' => $references,
            'unsubscribe_url' => $campaign && is_string($payload['unsubscribe_url'] ?? null) && $payload['unsubscribe_url'] !== '' ? $payload['unsubscribe_url'] : null,
        ]);
        if (!$message instanceof MetaMessage) {
            $message = (new MetaMessage())->setExternalId($externalId);
        }
        $message->setAsset($asset)->setChannel('email')->setDirection('outbound')->setMessageType('email')
            ->setRecipient($conversation->getRecipient())->setContact($contact)->setConversation($conversation)->setStatus('pending')->setError(null)
            ->setResponse(['job_id' => $job->getId(), 'delivery' => 'sending'])
            ->setPayload(['email' => [
                'message_id' => $messageId, 'in_reply_to' => $inReplyTo, 'references' => $references,
                'from' => ['name' => $fromName, 'address' => strtolower($connection['from_email'])],
                'to' => [['name' => $toName, 'address' => $recipient]], 'cc' => [],
                'subject' => (string) $email->getSubject(), 'text' => $text, 'quoted' => '',
                'html' => InboundBody::cap((string) $email->getHtmlBody()),
                'has_original' => false, 'original' => null, 'auto_reply' => false, 'unverified_sender' => false, 'kind' => 'message', 'attachments' => [],
            ]]);
        // The pending message and the Message-ID exist before the SMTP session starts.
        $this->entityManager->persist($message);
        $this->entityManager->persist($job);
        $this->entityManager->flush();
        // The queue only stores messageLogId on success, but the Inbox hides a human send's
        // message by that id: without it now, a sending, retried or failed e-mail would show
        // twice in the thread, once as the request and once as this message.
        $job->setMessageLogId((int) $message->getId());
        $this->entityManager->flush();

        $outcome = $this->sender->send($connection, $email);
        $response = ['job_id' => $job->getId(), 'delivery' => $outcome->status, 'at' => gmdate(DATE_ATOM)] + ($outcome->code !== null ? ['smtp_code' => $outcome->code] : []);
        switch ($outcome->status) {
            case SendOutcome::ACCEPTED:
                $message->setStatus('sent')->setResponse($response);
                $this->conversations->record($message);

                return $message;
            case SendOutcome::PERMANENT:
                $message->setStatus('failed')->setError($outcome->reason)->setResponse($response);
                $this->entityManager->flush();
                throw new \DomainException($outcome->reason);
            case SendOutcome::TEMPORARY:
                $message->setError($outcome->reason)->setResponse($response + ($outcome->retryAt !== null ? ['retry_at' => $outcome->retryAt] : []));
                $this->entityManager->flush();
                throw new ChannelTemporarilyUnavailable($outcome->reason.($outcome->retryAt !== null ? ' Nova tentativa a partir de '.$outcome->retryAt.'.' : ''));
            default:
                // The Sent-folder check of the poller may still confirm it within 24 hours.
                $message->setError($outcome->reason)->setResponse($response);
                $this->entityManager->flush();
                throw new \RuntimeException($outcome->reason);
        }
    }

    private function conversation(MetaAsset $asset, array $payload, string $recipient, ?Lead $contact): MetaConversation
    {
        $id = (int) ($payload['_inbox_conversation_id'] ?? 0);
        if ($id > 0) {
            $conversation = $this->entityManager->find(MetaConversation::class, $id);
            if ($conversation instanceof MetaConversation && $conversation->getChannel() === 'email' && $conversation->getAsset()->getId() === $asset->getId()) {
                return $conversation;
            }
        }
        $conversation = $this->entityManager->getRepository(MetaConversation::class)->findOneBy(['asset' => $asset, 'channel' => 'email', 'recipient' => $recipient]);
        if ($conversation instanceof MetaConversation) { return $conversation; }
        // The Reply-To token needs the conversation id before the first message exists.
        $conversation = (new MetaConversation())->setAsset($asset)->setChannel('email')->setRecipient($recipient)->setContact($contact);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        return $conversation;
    }

    /** @return array{0: ?string, 1: list<string>} In-Reply-To and References from the lead's latest message */
    private function threadHeaders(MetaConversation $conversation): array
    {
        $latest = $this->entityManager->getRepository(MetaMessage::class)->findBy(['conversation' => $conversation, 'channel' => 'email', 'direction' => 'inbound'], ['dateAdded' => 'DESC', 'id' => 'DESC'], 5);
        foreach ($latest as $message) {
            $email = $message->getPayload()['email'] ?? [];
            if (($email['kind'] ?? 'message') !== 'message' || !is_string($email['message_id'] ?? null)) { continue; }
            $references = array_values(array_filter(is_array($email['references'] ?? null) ? $email['references'] : [], 'is_string'));
            $references[] = $email['message_id'];

            return [$email['message_id'], array_slice($references, -OutboundEmailBuilder::MAX_REFERENCES)];
        }

        return [null, []];
    }

    private function uniqueContact(string $email): ?Lead
    {
        $leads = $this->entityManager->getRepository(Lead::class)->findBy(['email' => $email], null, 2);

        return count($leads) === 1 && $leads[0] instanceof Lead ? $leads[0] : null;
    }
}
