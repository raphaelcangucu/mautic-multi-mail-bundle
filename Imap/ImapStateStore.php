<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Imap;

use MauticPlugin\MauticMultiMailBundle\Application\PrivateStorage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `imap-state.json`: per connection the mailbox position (UIDVALIDITY, last stored UID) and
 * the failure bookkeeping (consecutive failures, next attempt, start of the problem).
 */
final class ImapStateStore
{
    public const DEFAULTS = ['position' => null, 'uidvalidity' => null, 'last_uid' => 0, 'last_success_at' => null, 'last_error' => null,
        'failures' => 0, 'next_attempt_at' => null, 'problem_since' => null, 'status' => 'ok', 'auth_failed' => false, 'auth_stamp' => null, 'notified' => null];

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir) {}

    public function get(string $id): array
    {
        return $this->all()[$id] ?? self::DEFAULTS;
    }

    /** @return array<string, array> */
    public function all(): array
    {
        return $this->transaction(fn(array &$data): array => array_map(fn(array $state): array => $state + self::DEFAULTS, $data['connections']));
    }

    /** Merge $changes into one connection's state atomically and return the result. */
    public function update(string $id, array $changes): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new \InvalidArgumentException('Invalid connection.'); }
        $unknown = array_diff_key($changes, self::DEFAULTS);
        if ($unknown !== []) { throw new \InvalidArgumentException('Invalid IMAP state key.'); }

        return $this->transaction(function (array &$data) use ($id, $changes): array {
            $data['connections'][$id] = array_replace(($data['connections'][$id] ?? []) + self::DEFAULTS, $changes);
            return $data['connections'][$id];
        }, true);
    }

    /** Forget connections that no longer exist. */
    public function prune(array $ids): void
    {
        $this->transaction(function (array &$data) use ($ids): array {
            $data['connections'] = array_intersect_key($data['connections'], array_flip($ids));
            return [];
        }, true);
    }

    private function transaction(callable $action, bool $write = false): array
    {
        return PrivateStorage::transaction($this->projectDir, 'imap-state.json', ['version' => 1, 'connections' => []],
            function (array &$data) use ($action): array {
                if (!is_array($data['connections'] ?? null)) { throw new \RuntimeException('Private mail storage invalid.'); }
                foreach ($data['connections'] as $id => $state) {
                    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id) || !is_array($state) || !is_int($state['last_uid'] ?? 0) || ($state['last_uid'] ?? 0) < 0) {
                        throw new \RuntimeException('Private mail storage invalid.');
                    }
                }
                return $action($data);
            }, $write);
    }
}
