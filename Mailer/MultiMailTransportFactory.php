<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Exception\{UnsupportedSchemeException, InvalidArgumentException};
use Symfony\Component\Mailer\Transport\{Dsn, TransportFactoryInterface, TransportInterface};

final class MultiMailTransportFactory implements TransportFactoryInterface
{
    public function __construct(private readonly ConnectionStore $store, private readonly ConnectionBuilder $builder,
        private readonly ?EventDispatcherInterface $dispatcher = null)
    {
    }

    public function supports(Dsn $dsn): bool
    {
        return $dsn->getScheme() === 'multimail';
    }

    public function create(Dsn $dsn): TransportInterface
    {
        if (!$this->supports($dsn)) { throw new UnsupportedSchemeException($dsn, 'multimail', ['multimail']); }
        if (!preg_match('/^[a-f0-9]{32}$/D', $dsn->getHost()) || $dsn->getUser() !== null || $dsn->getPassword() !== null || $dsn->getPort() !== null) {
            throw new InvalidArgumentException('Use apenas o identificador da conexão no transporte Multi Mail.');
        }

        try { $ids = array_column($this->store->overview()['connections'], 'id'); }
        catch (\Throwable) { throw new InvalidArgumentException('Multi Mail: configuração privada indisponível.'); }
        if (!in_array($dsn->getHost(), $ids, true)) { throw new InvalidArgumentException('Multi Mail: conexão não cadastrada.'); }

        return new ConnectionTransport($dsn->getHost(), $this->store, $this->builder, $this->dispatcher);
    }
}
