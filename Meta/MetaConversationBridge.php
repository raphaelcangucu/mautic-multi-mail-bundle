<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Meta;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use MauticPlugin\MauticMetaBundle\Application\Support\InboxIntegrationInterface;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\{MetaAsset, MetaConnection, MetaMessage, MetaOutboundJob};
use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use MauticPlugin\MauticMultiMailBundle\Conversation\{ConversationBridgeInterface, InboundEmail, MessageIds};
use Psr\Log\LoggerInterface;

/**
 * The conversational channel on top of the Meta bundle: one `email_account` asset per
 * conversational connection, all under a single inactive internal MetaConnection that has
 * no credentials and is never polled by Meta (the WebChat/QR precedent).
 */
final class MetaConversationBridge implements ConversationBridgeInterface
{
    public const APP_ID = 'multimail-email';
    public const CONNECTION_NAME = 'E-mail (Multi Mail)';
    private const LOCK = 'mautic_multimail_email_assets';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailIngestor $ingestor,
        private readonly ConversationManager $conversations,
        private readonly ?InboxIntegrationInterface $inbox = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function available(): bool
    {
        return true;
    }

    public function synchronize(array $connections): void
    {
        $this->sync($connections, true);
    }

    /** $complete: the list is the whole registry, so assets of missing connections are deactivated. */
    private function sync(array $connections, bool $complete): void
    {
        $database = $this->entityManager->getConnection();
        // Saving in the admin and the poller can run together: one creator of the internal rows.
        if (1 !== (int) $database->fetchOne('SELECT GET_LOCK(?, 10)', [self::LOCK])) { throw new \RuntimeException('Email asset synchronization is busy.'); }
        try {
            $wantedAny = array_filter($connections, fn(array $connection): bool => ($connection['provider'] ?? '') === 'smtp' && ($connection['conversational'] ?? false) === true) !== [];
            // Installations that never turn the channel on get no internal rows at all.
            if (!$wantedAny && !$this->entityManager->getRepository(MetaConnection::class)->findOneBy(['appId' => self::APP_ID, 'businessId' => '']) instanceof MetaConnection) { return; }
            $internal = $this->internalConnection();
            $assets = [];
            foreach ($this->entityManager->getRepository(MetaAsset::class)->findBy(['connection' => $internal, 'type' => AssetType::EmailAccount->value]) as $asset) {
                $assets[$asset->getExternalId()] = $asset;
            }
            foreach ($connections as $connection) {
                $wanted = ($connection['provider'] ?? '') === 'smtp' && ($connection['conversational'] ?? false) === true;
                $asset = $assets[$connection['id']] ?? null;
                unset($assets[$connection['id']]);
                if (!$wanted) {
                    if ($asset !== null && $asset->getStatus() !== 'inactive') { $asset->setStatus('inactive'); $this->entityManager->persist($asset); }
                    continue;
                }
                $asset ??= (new MetaAsset())->setConnection($internal)->setExternalId($connection['id'])->setType(AssetType::EmailAccount);
                $settings = $asset->getSettings();
                // Only the keys this bundle owns change; anything another plugin stored stays.
                $settings = array_replace(['email_inbound_status' => 'ok', 'email_inbound_since' => null], $settings, [
                    'email_address' => ConnectionStore::accountAddress($connection),
                    'email_from_name' => (string) $connection['from_name'],
                    'email_plus_addressing' => (bool) ($connection['plus_addressing'] ?? true),
                ]);
                $asset->setName(mb_substr((string) $connection['name'], 0, 191))->setStatus('active')->setSettings($settings);
                $asset->setIsPublished(true);
                $this->entityManager->persist($asset);
            }
            // Removed connections: inactive, never deleted, so their history stays readable.
            foreach ($complete ? $assets : [] as $asset) {
                if ($asset->getStatus() !== 'inactive') { $asset->setStatus('inactive'); $this->entityManager->persist($asset); }
            }
            $this->entityManager->flush();
        } finally {
            $database->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    public function ingest(array $connection, InboundEmail $email, string $raw, \DateTimeImmutable $receivedAt): string
    {
        return $this->ingestor->ingest($this->asset($connection), $connection, $email, $raw, $receivedAt);
    }

    public function ingestUnreadable(array $connection, string $raw, \DateTimeImmutable $receivedAt): string
    {
        return $this->ingestor->unreadable($this->asset($connection), $connection, $raw, $receivedAt);
    }

    public function inboundStatus(array $connection, string $status, ?string $since): void
    {
        if (!in_array($status, ['ok', 'delayed', 'auth_failed'], true)) { throw new \InvalidArgumentException('Invalid inbound status.'); }
        $asset = $this->asset($connection);
        $settings = $asset->getSettings();
        $since = $status === 'ok' ? null : $since;
        if (($settings['email_inbound_status'] ?? null) === $status && ($settings['email_inbound_since'] ?? null) === $since) { return; }
        $asset->setSettings(array_replace($settings, ['email_inbound_status' => $status, 'email_inbound_since' => $since]));
        $this->entityManager->persist($asset);
        $this->entityManager->flush();
    }

    public function uncertainMessageIds(array $connection): array
    {
        $asset = $this->asset($connection);
        $since = new \DateTimeImmutable('-24 hours');
        $ids = [];
        foreach ($this->entityManager->getRepository(MetaMessage::class)->findBy(['asset' => $asset, 'channel' => 'email', 'direction' => 'outbound', 'status' => 'pending'], ['id' => 'DESC'], 50) as $message) {
            $messageId = $message->getPayload()['email']['message_id'] ?? null;
            if (($message->getResponse()['delivery'] ?? null) === 'uncertain' && $message->getDateAdded() >= $since && is_string($messageId)) { $ids[] = $messageId; }
        }

        return $ids;
    }

    public function confirmSent(array $connection, string $messageId): bool
    {
        $asset = $this->asset($connection);
        $message = $this->entityManager->getRepository(MetaMessage::class)->findOneBy(['externalId' => MessageIds::externalId((int) $asset->getId(), $messageId)]);
        if (!$message instanceof MetaMessage || $message->getStatus() !== 'pending' || $message->getDirection() !== 'outbound') { return false; }
        $message->setStatus('sent')->setError(null)->setResponse(array_replace($message->getResponse() ?? [], ['delivery' => 'confirmed_by_sent_folder', 'confirmed_at' => gmdate(DATE_ATOM)]));
        $this->conversations->record($message);
        $jobId = (int) ($message->getResponse()['job_id'] ?? 0);
        $job = $jobId > 0 ? $this->entityManager->find(MetaOutboundJob::class, $jobId) : null;
        if ($job instanceof MetaOutboundJob && $job->getStatus() === 'uncertain') {
            // The copy in Sent proves the handoff: the job is no longer a decision for the agent.
            $job->setStatus('completed')->setCompletedAt(new \DateTimeImmutable())->setLastError(null)->setMessageLogId((int) $message->getId());
            $this->entityManager->persist($job);
            $this->entityManager->flush();
            try { $this->inbox?->outboundJobChanged($job); } catch (\Throwable) { /* A projection never changes delivery state. */ }
        }

        return true;
    }

    /** The asset of a conversational connection, created on demand (a hand-deleted asset comes back). */
    private function asset(array $connection): MetaAsset
    {
        $asset = $this->findAsset($connection['id']);
        if ($asset === null || $asset->getStatus() !== 'active') {
            $this->sync([$connection], false);
            $asset = $this->findAsset($connection['id']);
        }

        return $asset ?? throw new \RuntimeException('Email account asset unavailable.');
    }

    private function findAsset(string $connectionId): ?MetaAsset
    {
        $internal = $this->entityManager->getRepository(MetaConnection::class)->findOneBy(['appId' => self::APP_ID, 'businessId' => '']);
        if (!$internal instanceof MetaConnection) { return null; }
        $asset = $this->entityManager->getRepository(MetaAsset::class)->findOneBy(['connection' => $internal, 'externalId' => $connectionId, 'type' => AssetType::EmailAccount->value]);

        return $asset instanceof MetaAsset ? $asset : null;
    }

    private function internalConnection(): MetaConnection
    {
        $connection = $this->entityManager->getRepository(MetaConnection::class)->findOneBy(['appId' => self::APP_ID, 'businessId' => '']);
        if ($connection instanceof MetaConnection) { return $connection; }
        // Inactive and without secrets: Meta never polls it or talks to the Graph through it.
        $connection = (new MetaConnection())->setName(self::CONNECTION_NAME)->setAppId(self::APP_ID)->setBusinessId('')->setStatus('inactive')
            ->setEncryptedAppSecret('')->setEncryptedAccessToken('')->setEncryptedVerifyToken('');
        $this->entityManager->persist($connection);
        $this->entityManager->flush();

        return $connection;
    }
}
