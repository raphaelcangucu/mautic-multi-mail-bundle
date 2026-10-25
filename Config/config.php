<?php

declare(strict_types=1);

use MauticPlugin\MauticMultiMailBundle\Controller\ConnectionsController;

return [
    'name' => 'Multi Mail',
    'description' => 'Contas SMTP e APIs com fallback por conexão para o envio nativo do Mautic, e conversa por e-mail em threads no Inbox.',
    'version' => '0.5.1',
    'author' => 'Raphael Cangucu',
    'routes' => ['main' => [
        'mautic_multimail_connections' => ['path' => '/mail-connections', 'controller' => ConnectionsController::class.'::index', 'method' => ['GET', 'POST']],
    ]],
    'menu' => ['main' => [
        'mautic.multimail.connections' => ['id' => 'mautic_multimail_connections', 'route' => 'mautic_multimail_connections', 'access' => 'admin', 'iconClass' => 'ri-mail-settings-line', 'priority' => 22],
    ]],
    'parameters' => [
        // clamd socket for incoming email attachments, e.g. unix:///run/clamav/clamd.ctl or tcp://127.0.0.1:3310.
        // Empty: attachments are stored but never offered for download.
        'multimail_clamd_socket' => '',
    ],
];
