<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle;

use Mautic\PluginBundle\Bundle\PluginBundleBase;

final class MauticMultiMailBundle extends PluginBundleBase
{
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
