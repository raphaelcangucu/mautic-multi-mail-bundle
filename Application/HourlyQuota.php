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

    public function reserve(array $connection, int $recipients, int $limit, int $dailyLimit = 0, int $monthlyLimit = 0): array
    {
        if ($recipients < 1 || $recipients > 1000000) { throw new \InvalidArgumentException('Invalid recipient count.'); }
        return $this->transaction(function (array &$data, int $now) use ($connection, $recipients, $limit, $dailyLimit, $monthlyLimit): array {
            $scope = self::scope($connection);
            $usage = $this->scopeUsage($data, $scope, $now);
            $blocked = []; $retry = [];
            if ($limit > 0 && $usage + $recipients > $limit) {
                $blocked[] = 'hourly';
                $retry[] = strtotime($this->retryAt($data['quotas'][$scope] ?? [], $now, $usage + $recipients - $limit) ?? gmdate(DATE_ATOM, $now + 3600));
            }
            foreach (['daily' => $dailyLimit, 'monthly' => $monthlyLimit] as $period => $cap) {
                if (($cap > 0 && $this->periodUsage($data, $scope, $period, $now) + $recipients > $cap)
                    || ($data['blocks'][$scope][$period] ?? 0) > $now) {
                    $blocked[] = $period; $retry[] = self::resetAt($period, $now);
                }
            }
            if ($blocked) { return ['allowed' => false, 'retry_at' => gmdate(DATE_ATOM, max($retry)), 'blocked_by' => $blocked]; }
            $minute = (int) floor($now / 60);
            $token = bin2hex(random_bytes(16));
            $data['quotas'][$scope][$minute] = ($data['quotas'][$scope][$minute] ?? 0) + $recipients;
            foreach (['daily', 'monthly'] as $period) { $this->incrementPeriod($data, $scope, $period, $now, $recipients); }
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
            foreach (['daily', 'monthly'] as $period) {
                if ($outcome === 'rejected' || self::periodKey($period, $minute * 60) !== self::periodKey($period, $finishedMinute * 60)) {
                    $this->incrementPeriod($data, $reservation['scope'], $period, $minute * 60, -$count);
                    if ($outcome !== 'rejected') { $this->incrementPeriod($data, $reservation['scope'], $period, $finishedMinute * 60, $count); }
                }
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
                $periods = ['hourly' => ['used' => $usage, 'limit' => $limit, 'remaining' => $limit > 0 ? max(0, $limit - $usage) : null,
                    'status' => $limit > 0 && $usage >= $limit ? 'limited' : 'ready',
                    'retry_at' => $limit > 0 && $usage >= $limit ? $this->retryAt($data['quotas'][$scope] ?? [], $now, $usage - $limit + 1) : null]];
                foreach (['daily', 'monthly'] as $period) {
                    $used = $this->periodUsage($data, $scope, $period, $now);
                    $cap = self::effectiveLimit($connection, $connections, $period.'_limit');
                    $providerBlocked = ($data['blocks'][$scope][$period] ?? 0) > $now;
                    $limited = $providerBlocked || ($cap > 0 && $used >= $cap);
                    $periods[$period] = ['used' => $used, 'limit' => $cap, 'remaining' => $cap > 0 ? max(0, $cap - $used) : null,
                        'status' => $limited ? 'limited' : 'ready', 'retry_at' => $limited ? gmdate(DATE_ATOM, self::resetAt($period, $now)) : null,
                        'resets_at' => gmdate(DATE_ATOM, self::resetAt($period, $now)), 'provider_blocked' => $providerBlocked];
                }
                $blocked = array_keys(array_filter($periods, fn(array $p): bool => $p['status'] === 'limited'));
                $retryAt = $blocked ? max(array_map(fn(string $period): int => strtotime($periods[$period]['retry_at'] ?? gmdate(DATE_ATOM, $now + 3600)), $blocked)) : null;
                $status = $native ? 'native' : (!$enabled ? 'disabled' : ($blocked ? 'limited' : 'ready'));
                $result[$connection['id']] = $stats + ['used' => $usage, 'limit' => $limit,
                    'remaining' => $limit > 0 ? max(0, $limit - $usage) : null, 'status' => $status,
                    'retry_at' => $retryAt ? gmdate(DATE_ATOM, $retryAt) : null, 'periods' => $periods, 'blocked_by' => $blocked,
                    'history' => array_values(array_reverse($history))];
            }
            return $result;
        });
    }

    public static function effectiveLimit(array $connection, array $connections, string $field = 'hourly_limit'): int
    {
        if (!in_array($field, ['hourly_limit', 'daily_limit', 'monthly_limit'], true)) { throw new \InvalidArgumentException('Invalid quota period.'); }
        $limit = (int) ($connection[$field] ?? 0);
        if (($connection['quota_group'] ?? '') === '') { return $limit; }
        foreach ($connections as $member) {
            $candidate = (int) ($member[$field] ?? 0);
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
                foreach (['daily', 'monthly'] as $period) { $this->incrementPeriod($data, $scope, $period, (int) $minute * 60, $count); }
                $this->increment($data, $connection['id'], (int) $minute, 'accepted', $count);
            }
            $data['imports'][$reference] = $now;
            return [];
        }, true);
    }

    /** Seed actual dashboard usage once under a controlled activation. Never lower charged capacity. */
    public function importPeriodUsage(array $connection, int $daily, int $monthly, string $reference): void
    {
        if ($daily < 0 || $monthly < $daily || $monthly > 1000000000 || !preg_match('/^[a-z0-9-]{1,80}$/D', $reference)) {
            throw new \InvalidArgumentException('Invalid provider usage import.');
        }
        $this->transaction(function (array &$data, int $now) use ($connection, $daily, $monthly, $reference): array {
            if (isset($data['imports'][$reference])) { return []; }
            $scope = self::scope($connection);
            foreach (['daily' => $daily, 'monthly' => $monthly] as $period => $observed) {
                $key = self::periodKey($period, $now); $pending = 0;
                foreach ($data['reservations'] as $reservation) {
                    if ($reservation['scope'] === $scope && self::periodKey($period, $reservation['minute'] * 60) === $key) { $pending += $reservation['recipients']; }
                }
                $current = $data['periods'][$scope][$period][$key] ?? 0;
                $data['periods'][$scope][$period][$key] = max($current, $observed + $pending);
            }
            $data['imports'][$reference] = $now;
            return [];
        }, true);
    }

    public function block(array $connection, string $period): void
    {
        if (!in_array($period, ['daily', 'monthly'], true)) { throw new \InvalidArgumentException('Invalid provider quota period.'); }
        $this->transaction(function (array &$data, int $now) use ($connection, $period): array {
            $data['blocks'][self::scope($connection)][$period] = self::resetAt($period, $now);
            return [];
        }, true);
    }

    private static function periodKey(string $period, int $now): string { return gmdate($period === 'daily' ? 'Y-m-d' : 'Y-m', $now); }

    public static function resetAt(string $period, int $now): int
    {
        $date = (new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('UTC'));
        return match ($period) {
            'daily' => $date->modify('tomorrow')->setTime(0, 0)->getTimestamp(),
            'monthly' => $date->modify('first day of next month')->setTime(0, 0)->getTimestamp(),
            default => throw new \InvalidArgumentException('Invalid quota period.'),
        };
    }

    private function incrementPeriod(array &$data, string $scope, string $period, int $at, int $count): void
    {
        $key = self::periodKey($period, $at);
        $value = ($data['periods'][$scope][$period][$key] ?? 0) + $count;
        if ($value < 0) { throw new \RuntimeException('Private quota storage invalid.'); }
        $data['periods'][$scope][$period][$key] = $value;
    }

    private function periodUsage(array $data, string $scope, string $period, int $now): int
    {
        $key = self::periodKey($period, $now); $used = $data['periods'][$scope][$period][$key] ?? 0;
        foreach ($data['reservations'] as $reservation) {
            if ($reservation['scope'] === $scope && self::periodKey($period, $reservation['minute'] * 60) !== $key) { $used += $reservation['recipients']; }
        }
        return $used;
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
            ['version' => 1, 'connections' => [], 'quotas' => [], 'reservations' => [], 'imports' => [], 'periods' => [], 'blocks' => []],
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
                // Upgrade the existing ledger in memory; the next write persists it atomically.
                if (!array_key_exists('periods', $data)) {
                    $data['periods'] = [];
                    foreach ($data['quotas'] as $scope => $buckets) {
                        foreach ($buckets as $minute => $count) {
                            foreach (['daily', 'monthly'] as $period) { $this->incrementPeriod($data, $scope, $period, (int) $minute * 60, $count); }
                        }
                    }
                }
                $data += ['blocks' => []];
                if (!is_array($data['periods']) || !is_array($data['blocks'])) { throw new \RuntimeException('Private quota storage invalid.'); }
                foreach ($data['periods'] as &$periods) {
                    if (!is_array($periods)) { throw new \RuntimeException('Private quota storage invalid.'); }
                    foreach ($periods as $period => &$buckets) {
                        if (!in_array($period, ['daily', 'monthly'], true) || !is_array($buckets)) { throw new \RuntimeException('Private quota storage invalid.'); }
                        foreach ($buckets as $key => $count) {
                            $format = $period === 'daily' ? '!Y-m-d' : '!Y-m';
                            $date = \DateTimeImmutable::createFromFormat($format, (string) $key, new \DateTimeZone('UTC'));
                            if (!$date || self::periodKey($period, $date->getTimestamp()) !== (string) $key || !is_int($count) || $count < 0) { throw new \RuntimeException('Private quota storage invalid.'); }
                            if ($date->getTimestamp() < $now - ($period === 'daily' ? 45 : 400) * 86400) { unset($buckets[$key]); }
                        }
                    }
                    unset($buckets);
                }
                unset($periods);
                foreach ($data['blocks'] as &$periods) {
                    if (!is_array($periods)) { throw new \RuntimeException('Private quota storage invalid.'); }
                    foreach ($periods as $period => $until) {
                        if (!in_array($period, ['daily', 'monthly'], true) || !is_int($until)) { throw new \RuntimeException('Private quota storage invalid.'); }
                        if ($until <= $now) { unset($periods[$period]); }
                    }
                }
                unset($periods);
                return $action($data, $now);
            }, $write);
    }
}
