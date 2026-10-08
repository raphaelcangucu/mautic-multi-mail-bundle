<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\{Dsn, TransportFactoryInterface, TransportInterface};

final class ExampleTransportFactory implements TransportFactoryInterface
{
    public function __construct(private readonly ExampleTransport $transport) {}
    public function supports(Dsn $dsn): bool { return $dsn->getScheme() === 'multimail-example'; }
    public function create(Dsn $dsn): TransportInterface
    {
        if (!$this->supports($dsn)) { throw new UnsupportedSchemeException($dsn, 'multimail-example', ['multimail-example']); }
        return $this->transport;
    }
}
