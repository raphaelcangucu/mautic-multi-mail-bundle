<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

/** Result of one conversational send. The reason never carries credentials or SMTP commands. */
final class SendOutcome
{
    public const ACCEPTED = 'accepted';
    /** Refused before DATA, 4xx, or no capacity: safe to try again later. */
    public const TEMPORARY = 'temporary';
    /** 5xx for this recipient or this message: trying again changes nothing. */
    public const PERMANENT = 'permanent';
    /** The server may have queued it (timeout after DATA): never retried automatically. */
    public const UNCERTAIN = 'uncertain';

    public function __construct(
        public readonly string $status,
        public readonly string $reason = '',
        public readonly ?string $retryAt = null,
        public readonly ?int $code = null,
    ) {}
}
