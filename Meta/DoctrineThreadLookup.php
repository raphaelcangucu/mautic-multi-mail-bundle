<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Meta;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMultiMailBundle\Conversation\ThreadLookup;
use MauticPlugin\MauticMetaBundle\Entity\{MetaAsset, MetaConversation, MetaMessage};

/** The threading questions answered from the Meta tables, always within one email asset. */
final class DoctrineThreadLookup implements ThreadLookup
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly MetaAsset $asset) {}

    public function hasConversation(int $conversationId): bool
    {
        return $this->conversation($conversationId) !== null;
    }

    public function conversation(int $conversationId): ?MetaConversation
    {
        $conversation = $this->entityManager->find(MetaConversation::class, $conversationId);

        return $conversation instanceof MetaConversation && $conversation->getChannel() === 'email'
            && $conversation->getAsset()->getId() === $this->asset->getId() ? $conversation : null;
    }

    public function conversationForExternalIds(array $externalIds): ?int
    {
        if ($externalIds === []) { return null; }
        $found = [];
        foreach ($this->entityManager->getRepository(MetaMessage::class)->findBy(['externalId' => array_values($externalIds)]) as $message) {
            if ($message instanceof MetaMessage && $message->getAsset()->getId() === $this->asset->getId() && $message->getConversation() !== null) {
                $found[$message->getExternalId()] = (int) $message->getConversation()->getId();
            }
        }
        foreach ($externalIds as $externalId) {
            if (isset($found[$externalId])) { return $found[$externalId]; }
        }

        return null;
    }

    public function conversationForRecipient(string $recipient): ?int
    {
        $conversation = $this->entityManager->getRepository(MetaConversation::class)->findOneBy(['asset' => $this->asset, 'channel' => 'email', 'recipient' => strtolower($recipient)]);

        return $conversation instanceof MetaConversation ? (int) $conversation->getId() : null;
    }
}
