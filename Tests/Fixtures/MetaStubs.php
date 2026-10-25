<?php

declare(strict_types=1);

/*
 * Minimal stand-ins for the Meta 0.15.0, Inbox 1.9.0, Mautic and Doctrine classes that the
 * Meta/ and Inbox/ folders use, with the same names and signatures. Only for the
 * kernel-less tests; installed classes always win (each block checks first).
 */

namespace Doctrine\ORM {
    if (!interface_exists(EntityManagerInterface::class)) {
        interface EntityManagerInterface
        {
            public function persist(object $object): void;
            public function flush(): void;
            public function find(string $className, mixed $id): ?object;
            public function getRepository(string $className): object;
            public function getConnection(): object;
        }
    }
}

namespace Mautic\CoreBundle\Helper {
    if (!class_exists(CoreParametersHelper::class)) {
        class CoreParametersHelper
        {
            public function __construct(private array $parameters = []) {}
            public function get(string $name, mixed $default = null): mixed { return $this->parameters[$name] ?? $default; }
        }
    }
}

namespace Mautic\LeadBundle\Entity {
    if (!class_exists(Lead::class)) {
        class Lead
        {
            public $id;
            public function __construct(public string $email = '', public string $name = '') {}
            public function getId() { return $this->id; }
            public function getEmail() { return $this->email; }
            public function getName($lastFirst = false) { return $this->name; }
        }
        class DoNotContact
        {
            public const IS_CONTACTABLE = 0;
            public const UNSUBSCRIBED = 1;
        }
    }
}

namespace Mautic\LeadBundle\Model {
    if (!class_exists(DoNotContact::class)) {
        class DoNotContact
        {
            public array $blocked = [];
            public function isContactable(\Mautic\LeadBundle\Entity\Lead $contact, $channel) { return in_array($contact, $this->blocked, true) && $channel === 'email' ? 1 : 0; }
        }
    }
}

namespace MauticPlugin\MauticMetaBundle\Domain {
    if (!enum_exists(Channel::class)) {
        enum Channel: string { case WhatsApp = 'whatsapp'; case Instagram = 'instagram'; case Facebook = 'facebook'; case Email = 'email'; }
        enum AssetType: string
        {
            case WhatsAppBusinessAccount = 'whatsapp_business_account';
            case WhatsAppPhoneNumber = 'whatsapp_phone_number';
            case InstagramAccount = 'instagram_account';
            case FacebookPage = 'facebook_page';
            case WhatsAppQrSession = 'whatsapp_qr_session';
            case EmailAccount = 'email_account';
        }
    }
}

namespace MauticPlugin\MauticMetaBundle\Entity {
    use Mautic\LeadBundle\Entity\Lead;
    use MauticPlugin\MauticMetaBundle\Domain\AssetType;

    if (!class_exists(MetaMessage::class)) {
        class MetaConnection
        {
            public $id;
            public string $name = '', $appId = '', $businessId = '', $status = 'pending', $encryptedAppSecret = '', $encryptedAccessToken = '', $encryptedVerifyToken = '';
            public function getId(): ?int { return $this->id; }
            public function setName(string $name): self { $this->name = trim($name); return $this; }
            public function getAppId(): string { return $this->appId; }
            public function setAppId(string $appId): self { $this->appId = trim($appId); return $this; }
            public function setBusinessId(string $value): self { $this->businessId = trim($value); return $this; }
            public function getStatus(): string { return $this->status; }
            public function setStatus(string $status): self { $this->status = $status; return $this; }
            public function setEncryptedAppSecret(string $value): self { $this->encryptedAppSecret = $value; return $this; }
            public function setEncryptedAccessToken(string $value): self { $this->encryptedAccessToken = $value; return $this; }
            public function setEncryptedVerifyToken(string $value): self { $this->encryptedVerifyToken = $value; return $this; }
        }
        class MetaAsset
        {
            public $id;
            public MetaConnection $connection;
            public string $externalId = '', $type = '', $name = '', $status = 'pending';
            public bool $isPublished = false;
            public array $settings = [];
            public function getId(): ?int { return $this->id; }
            public function getConnection(): MetaConnection { return $this->connection; }
            public function setConnection(MetaConnection $connection): self { $this->connection = $connection; return $this; }
            public function getExternalId(): string { return $this->externalId; }
            public function setExternalId(string $value): self { $this->externalId = trim($value); return $this; }
            public function getType(): AssetType { return AssetType::from($this->type); }
            public function setType(AssetType $type): self { $this->type = $type->value; return $this; }
            public function getName(): string { return $this->name; }
            public function setName(string $name): self { $this->name = trim($name); return $this; }
            public function getStatus(): string { return $this->status; }
            public function setStatus(string $status): self { $this->status = $status; return $this; }
            public function getSettings(): array { return $this->settings; }
            public function setSettings(array $value): self { $this->settings = $value; return $this; }
            public function setIsPublished($isPublished): static { $this->isPublished = (bool) $isPublished; return $this; }
            public function getIsPublished() { return $this->isPublished; }
        }
        class MetaConversation
        {
            public $id;
            public MetaAsset $asset;
            public ?Lead $contact = null;
            public string $channel = '', $recipient = '', $status = 'open';
            public int $unreadCount = 0;
            public \DateTimeInterface $lastMessageAt;
            public ?\DateTimeInterface $lastInboundAt = null;
            public function __construct() { $this->lastMessageAt = new \DateTimeImmutable(); }
            public function getId(): ?int { return $this->id; }
            public function getAsset(): MetaAsset { return $this->asset; }
            public function setAsset(MetaAsset $v): self { $this->asset = $v; return $this; }
            public function getContact(): ?Lead { return $this->contact; }
            public function setContact(?Lead $v): self { $this->contact = $v; return $this; }
            public function getChannel(): string { return $this->channel; }
            public function setChannel(string $v): self { $this->channel = $v; return $this; }
            public function getRecipient(): string { return $this->recipient; }
            public function setRecipient(string $v): self { $this->recipient = $v; return $this; }
            public function getStatus(): string { return $this->status; }
            public function setStatus(string $v): self { $this->status = $v; return $this; }
            public function getUnreadCount(): int { return $this->unreadCount; }
            public function setUnreadCount(int $v): self { $this->unreadCount = $v; return $this; }
            public function getLastMessageAt(): \DateTimeInterface { return $this->lastMessageAt; }
            public function setLastMessageAt(\DateTimeInterface $v): self { $this->lastMessageAt = $v; return $this; }
            public function getLastInboundAt(): ?\DateTimeInterface { return $this->lastInboundAt; }
            public function setLastInboundAt(?\DateTimeInterface $v): self { $this->lastInboundAt = $v; return $this; }
        }
        class MetaMessage
        {
            public $id;
            public MetaAsset $asset;
            public ?Lead $contact = null;
            public ?MetaConversation $conversation = null;
            public ?string $externalId = null, $error = null;
            public string $channel = '', $direction = 'outbound', $messageType = '', $recipient = '', $status = 'pending';
            public array $payload = [];
            public ?array $response = null;
            public \DateTimeInterface $dateAdded;
            public function __construct() { $this->dateAdded = new \DateTimeImmutable(); }
            public function getId(): ?int { return $this->id; }
            public function getAsset(): MetaAsset { return $this->asset; }
            public function setAsset(MetaAsset $value): self { $this->asset = $value; return $this; }
            public function getContact(): ?Lead { return $this->contact; }
            public function setContact(?Lead $value): self { $this->contact = $value; return $this; }
            public function getConversation(): ?MetaConversation { return $this->conversation; }
            public function setConversation(?MetaConversation $value): self { $this->conversation = $value; return $this; }
            public function getExternalId(): ?string { return $this->externalId; }
            public function setExternalId(?string $value): self { $this->externalId = $value; return $this; }
            public function getChannel(): string { return $this->channel; }
            public function setChannel(string $value): self { $this->channel = $value; return $this; }
            public function getDirection(): string { return $this->direction; }
            public function setDirection(string $value): self { $this->direction = $value; return $this; }
            public function getMessageType(): string { return $this->messageType; }
            public function setMessageType(string $value): self { $this->messageType = $value; return $this; }
            public function getRecipient(): string { return $this->recipient; }
            public function setRecipient(string $value): self { $this->recipient = $value; return $this; }
            public function getStatus(): string { return $this->status; }
            public function setStatus(string $value): self { $this->status = $value; return $this; }
            public function getPayload(): array { return $this->payload; }
            public function setPayload(array $value): self { $this->payload = $value; return $this; }
            public function getResponse(): ?array { return $this->response; }
            public function setResponse(?array $value): self { $this->response = $value; return $this; }
            public function getError(): ?string { return $this->error; }
            public function setError(?string $value): self { $this->error = $value; return $this; }
            public function setDateAdded(\DateTimeInterface $value): self { $this->dateAdded = \DateTimeImmutable::createFromInterface($value); return $this; }
            public function getDateAdded(): \DateTimeInterface { return $this->dateAdded; }
        }
        class MetaOutboundJob
        {
            public $id;
            public MetaAsset $asset;
            public ?Lead $contact = null;
            public string $operation = '', $status = 'pending';
            public array $payload = [];
            public ?\DateTimeInterface $completedAt = null;
            public ?string $lastError = null;
            public ?int $messageLogId = null;
            public function getId(): ?int { return $this->id; }
            public function getAsset(): MetaAsset { return $this->asset; }
            public function setAsset(MetaAsset $value): self { $this->asset = $value; return $this; }
            public function getContact(): ?Lead { return $this->contact; }
            public function setContact(?Lead $value): self { $this->contact = $value; return $this; }
            public function getOperation(): string { return $this->operation; }
            public function setOperation(string $value): self { $this->operation = $value; return $this; }
            public function getPayload(): array { return $this->payload; }
            public function setPayload(array $value): self { $this->payload = $value; return $this; }
            public function getStatus(): string { return $this->status; }
            public function setStatus(string $value): self { $this->status = $value; return $this; }
            public function setCompletedAt(?\DateTimeInterface $value): self { $this->completedAt = $value; return $this; }
            public function getLastError(): ?string { return $this->lastError; }
            public function setLastError(?string $value): self { $this->lastError = $value; return $this; }
            public function setMessageLogId(?int $value): self { $this->messageLogId = $value; return $this; }
        }
    }
}

namespace MauticPlugin\MauticMetaBundle\Application\Support {
    use MauticPlugin\MauticMetaBundle\Entity\{MetaAsset, MetaMessage, MetaOutboundJob};

    if (!interface_exists(InboxIntegrationInterface::class)) {
        interface InboxIntegrationInterface
        {
            public function ownsSupportInbox(): bool;
            public function messagePersisted(MetaMessage $message): void;
            public function automationAllowed(MetaAsset $asset, string $recipient): bool;
            public function runAutomationGuarded(MetaAsset $asset, string $recipient, callable $operation): mixed;
            public function outboundJobChanged(MetaOutboundJob $job): void;
        }
    }
}

namespace MauticPlugin\MauticMetaBundle\Application\Exception {
    if (!class_exists(ChannelTemporarilyUnavailable::class)) {
        final class ChannelTemporarilyUnavailable extends \RuntimeException {}
    }
}

namespace MauticPlugin\MauticMetaBundle\Application\Queue {
    use MauticPlugin\MauticMetaBundle\Entity\{MetaMessage, MetaOutboundJob};

    if (!interface_exists(ChannelDispatcherInterface::class)) {
        interface ChannelDispatcherInterface { public function dispatch(MetaOutboundJob $job): MetaMessage; }
    }
}

namespace MauticPlugin\MauticMetaBundle\Application\Conversation {
    use Doctrine\ORM\EntityManagerInterface;
    use MauticPlugin\MauticMetaBundle\Application\Support\InboxIntegrationInterface;
    use MauticPlugin\MauticMetaBundle\Entity\{MetaConversation, MetaMessage};

    if (!class_exists(ConversationManager::class)) {
        /** The email branch of Meta 0.15.0 ConversationManager::record(), line for line. */
        class ConversationManager
        {
            public function __construct(private EntityManagerInterface $entityManager, private ?InboxIntegrationInterface $inboxIntegration = null) {}
            public function record(MetaMessage $message): MetaConversation
            {
                $conversation = $this->entityManager->getRepository(MetaConversation::class)->findOneBy(['asset' => $message->getAsset(), 'channel' => $message->getChannel(), 'recipient' => $message->getRecipient()]);
                if (!$conversation instanceof MetaConversation) {
                    $conversation = (new MetaConversation())->setAsset($message->getAsset())->setChannel($message->getChannel())->setRecipient($message->getRecipient());
                    $this->entityManager->persist($conversation);
                }
                if (null !== $message->getContact()) { $conversation->setContact($message->getContact()); }
                $now = new \DateTimeImmutable();
                $conversation->setLastMessageAt($now);
                if ('inbound' === $message->getDirection()) { $conversation->setLastInboundAt($now)->setUnreadCount($conversation->getUnreadCount() + 1)->setStatus('open'); }
                $message->setConversation($conversation);
                $this->entityManager->persist($conversation);
                $this->entityManager->persist($message);
                $this->entityManager->flush();
                if ('outbound' === $message->getDirection()) { $this->inboxIntegration?->messagePersisted($message); }
                return $conversation;
            }
        }
    }
}

namespace MauticPlugin\MauticInboxBundle\Contract {
    use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

    if (!interface_exists(EmailContentProviderInterface::class)) {
        interface EmailContentProviderInterface
        {
            public function supports(MetaMessage $message): bool;
            public function originalHtml(MetaMessage $message): ?string;
            public function attachment(MetaMessage $message, int $index): ?array;
        }
    }
}

namespace MauticPlugin\MauticMultiMailBundle\Tests\Fixtures {
    use Doctrine\ORM\EntityManagerInterface;

    /** Doctrine stand-in: identity map, ids on flush, findBy/findOneBy over stored properties. */
    final class MemoryEntityManager implements EntityManagerInterface
    {
        public array $objects = [];
        public int $flushes = 0;
        public array $locks = [];
        /**
         * Changes Mautic would silently drop. Its ClassMetadataBuilder sets DEFERRED_EXPLICIT on every
         * entity, so flush() writes only what was persist()ed since the previous flush; a setter
         * followed by a bare flush() never reaches the database.
         *
         * @var list<string>
         */
        public array $unsaved = [];
        private int $sequence = 0;
        /** @var array<int, true> */
        private array $pending = [];
        /** @var array<int, string> what the "database" holds for each managed object */
        private array $saved = [];

        public function persist(object $object): void
        {
            $this->objects[$object::class][spl_object_id($object)] = $object;
            $this->pending[spl_object_id($object)] = true;
        }

        public function flush(): void
        {
            ++$this->flushes;
            foreach ($this->objects as $objects) {
                foreach ($objects as $object) {
                    if (property_exists($object, 'id') && $object->id === null) { $object->id = ++$this->sequence; }
                    $key = spl_object_id($object);
                    $state = self::state($object);
                    if (isset($this->pending[$key])) {
                        $this->saved[$key] = $state;
                    } elseif (isset($this->saved[$key]) && $this->saved[$key] !== $state) {
                        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
                        $this->unsaved[] = (new \ReflectionClass($object))->getShortName().'#'.($object->id ?? '?').' (flush from '.basename($caller['file'] ?? '?').':'.($caller['line'] ?? '?').')';
                        $this->saved[$key] = $state;
                    }
                }
            }
            $this->pending = [];
        }

        /** Scalar and array fields only: a change in a related entity belongs to that entity. */
        private static function state(object $object): string
        {
            return serialize(array_filter((array) $object, static fn (mixed $value): bool => !is_object($value)));
        }

        public function find(string $className, mixed $id): ?object
        {
            foreach ($this->objects[$className] ?? [] as $object) { if ($object->id === $id) { return $object; } }
            return null;
        }

        public function getRepository(string $className): object
        {
            $manager = $this;
            return new class($manager, $className) {
                public function __construct(private MemoryEntityManager $manager, private string $className) {}
                public function findOneBy(array $criteria): ?object { return $this->findBy($criteria, null, 1)[0] ?? null; }
                public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
                {
                    $found = array_values(array_filter($this->manager->objects[$this->className] ?? [], function (object $object) use ($criteria): bool {
                        foreach ($criteria as $field => $value) {
                            $actual = $object->{$field};
                            if (is_array($value) ? !in_array($actual, $value, true) : $actual !== $value) { return false; }
                        }
                        return true;
                    }));
                    foreach (array_reverse($orderBy ?? []) as $field => $direction) {
                        usort($found, fn(object $a, object $b): int => ($direction === 'DESC' ? -1 : 1) * ($a->{$field} <=> $b->{$field}));
                    }
                    return $limit === null ? $found : array_slice($found, 0, $limit);
                }
            };
        }

        public function getConnection(): object
        {
            $manager = $this;
            return new class($manager) {
                public function __construct(private MemoryEntityManager $manager) {}
                public function fetchOne(string $sql, array $parameters = []): mixed { $this->manager->locks[] = $sql; return 1; }
            };
        }
    }

    final class RecordingInbox implements \MauticPlugin\MauticMetaBundle\Application\Support\InboxIntegrationInterface
    {
        public array $persisted = [], $jobs = [];
        public function ownsSupportInbox(): bool { return true; }
        public function messagePersisted(\MauticPlugin\MauticMetaBundle\Entity\MetaMessage $message): void { $this->persisted[] = $message; }
        public function automationAllowed(\MauticPlugin\MauticMetaBundle\Entity\MetaAsset $asset, string $recipient): bool { return false; }
        public function runAutomationGuarded(\MauticPlugin\MauticMetaBundle\Entity\MetaAsset $asset, string $recipient, callable $operation): mixed { return $operation(); }
        public function outboundJobChanged(\MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob $job): void { $this->jobs[] = $job->getStatus(); }
    }
}
