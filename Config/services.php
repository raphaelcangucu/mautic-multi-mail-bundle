<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use MauticPlugin\MauticMultiMailBundle\Mailer\MultiMailTransportFactory;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()->defaults()->autowire()->autoconfigure()->public();
    $services->load('MauticPlugin\\MauticMultiMailBundle\\', '../')->exclude('../{Config,DependencyInjection,Resources,Translations,Tests,Assets,build,vendor,dist,MauticMultiMailBundle.php,Application/NativeDsnGuard.php,Mailer/ConnectionTransport.php,Mailer/Attempt.php,Mailer/OutcomeHttpClient.php,Mailer/ConfirmedSmtpTransport.php}');
    $services->set(MultiMailTransportFactory::class)->autoconfigure(false)->tag('mailer.transport_factory');
};
