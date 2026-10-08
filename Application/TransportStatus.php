<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Application;

/** Public summary only: never display a DSN, host, user, password or query options. */
final class TransportStatus
{
    public static function describe(#[\SensitiveParameter] mixed $dsn, array $connections): array
    {
        $result = ['connection_id' => null, 'name' => null, 'scheme' => 'unknown'];
        if (!is_string($dsn)) { return $result; }
        if (preg_match('/^multimail:\/\/([a-f0-9]{32})$/D', $dsn, $match)) {
            $result['scheme'] = 'multimail';
            foreach ($connections as $connection) {
                if ($connection['id'] === $match[1]) {
                    return array_replace($result, ['connection_id' => $connection['id'], 'name' => $connection['name']]);
                }
            }
            return $result;
        }
        if (preg_match('/^([a-z][a-z0-9+.-]{0,40})(?::\/\/|\()/i', $dsn, $match)) {
            $result['scheme'] = strtolower($match[1]);
        }
        return $result;
    }
}
