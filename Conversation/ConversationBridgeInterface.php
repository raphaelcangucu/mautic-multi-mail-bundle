<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

/**
 * The only door between the mail channel and the conversation store. Without the Meta
 * bundle the Noop implementation answers: Multi Mail stays a transport, the IMAP command
 * does nothing and no email asset exists.
 *
 * Connection arrays are this bundle's records; implementations never persist secrets.
 */
interface ConversationBridgeInterface
{
    public function available(): bool;

    /**
     * Ensure one email account asset per conversational SMTP connection and deactivate the
     * others (never delete them: history must stay readable).
     *
     * @param list<array<string, mixed>> $connections every connection of the registry
     */
    public function synchronize(array $connections): void;

    /**
     * Store one parsed message. Returns what happened: recorded, duplicate, echo, bounce,
     * auto_reply, ignored. Throws only for storage failures; the poller then retries the UID.
     */
    public function ingest(array $connection, InboundEmail $email, string $raw, \DateTimeImmutable $receivedAt): string;

    /** A message that could not be parsed: keep the raw blob and, when possible, an event. */
    public function ingestUnreadable(array $connection, string $raw, \DateTimeImmutable $receivedAt): string;

    /** @param 'ok'|'delayed'|'auth_failed' $status */
    public function inboundStatus(array $connection, string $status, ?string $since): void;

    /** Message-IDs of outgoing messages of the last 24 hours whose send outcome is unknown. */
    public function uncertainMessageIds(array $connection): array;

    /** The Sent folder holds this Message-ID: promote the outgoing message to sent. */
    public function confirmSent(array $connection, string $messageId): bool;
}
