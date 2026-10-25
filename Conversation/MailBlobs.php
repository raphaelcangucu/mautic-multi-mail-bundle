<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

/** What an incoming message leaves on disk: the full original HTML, attachments, and raw unreadable mail. */
final class MailBlobs
{
    public const MAX_ORIGINAL_BYTES = 10485760;

    public function __construct(private readonly BlobStore $store, private readonly ClamdScanner $scanner) {}

    /** `blob:<sha256>` of the complete HTML, or null when there is none or no room. */
    public function original(string $html): ?string
    {
        if (trim($html) === '' || strlen($html) > self::MAX_ORIGINAL_BYTES) { return null; }
        $sha = $this->store->put($html, ['kind' => 'original_html', 'mime' => 'text/html', 'scan' => 'not_applicable']);

        return $sha === null ? null : 'blob:'.$sha;
    }

    public function raw(string $raw): ?string
    {
        $sha = $this->store->put($raw, ['kind' => 'raw', 'mime' => 'message/rfc822', 'scan' => 'not_applicable']);

        return $sha === null ? null : 'blob:'.$sha;
    }

    /**
     * Payload entries in MIME order. Only allowed, scanned-clean files are `available`;
     * the rest still show name and size so the agent knows something was sent.
     *
     * @param list<InboundAttachment> $attachments
     * @return list<array{index: int, type: string, name: string, mime: string, size: int, cid: ?string, available: bool, blob: ?string}>
     */
    public function attachments(array $attachments): array
    {
        $items = [];
        foreach (array_values($attachments) as $index => $attachment) {
            $policy = AttachmentPolicy::evaluate($attachment->mime, $attachment->name, (string) $attachment->content);
            $blob = null;
            $available = false;
            if ($attachment->content !== null && $policy['allowed']) {
                $scan = $this->scanner->scan($attachment->content);
                if ($scan !== ClamdScanner::INFECTED) {
                    $blob = $this->store->put($attachment->content, ['kind' => 'attachment', 'mime' => $policy['mime'], 'name' => $attachment->name, 'scan' => $scan]);
                }
                $available = $blob !== null && $scan === ClamdScanner::CLEAN;
            }
            $items[] = ['index' => $index, 'type' => $policy['type'], 'name' => $attachment->name !== '' ? $attachment->name : $policy['type'],
                'mime' => $policy['mime'], 'size' => $attachment->size, 'cid' => $attachment->contentId, 'available' => $available, 'blob' => $blob];
        }

        return $items;
    }

    /** A verified local file for the Inbox proxy, or null. */
    public function attachmentFile(?string $sha): ?array
    {
        if ($sha === null) { return null; }
        $descriptor = $this->store->descriptor($sha);
        $path = $this->store->path($sha);
        if ($descriptor === null || $path === null || ($descriptor['kind'] ?? '') !== 'attachment' || ($descriptor['scan'] ?? '') !== ClamdScanner::CLEAN
            || filesize($path) !== ($descriptor['size'] ?? -1)) { return null; }
        $policy = AttachmentPolicy::evaluate((string) $descriptor['mime'], (string) $descriptor['name'], (string) file_get_contents($path, false, null, 0, 1048576));
        if (!$policy['allowed'] || $policy['mime'] !== $descriptor['mime']) { return null; }

        return ['path' => $path, 'mime' => $policy['mime'], 'name' => (string) ($descriptor['name'] !== '' ? $descriptor['name'] : $policy['type']), 'size' => (int) $descriptor['size']];
    }

    public function originalHtml(?string $reference): ?string
    {
        if ($reference === null || !preg_match('/^blob:([a-f0-9]{64})$/D', $reference, $match)) { return null; }
        $descriptor = $this->store->descriptor($match[1]);
        if (($descriptor['kind'] ?? '') !== 'original_html') { return null; }

        return $this->store->read($match[1], self::MAX_ORIGINAL_BYTES);
    }
}
