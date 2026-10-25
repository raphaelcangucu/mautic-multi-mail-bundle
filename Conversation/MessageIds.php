<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

/**
 * Message-ID rules of the conversational channel. Ids are kept with angle brackets,
 * exactly as they travel in headers and in the message payload.
 */
final class MessageIds
{
    /** `<{16 random bytes hex}.{assetId}@{From domain}>`: stable for every retry of one job. */
    public static function generate(int $assetId, string $fromEmail): string
    {
        $domain = strtolower(substr($fromEmail, (int) strrpos($fromEmail, '@') + 1));
        if ($assetId < 1 || !preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $domain)) {
            throw new \InvalidArgumentException('Invalid sender for Message-ID.');
        }

        return '<'.bin2hex(random_bytes(16)).'.'.$assetId.'@'.$domain.'>';
    }

    /** A single id in canonical bracketed form, or null when the value is not a msg-id. */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') { return null; }
        if ($value[0] !== '<') { $value = '<'.$value.'>'; }
        if (strlen($value) > 998 || !preg_match('/^<[^<>\s@]+@[^<>\s@]+>$/D', $value)) { return null; }

        return $value;
    }

    /** Every id in a References/In-Reply-To style header value, in order, without duplicates. */
    public static function parseList(?string $value, int $limit = 50): array
    {
        preg_match_all('/<[^<>\s@]+@[^<>\s@]+>/', (string) $value, $matches);
        $ids = [];
        foreach ($matches[0] as $id) {
            $ids[strtolower($id)] ??= $id;
        }

        return array_slice(array_values($ids), -$limit);
    }

    /** True for ids this bundle generates; a cheap pre-filter, never proof of ownership. */
    public static function looksOwn(string $messageId): bool
    {
        return (bool) preg_match('/^<[a-f0-9]{32}\.[0-9]+@[^<>\s@]+>$/D', $messageId);
    }

    /** meta_message_external_id is unique across the whole database, so the asset scopes it. */
    public static function externalId(int $assetId, string $messageId): string
    {
        return 'email:'.hash('sha256', $assetId."\0".strtolower($messageId));
    }

    /** Messages without a Message-ID still need a stable deduplication key. */
    public static function fallbackExternalId(int $assetId, string $sender, string $date, string $subject, string $body): string
    {
        return 'email:'.hash('sha256', $assetId."\0".'no-message-id'."\0".strtolower($sender)."\0".$date."\0".$subject."\0".hash('sha256', $body));
    }
}
