<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\DependencyInjection\Compiler;

use MauticPlugin\MauticMultiMailBundle\Mailer\ExampleTransport;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ExampleTransportPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('mailer.transports')) { return; }
        $definition = $container->getDefinition('mailer.transports');
        $transports = $definition->getArgument(0);
        if (!is_array($transports) || isset($transports[ExampleTransport::NAME])) {
            throw new \LogicException('Multi Mail: incompatible named mail transport configuration.');
        }
        // Append only: preserve the original default, names, DSNs and optional interfaces.
        $transports[ExampleTransport::NAME] = 'multimail-example://default';
        $definition->setArgument(0, $transports);
    }
}
