<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

/**
 * Which conversation an incoming message belongs to, in this order:
 * 1. a valid token in the +tag of To, Cc, Delivered-To or X-Original-To;
 * 2. In-Reply-To or References matching a message stored for the account;
 * 3. the existing conversation of (account, sender);
 * 4. a new conversation for (account, sender).
 * An invalid token is ignored (and reported) and the next rule decides.
 */
final class ThreadResolver
{
    public const MAX_REFERENCES = 20;

    public function __construct(private readonly ThreadToken $tokens) {}

    /** @return array{rule: 'token'|'headers'|'sender'|'new', conversation_id: ?int, invalid_token: bool} */
    public function resolve(InboundEmail $email, int $assetId, string $accountAddress, ThreadLookup $lookup): array
    {
        $invalid = false;
        foreach (ThreadToken::candidates($email->recipientAddresses(), $accountAddress) as $token) {
            $conversationId = $this->tokens->decode($token, $assetId);
            if ($conversationId !== null && $lookup->hasConversation($conversationId)) {
                return ['rule' => 'token', 'conversation_id' => $conversationId, 'invalid_token' => false];
            }
            $invalid = true;
        }
        $ids = [];
        foreach (array_merge($email->inReplyTo === null ? [] : [$email->inReplyTo], array_reverse($email->references)) as $id) {
            $ids[strtolower($id)] ??= MessageIds::externalId($assetId, $id);
        }
        if ($ids !== []) {
            $conversationId = $lookup->conversationForExternalIds(array_slice(array_values($ids), 0, self::MAX_REFERENCES));
            if ($conversationId !== null) { return ['rule' => 'headers', 'conversation_id' => $conversationId, 'invalid_token' => $invalid]; }
        }
        $sender = strtolower($email->from['address']);
        $conversationId = $sender === '' ? null : $lookup->conversationForRecipient($sender);

        return $conversationId !== null
            ? ['rule' => 'sender', 'conversation_id' => $conversationId, 'invalid_token' => $invalid]
            : ['rule' => 'new', 'conversation_id' => null, 'invalid_token' => $invalid];
    }
}
