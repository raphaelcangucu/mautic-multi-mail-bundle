<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use MauticPlugin\MauticMultiMailBundle\Mailer\MultiMailTransportFactory;
use MauticPlugin\MauticMultiMailBundle\Mailer\ExampleTransportFactory;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()->defaults()->autowire()->autoconfigure()->public();
    $services->load('MauticPlugin\\MauticMultiMailBundle\\', '../')->exclude('../{Config,DependencyInjection,Resources,Translations,Tests,Assets,build,vendor,dist,MauticMultiMailBundle.php,Application/NativeDsnGuard.php,Application/PrivateStorage.php,Application/HourlyQuota.php,Application/TransportStatus.php,Mailer/ConnectionTransport.php,Mailer/QuotaExceededException.php,Mailer/Attempt.php,Mailer/OutcomeHttpClient.php,Mailer/ConfirmedSmtpTransport.php,Mailer/AdditionalApiTransport.php,Mailer/ApiEmail.php,Mailer/PreparedApiTransport.php,Mailer/ProviderResponseGuard.php,Mailer/ProviderResponseException.php}');
    $services->set(MultiMailTransportFactory::class)->autoconfigure(false)->tag('mailer.transport_factory');
    $services->set(ExampleTransportFactory::class)->autoconfigure(false)->tag('mailer.transport_factory');
};
