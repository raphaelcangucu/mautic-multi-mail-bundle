<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

use MauticPlugin\MauticMultiMailBundle\Application\PrivateStorage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Content-addressed private files (`mail-blobs/ab/<sha256>` + `<sha256>.json` descriptor),
 * outside the web root with the same 0700/0600 and symlink guards as the credentials.
 * The total is budgeted in `mail-blobs.json`, so a mailbox flood cannot fill the disk.
 */
final class BlobStore
{
    public const MAX_FILE_BYTES = 26214400;
    public const MAX_TOTAL_BYTES = 2147483648;

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir) {}

    /** @return string|null the sha256, or null over the per-file or total budget */
    public function put(string $bytes, array $meta): ?string
    {
        $sha = hash('sha256', $bytes);
        $size = strlen($bytes);
        if ($size > self::MAX_FILE_BYTES) { return null; }

        return $this->ledger(function (array &$data) use ($sha, $size, $bytes, $meta): array {
            $directory = PrivateStorage::subdirectory($this->projectDir, 'mail-blobs/'.substr($sha, 0, 2));
            $path = $directory.'/'.$sha;
            PrivateStorage::guard($path);
            if (is_file($path)) {
                // Same bytes again: a newer clean scan upgrades an older unscanned copy.
                $descriptor = $this->descriptor($sha);
                if ($descriptor !== null && ($meta['scan'] ?? null) === ClamdScanner::CLEAN && ($descriptor['scan'] ?? null) !== ClamdScanner::CLEAN) {
                    self::write($path.'.json', json_encode(array_replace($descriptor, ['scan' => ClamdScanner::CLEAN]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                return ['sha' => $sha];
            }
            if ($data['total'] + $size > self::MAX_TOTAL_BYTES) { return ['sha' => null]; }
            $descriptor = ['sha256' => $sha, 'size' => $size, 'mime' => (string) ($meta['mime'] ?? 'application/octet-stream'),
                'name' => (string) ($meta['name'] ?? ''), 'kind' => (string) ($meta['kind'] ?? 'attachment'),
                'scan' => (string) ($meta['scan'] ?? 'not_applicable'), 'created_at' => gmdate(DATE_ATOM)];
            // Descriptor first: a blob is never visible without its metadata.
            self::write($path.'.json', json_encode($descriptor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            self::write($path, $bytes);
            $data['total'] += $size;
            ++$data['count'];

            return ['sha' => $sha];
        })['sha'];
    }

    public function descriptor(string $sha): ?array
    {
        $path = $this->path($sha);
        if ($path === null || !is_file($path.'.json')) { return null; }
        PrivateStorage::guard($path.'.json');
        try { $descriptor = json_decode((string) file_get_contents($path.'.json'), true, 4, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return null; }

        return is_array($descriptor) && ($descriptor['sha256'] ?? null) === $sha ? $descriptor : null;
    }

    /** Absolute path of an existing blob, or null for anything that is not a stored sha256. */
    public function path(string $sha): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $sha)) { return null; }
        $directory = PrivateStorage::directory($this->projectDir).'/mail-blobs/'.substr($sha, 0, 2);
        $path = $directory.'/'.$sha;
        if (!is_dir($directory) || !is_file($path)) { return null; }
        PrivateStorage::guard($directory, true);
        PrivateStorage::guard($path);

        return $path;
    }

    public function read(string $sha, int $maxBytes = self::MAX_FILE_BYTES): ?string
    {
        $path = $this->path($sha);
        if ($path === null || filesize($path) > $maxBytes) { return null; }
        $bytes = file_get_contents($path);

        return $bytes === false || !hash_equals($sha, hash('sha256', $bytes)) ? null : $bytes;
    }

    public function updateDescriptor(string $sha, array $changes): void
    {
        $this->ledger(function () use ($sha, $changes): array {
            $descriptor = $this->descriptor($sha);
            if ($descriptor === null) { return []; }
            $path = $this->path($sha);
            self::write($path.'.json', json_encode(array_replace($descriptor, array_intersect_key($changes, ['scan' => true])), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return [];
        });
    }

    /** @return array{total: int, count: int} */
    public function usage(): array
    {
        $data = $this->ledger(fn(array &$data): array => $data, false);

        return ['total' => $data['total'], 'count' => $data['count']];
    }

    private function ledger(callable $action, bool $write = true): array
    {
        return PrivateStorage::transaction($this->projectDir, 'mail-blobs.json', ['version' => 1, 'total' => 0, 'count' => 0],
            function (array &$data) use ($action): array {
                if (!is_int($data['total'] ?? null) || !is_int($data['count'] ?? null) || $data['total'] < 0) { throw new \RuntimeException('Private mail storage invalid.'); }
                return $action($data);
            }, $write);
    }

    private static function write(string $path, string $bytes): void
    {
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(8));
        $mask = umask(0077);
        try { $handle = @fopen($temporary, 'xb'); } finally { umask($mask); }
        if (!$handle) { throw new \RuntimeException('Private mail storage unavailable.'); }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) { throw new \RuntimeException('Private mail storage unavailable.'); }
            fclose($handle); $handle = null;
            PrivateStorage::guard($path);
            if (!rename($temporary, $path)) { throw new \RuntimeException('Private mail storage unavailable.'); }
        } finally {
            if (is_resource($handle)) { fclose($handle); }
            if (file_exists($temporary)) { unlink($temporary); }
        }
    }
}
