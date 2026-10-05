<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use MauticPlugin\MauticMultiMailBundle\Application\NativeDsnGuard;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class NativeTransportResolver
{
    public function __construct(
        // Resolve lazily: the native registry also contains our tagged factory.
        #[AutowireServiceClosure('Mautic\\EmailBundle\\Mailer\\Transport\\TransportFactory')]
        private readonly \Closure $factory,
    ) {
    }

    public function resolve(#[\SensitiveParameter] string $dsn): TransportInterface
    {
        try {
            NativeDsnGuard::validate($dsn);
            $transport = ($this->factory)()->fromString($dsn);
            if ($transport instanceof \Mautic\EmailBundle\Mailer\Transport\InvalidTransport) {
                throw new \RuntimeException('Adapter unavailable.');
            }

            // Return the provider's exact object, preserving optional Mautic interfaces.
            return $transport;
        } catch (\Throwable) {
            throw new InvalidArgumentException('DSN nativo indisponível: confira a configuração e o adaptador instalado.');
        }
    }
}
