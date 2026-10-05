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
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?NativeTransportResolver $native = null)
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

        try { $connections = $this->store->overview()['connections']; $ids = array_column($connections, 'id'); }
        catch (\Throwable) { throw new InvalidArgumentException('Multi Mail: configuração privada indisponível.'); }
        if (!in_array($dsn->getHost(), $ids, true)) { throw new InvalidArgumentException('Multi Mail: conexão não cadastrada.'); }

        foreach ($connections as $connection) {
            if ($connection['id'] === $dsn->getHost() && $connection['provider'] === 'native') {
                if ($this->native === null) { throw new InvalidArgumentException('Fábrica nativa do Mautic indisponível.'); }
                try { $private = $this->store->transportChain($connection['id'])[0]; }
                catch (\Throwable) { throw new InvalidArgumentException('Multi Mail: configuração privada indisponível.'); }

                return $this->native->resolve($private['secrets']['dsn']);
            }
        }

        return new ConnectionTransport($dsn->getHost(), $this->store, $this->builder, $this->dispatcher);
    }
}
