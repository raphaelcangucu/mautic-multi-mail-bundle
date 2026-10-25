<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

/** A decoded MIME part that is not the message body. Content is null when it was too large to keep. */
final class InboundAttachment
{
    public function __construct(
        public readonly string $name,
        public readonly string $mime,
        public readonly int $size,
        public readonly ?string $content,
        public readonly ?string $contentId,
        public readonly bool $inline,
    ) {}
}
