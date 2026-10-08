<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Application;

/** Recipient quotas shared across PHP workers. Reservations survive ambiguous handoff or crashes. */
final class HourlyQuota
{
    public function __construct(private readonly string $projectDir, private readonly ?\Closure $clock = null) {}

    public static function scope(array $connection): string
    {
        return ($connection['quota_group'] ?? '') !== '' ? 'group:'.$connection['quota_group'] : 'connection:'.$connection['id'];
    }

    public function reserve(array $connection, int $recipients, int $limit): array
    {
        if ($recipients < 1 || $recipients > 1000000) { throw new \InvalidArgumentException('Invalid recipient count.'); }
        return $this->transaction(function (array &$data, int $now) use ($connection, $recipients, $limit): array {
            $scope = self::scope($connection);
            $usage = $this->scopeUsage($data, $scope, $now);
            if ($limit > 0 && $usage + $recipients > $limit) {
                return ['allowed' => false, 'retry_at' => $this->retryAt($data['quotas'][$scope] ?? [], $now, $usage + $recipients - $limit)];
            }
            $minute = (int) floor($now / 60);
            $token = bin2hex(random_bytes(16));
            $data['quotas'][$scope][$minute] = ($data['quotas'][$scope][$minute] ?? 0) + $recipients;
            $this->increment($data, $connection['id'], $minute, 'reserved', $recipients);
            $data['reservations'][$token] = ['connection_id' => $connection['id'], 'scope' => $scope, 'minute' => $minute, 'recipients' => $recipients];
            return ['allowed' => true, 'token' => $token];
        }, true);
    }

    public function finish(string $token, string $outcome): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $token) || !in_array($outcome, ['accepted', 'rejected', 'uncertain'], true)) {
            throw new \InvalidArgumentException('Invalid quota outcome.');
        }
        $this->transaction(function (array &$data, int $now) use ($token, $outcome): array {
            $reservation = $data['reservations'][$token] ?? null;
            if ($reservation === null) { return []; }
            $id = $reservation['connection_id']; $minute = $reservation['minute']; $count = $reservation['recipients'];
            if (($data['connections'][$id][$minute]['reserved'] ?? 0) < $count || ($data['quotas'][$reservation['scope']][$minute] ?? 0) < $count) {
                throw new \RuntimeException('Private quota storage invalid.');
            }
            $this->increment($data, $id, $minute, 'reserved', -$count);
            $finishedMinute = max($minute, (int) floor($now / 60));
            $this->increment($data, $id, $finishedMinute, $outcome, $count);
            if ($outcome === 'rejected') {
                $scope = $reservation['scope'];
                $data['quotas'][$scope][$minute] -= $count;
            } elseif ($finishedMinute !== $minute) {
                $scope = $reservation['scope'];
                $data['quotas'][$scope][$minute] -= $count;
                $data['quotas'][$scope][$finishedMinute] = ($data['quotas'][$scope][$finishedMinute] ?? 0) + $count;
            }
            unset($data['reservations'][$token]);
            return [];
        }, true);
    }

    public function snapshot(array $connections): array
    {
        return $this->transaction(function (array &$data, int $now) use ($connections): array {
            $result = [];
            foreach ($connections as $connection) {
                $scope = self::scope($connection); $limit = self::effectiveLimit($connection, $connections);
                $usage = $this->scopeUsage($data, $scope, $now);
                $stats = ['accepted' => 0, 'uncertain' => 0, 'reserved' => 0, 'rejected' => 0];
                $history = [];
                for ($hour = (int) floor($now / 3600) - 23; $hour <= (int) floor($now / 3600); ++$hour) {
                    $history[$hour] = ['utc' => gmdate(DATE_ATOM, $hour * 3600), 'accepted' => 0, 'uncertain' => 0, 'reserved' => 0, 'rejected' => 0];
                }
                foreach ($data['connections'][$connection['id']] ?? [] as $minute => $counts) {
                    foreach ($stats as $name => $unused) {
                        if (($minute + 1) * 60 > $now - 3600) { $stats[$name] += $counts[$name] ?? 0; }
                        $hour = (int) floor($minute / 60);
                        if (isset($history[$hour])) { $history[$hour][$name] += $counts[$name] ?? 0; }
                    }
                }
                // Old unfinished reservations remain charged until reconciled or the 24-hour retention expires.
                foreach ($data['reservations'] as $reservation) {
                    if ($reservation['connection_id'] === $connection['id'] && ($reservation['minute'] + 1) * 60 <= $now - 3600) {
                        $stats['reserved'] += $reservation['recipients'];
                    }
                }
                $native = $connection['provider'] === 'native';
                $enabled = $connection['pool_enabled'] ?? !$native;
                $status = $native ? 'native' : (!$enabled ? 'disabled' : ($limit > 0 && $usage >= $limit ? 'limited' : 'ready'));
                $result[$connection['id']] = $stats + ['used' => $usage, 'limit' => $limit,
                    'remaining' => $limit > 0 ? max(0, $limit - $usage) : null, 'status' => $status,
                    'retry_at' => $limit > 0 && $usage >= $limit ? $this->retryAt($data['quotas'][$scope] ?? [], $now, $usage - $limit + 1) : null,
                    'history' => array_values(array_reverse($history))];
            }
            return $result;
        });
    }

    public static function effectiveLimit(array $connection, array $connections): int
    {
        $limit = (int) ($connection['hourly_limit'] ?? 0);
        if (($connection['quota_group'] ?? '') === '') { return $limit; }
        foreach ($connections as $member) {
            $candidate = (int) ($member['hourly_limit'] ?? 0);
            if (($member['quota_group'] ?? '') === $connection['quota_group'] && $candidate > 0) {
                $limit = $limit > 0 ? min($limit, $candidate) : $candidate;
            }
        }
        return $limit;
    }

    /** Import existing transport acceptance once during activation; input contains only timestamps/counts. */
    public function importAccepted(array $connection, array $minutes, string $reference): void
    {
        if (!preg_match('/^[a-z0-9-]{1,80}$/D', $reference)) { throw new \InvalidArgumentException('Invalid import reference.'); }
        $this->transaction(function (array &$data, int $now) use ($connection, $minutes, $reference): array {
            if (isset($data['imports'][$reference])) { return []; }
            foreach ($minutes as $minute => $count) {
                if (!ctype_digit((string) $minute) || !is_int($count) || $count < 1 || $count > 1000000 || $minute * 60 > $now) {
                    throw new \InvalidArgumentException('Invalid quota import.');
                }
                if (($minute + 1) * 60 <= $now - 3600) { continue; }
                $scope = self::scope($connection);
                $data['quotas'][$scope][$minute] = ($data['quotas'][$scope][$minute] ?? 0) + $count;
                $this->increment($data, $connection['id'], (int) $minute, 'accepted', $count);
            }
            $data['imports'][$reference] = $now;
            return [];
        }, true);
    }

    private function scopeUsage(array $data, string $scope, int $now): int
    {
        $usage = $this->rolling($data['quotas'][$scope] ?? [], $now);
        foreach ($data['reservations'] as $reservation) {
            if ($reservation['scope'] === $scope && ($reservation['minute'] + 1) * 60 <= $now - 3600) { $usage += $reservation['recipients']; }
        }
        return $usage;
    }

    private function rolling(array $buckets, int $now): int
    {
        $sum = 0;
        foreach ($buckets as $minute => $count) { if (($minute + 1) * 60 > $now - 3600) { $sum += $count; } }
        return $sum;
    }

    private function retryAt(array $buckets, int $now, int $needed): ?string
    {
        ksort($buckets, SORT_NUMERIC);
        foreach ($buckets as $minute => $count) {
            $expires = ($minute + 1) * 60 + 3600;
            if ($expires <= $now) { continue; }
            $needed -= $count;
            if ($needed <= 0) { return gmdate(DATE_ATOM, $expires); }
        }
        return null;
    }

    private function increment(array &$data, string $id, int $minute, string $name, int $count): void
    {
        $data['connections'][$id][$minute][$name] = ($data['connections'][$id][$minute][$name] ?? 0) + $count;
    }

    private function transaction(callable $action, bool $write = false): array
    {
        $now = $this->clock ? ($this->clock)() : time();
        return PrivateStorage::transaction($this->projectDir, 'hourly-usage.json',
            ['version' => 1, 'connections' => [], 'quotas' => [], 'reservations' => [], 'imports' => []],
            function (array &$data) use ($action, $now): array {
                foreach (['connections', 'quotas', 'reservations', 'imports'] as $key) {
                    if (!is_array($data[$key] ?? null)) { throw new \RuntimeException('Private quota storage invalid.'); }
                }
                // Fixed minute buckets bound storage and conservatively keep the overlapping oldest minute.
                foreach (['connections', 'quotas'] as $key) {
                    foreach ($data[$key] as &$buckets) {
                        if (!is_array($buckets)) { throw new \RuntimeException('Private quota storage invalid.'); }
                        foreach ($buckets as $minute => $counts) {
                            if (!ctype_digit((string) $minute)) { throw new \RuntimeException('Private quota storage invalid.'); }
                            if ($key === 'quotas') {
                                if (!is_int($counts) || $counts < 0) { throw new \RuntimeException('Private quota storage invalid.'); }
                            } else {
                                if (!is_array($counts)) { throw new \RuntimeException('Private quota storage invalid.'); }
                                foreach ($counts as $name => $count) {
                                    if (!in_array($name, ['accepted', 'reserved', 'uncertain', 'rejected'], true) || !is_int($count) || $count < 0) {
                                        throw new \RuntimeException('Private quota storage invalid.');
                                    }
                                }
                            }
                            if (($minute + 1) * 60 <= $now - 86400) { unset($buckets[$minute]); }
                        }
                    }
                    unset($buckets);
                }
                foreach ($data['reservations'] as $token => $reservation) {
                    if (!is_array($reservation) || !is_int($reservation['minute'] ?? null) || !is_int($reservation['recipients'] ?? null)
                        || $reservation['recipients'] < 1 || !is_string($reservation['scope'] ?? null) || !is_string($reservation['connection_id'] ?? null)) {
                        throw new \RuntimeException('Private quota storage invalid.');
                    }
                    if (($reservation['minute'] + 1) * 60 <= $now - 86400) { unset($data['reservations'][$token]); }
                }
                return $action($data, $now);
            }, $write);
    }
}
