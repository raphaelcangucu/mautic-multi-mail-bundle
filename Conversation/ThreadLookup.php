<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

/** Storage questions the threading rules ask, always scoped to one email account asset. */
interface ThreadLookup
{
    /** True when the conversation exists, is an email conversation and belongs to the asset. */
    public function hasConversation(int $conversationId): bool;

    /**
     * The conversation of the first stored message (either direction) among these scoped
     * external ids, tried in the given order.
     *
     * @param list<string> $externalIds
     */
    public function conversationForExternalIds(array $externalIds): ?int;

    /** The existing conversation for (asset, recipient) or null. */
    public function conversationForRecipient(string $recipient): ?int;
}
