<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require is_file(__DIR__.'/../build/dependencies/vendor/autoload.php')
    ? __DIR__.'/../build/dependencies/vendor/autoload.php' : __DIR__.'/../vendor/autoload.php';
spl_autoload_register(function (string $class): void {
    $prefix = 'MauticPlugin\\MauticMultiMailBundle\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__.'/../'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }
});
// Unmodified contracts/factory from Mautic 7.2. Installed Mautic autoloaders take precedence.
spl_autoload_register(function (string $class): void {
    $prefix = 'Mautic\\EmailBundle\\Mailer\\Transport\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__.'/Fixtures/MauticNative/'.substr($class, strlen($prefix)).'.php';
        if (is_file($path)) { require $path; }
    }
});
