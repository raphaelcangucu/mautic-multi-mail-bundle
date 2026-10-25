<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use MauticPlugin\MauticMultiMailBundle\Mailer\MultiMailTransportFactory;
use MauticPlugin\MauticMultiMailBundle\Mailer\ExampleTransportFactory;
use MauticPlugin\MauticMultiMailBundle\Conversation\{ConversationBridgeInterface, NoopConversationBridge};
use MauticPlugin\MauticMultiMailBundle\Imap\{ImapConnector, ImapConnectorInterface};

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()->defaults()->autowire()->autoconfigure()->public();
    // Value objects and exceptions have scalar constructors: registering them breaks the container compile.
    // Meta/ and Inbox/ depend on optional plugins and are registered below only when those exist.
    $services->load('MauticPlugin\\MauticMultiMailBundle\\', '../')->exclude('../{Config,DependencyInjection,Resources,Translations,Tests,Assets,build,vendor,dist,bin,docs,Meta,Inbox,MauticMultiMailBundle.php,Application/NativeDsnGuard.php,Application/PrivateStorage.php,Application/HourlyQuota.php,Application/TransportStatus.php,Mailer/ConnectionTransport.php,Mailer/QuotaExceededException.php,Mailer/Attempt.php,Mailer/OutcomeHttpClient.php,Mailer/ConfirmedSmtpTransport.php,Mailer/AdditionalApiTransport.php,Mailer/ApiEmail.php,Mailer/PreparedApiTransport.php,Mailer/ProviderResponseGuard.php,Mailer/ProviderResponseException.php,Conversation/InboundEmail.php,Conversation/InboundAttachment.php,Conversation/SendOutcome.php,Imap/ImapClient.php,Imap/SocketImapStream.php,Imap/ImapException.php,Imap/ImapAuthenticationFailed.php,Imap/ImapRefused.php}');
    $services->set(MultiMailTransportFactory::class)->autoconfigure(false)->tag('mailer.transport_factory');
    $services->set(ExampleTransportFactory::class)->autoconfigure(false)->tag('mailer.transport_factory');
    $services->alias(ImapConnectorInterface::class, ImapConnector::class);

    // Without the Meta bundle 0.15+ Multi Mail is only a transport: Noop bridge, no dispatcher, no assets.
    if (interface_exists(\MauticPlugin\MauticMetaBundle\Application\Queue\ChannelDispatcherInterface::class)
        && class_exists(\MauticPlugin\MauticMetaBundle\Application\Queue\ChannelDispatchers::class)
        && class_exists(\MauticPlugin\MauticMetaBundle\Entity\MetaMessage::class)) {
        $services->set(\MauticPlugin\MauticMultiMailBundle\Meta\EmailIngestor::class);
        $services->set(\MauticPlugin\MauticMultiMailBundle\Meta\MetaConversationBridge::class);
        $services->alias(ConversationBridgeInterface::class, \MauticPlugin\MauticMultiMailBundle\Meta\MetaConversationBridge::class);
        // The tag is the only link between the Meta queue's `email_message` and this channel:
        // ChannelDispatchers indexes dispatchers by asset_type and picks the job asset's one.
        $services->set(\MauticPlugin\MauticMultiMailBundle\Meta\EmailChannelDispatcher::class)
            ->tag(\MauticPlugin\MauticMetaBundle\Application\Queue\ChannelDispatchers::TAG, ['asset_type' => \MauticPlugin\MauticMetaBundle\Domain\AssetType::EmailAccount->value]);
    } else {
        $services->alias(ConversationBridgeInterface::class, NoopConversationBridge::class);
    }
    if (interface_exists(\MauticPlugin\MauticInboxBundle\Contract\EmailContentProviderInterface::class)) {
        $services->set(\MauticPlugin\MauticMultiMailBundle\Inbox\EmailContentProvider::class)->tag('mautic.inbox.email_content');
    }
};
