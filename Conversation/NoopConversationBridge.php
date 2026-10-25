<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

/** Default when the Meta bundle is not installed: the conversational channel is off. */
final class NoopConversationBridge implements ConversationBridgeInterface
{
    public function available(): bool { return false; }

    public function synchronize(array $connections): void {}

    public function ingest(array $connection, InboundEmail $email, string $raw, \DateTimeImmutable $receivedAt): string { return 'ignored'; }

    public function ingestUnreadable(array $connection, string $raw, \DateTimeImmutable $receivedAt): string { return 'ignored'; }

    public function inboundStatus(array $connection, string $status, ?string $since): void {}

    public function uncertainMessageIds(array $connection): array { return []; }

    public function confirmSent(array $connection, string $messageId): bool { return false; }
}
