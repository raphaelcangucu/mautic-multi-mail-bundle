<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Application;

/** Shared atomic-release storage; never place credentials or counters inside the web root. */
final class PrivateStorage
{
    public static function directory(string $projectDir): string
    {
        $resolved = realpath($projectDir) ?: $projectDir;
        $parent = dirname($resolved);
        $directory = basename($parent) === 'releases' ? dirname($parent).'/shared/inbox-mail-private'
            : $parent.'/'.basename($resolved).'-multimail-private';
        clearstatcache(true, $directory);
        if (!file_exists($directory) && !is_link($directory)) {
            $mask = umask(0077);
            try { @mkdir($directory, 0700); } finally { umask($mask); }
        }
        self::guard($directory, true);
        return $directory;
    }

    public static function guard(string $path, bool $directory = false): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || (file_exists($path) && (($directory ? !is_dir($path) : !is_file($path)) || (fileperms($path) & 0077) !== 0))
            || ($directory && !is_dir($path))) {
            throw new \RuntimeException('Private mail storage unavailable.');
        }
    }

    public static function transaction(string $projectDir, string $filename, array $initial, callable $action, bool $write = false): array
    {
        if (!preg_match('/^[a-z-]+\.json$/D', $filename)) { throw new \LogicException('Invalid storage filename.'); }
        $directory = self::directory($projectDir);
        $path = $directory.'/'.$filename;
        $lockPath = $directory.'/'.substr($filename, 0, -5).'.lock';
        self::guard($lockPath);
        $mask = umask(0077);
        try { $lock = @fopen($lockPath, 'c+b'); } finally { umask($mask); }
        if (!$lock || !flock($lock, LOCK_EX)) { throw new \RuntimeException('Private mail storage unavailable.'); }
        try {
            self::guard($lockPath); self::guard($path);
            $data = $initial;
            if (file_exists($path)) {
                if (filesize($path) > 8 * 1024 * 1024) { throw new \RuntimeException('Private mail storage exceeds its safe size.'); }
                try { $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { throw new \RuntimeException('Private mail storage invalid.'); }
                if (!is_array($data) || ($data['version'] ?? null) !== 1) { throw new \RuntimeException('Private mail storage invalid.'); }
            }
            $result = $action($data);
            if ($write) {
                $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                if (strlen($encoded) > 8 * 1024 * 1024) { throw new \RuntimeException('Private mail storage exceeds its safe size.'); }
                $temporary = $path.'.tmp-'.bin2hex(random_bytes(8));
                $mask = umask(0077);
                try { $handle = @fopen($temporary, 'xb'); } finally { umask($mask); }
                if (!$handle) { throw new \RuntimeException('Private mail storage unavailable.'); }
                try {
                    if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle) || !fsync($handle)) {
                        throw new \RuntimeException('Private mail storage unavailable.');
                    }
                    fclose($handle); $handle = null;
                    self::guard($path);
                    if (!rename($temporary, $path)) { throw new \RuntimeException('Private mail storage unavailable.'); }
                } finally {
                    if (is_resource($handle)) { fclose($handle); }
                    if (file_exists($temporary)) { unlink($temporary); }
                }
            }
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
