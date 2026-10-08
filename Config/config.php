<?php

declare(strict_types=1);

use MauticPlugin\MauticMultiMailBundle\Controller\ConnectionsController;

return [
    'name' => 'Multi Mail',
    'description' => 'Contas SMTP e APIs com fallback por conexão para o envio nativo do Mautic.',
    'version' => '0.4.0',
    'author' => 'Raphael Cangucu',
    'routes' => ['main' => [
        'mautic_multimail_connections' => ['path' => '/mail-connections', 'controller' => ConnectionsController::class.'::index', 'method' => ['GET', 'POST']],
    ]],
    'menu' => ['main' => [
        'mautic.multimail.connections' => ['id' => 'mautic_multimail_connections', 'route' => 'mautic_multimail_connections', 'access' => 'admin', 'iconClass' => 'ri-mail-settings-line', 'priority' => 22],
    ]],
];
