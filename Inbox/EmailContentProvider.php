<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Inbox;

use MauticPlugin\MauticInboxBundle\Contract\EmailContentProviderInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMultiMailBundle\Conversation\MailBlobs;

/**
 * Private email content for the Inbox (tag `mautic.inbox.email_content`): the complete
 * original HTML, raw (the Inbox sanitizes it), and verified attachment files. The Inbox
 * serves them with its own access checks and security headers.
 */
final class EmailContentProvider implements EmailContentProviderInterface
{
    public function __construct(private readonly MailBlobs $blobs) {}

    public function supports(MetaMessage $message): bool
    {
        return $message->getChannel() === 'email' && is_array($message->getPayload()['email'] ?? null);
    }

    public function originalHtml(MetaMessage $message): ?string
    {
        if (!$this->supports($message)) { return null; }
        $reference = $message->getPayload()['email']['original'] ?? null;

        return $this->blobs->originalHtml(is_string($reference) ? $reference : null);
    }

    public function attachment(MetaMessage $message, int $index): ?array
    {
        if (!$this->supports($message) || $index < 0) { return null; }
        $item = $message->getPayload()['email']['attachments'][$index] ?? null;
        if (!is_array($item) || ($item['available'] ?? false) !== true || ($item['index'] ?? null) !== $index) { return null; }

        return $this->blobs->attachmentFile(is_string($item['blob'] ?? null) ? $item['blob'] : null);
    }
}
