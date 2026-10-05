<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Application;

/** Syntax boundary only; the original Mautic factory validates installed adapters. */
final class NativeDsnGuard
{
    public static function validate(#[\SensitiveParameter] string $dsn): void
    {
        if (strlen($dsn) > 16384 || preg_match('/[\x00-\x1F\x7F]/', $dsn)
            || !preg_match('/^(?:[a-z][a-z0-9+.-]*:\/\/|(?:failover|roundrobin)\()/i', $dsn)) {
            throw new \InvalidArgumentException('Informe um DSN nativo válido do transporte instalado.');
        }
        // Nested aliases would bypass the connection graph and could recurse forever.
        if (preg_match('/\bmultimail:\/\//i', $dsn)) {
            throw new \InvalidArgumentException('O DSN nativo não pode apontar para conexões Multi Mail.');
        }
    }
}
