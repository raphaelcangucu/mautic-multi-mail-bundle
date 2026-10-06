<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle;

use Mautic\PluginBundle\Bundle\PluginBundleBase;
use MauticPlugin\MauticMultiMailBundle\DependencyInjection\Compiler\ExampleTransportPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MauticMultiMailBundle extends PluginBundleBase
{
    public const MINIMUM_MAUTIC_VERSION = '7.2.0-rc';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ExampleTransportPass());
    }

    public function __construct()
    {
        // Load in every kernel boot, including when its container is already cached.
        // Mautic's framework autoloader keeps precedence over bundled dependencies.
        if (is_file(__DIR__.'/vendor/autoload.php')) {
            $loader = require __DIR__.'/vendor/autoload.php';
            $loader->unregister();
            $loader->register(false);
        }
    }
}
