<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

/**
 * A parsed incoming message, independent of the Meta bundle. Addresses are
 * `{name, address}` with the address lowercased; Message-IDs keep their angle brackets.
 */
final class InboundEmail
{
    /**
     * @param array{name: string, address: string} $from
     * @param list<array{name: string, address: string}> $to
     * @param list<array{name: string, address: string}> $cc
     * @param list<string> $envelopeRecipients Delivered-To, X-Original-To and Envelope-To
     * @param list<string> $references
     * @param array<string, list<string>> $headers lowercase name => values, triage headers only
     * @param list<InboundAttachment> $attachments
     * @param array{action: string, status: string, diagnostic: string, recipient: string, original_message_id: ?string}|null $report
     */
    public function __construct(
        public readonly ?string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly array $from,
        public readonly array $to,
        public readonly array $cc,
        public readonly array $envelopeRecipients,
        public readonly string $subject,
        public readonly string $date,
        public readonly string $text,
        public readonly string $html,
        public readonly array $attachments,
        public readonly array $headers,
        public readonly ?array $report,
        public readonly bool $truncated,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** Every address the message was delivered to, for the +tag lookup. */
    public function recipientAddresses(): array
    {
        return array_values(array_unique(array_merge(array_column($this->to, 'address'), array_column($this->cc, 'address'), $this->envelopeRecipients)));
    }
}
