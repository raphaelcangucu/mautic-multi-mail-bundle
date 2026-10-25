<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Thread anchor carried in the Reply-To +tag: `r` + lowercase unpadded base32 of
 * version(1) + conversation id(4, big endian) + HMAC-SHA256 truncated to 10 bytes.
 *
 * The asset is part of the MAC, so a token copied into another account's mailbox never
 * matches there. Lowercase because some servers fold the local part. Rotating Mautic's
 * secret_key invalidates old tokens; threading then falls back to the headers.
 */
final class ThreadToken
{
    private const VERSION = 1;
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    private readonly ?string $key;

    /** Mautic resolves secret_key through a nullable env: a missing key fails one send, never the container. */
    public function __construct(#[Autowire('%mautic.secret_key%')] #[\SensitiveParameter] ?string $secret)
    {
        $this->key = $secret === null || $secret === '' ? null : hash_hkdf('sha256', $secret, 32, 'multimail-thread-v1');
    }

    public function encode(int $assetId, int $conversationId): string
    {
        if ($this->key === null) { throw new \DomainException('O secret_key do Mautic não está configurado.'); }
        if ($assetId < 1 || $conversationId < 1 || $conversationId > 0xFFFFFFFF) {
            throw new \InvalidArgumentException('Invalid thread reference.');
        }

        return 'r'.self::base32(chr(self::VERSION).pack('N', $conversationId).$this->mac($assetId, $conversationId));
    }

    /** The conversation id, or null for anything forged, truncated, foreign or of an unknown version. */
    public function decode(string $token, int $assetId): ?int
    {
        $token = strtolower($token);
        if ($this->key === null || $assetId < 1 || !preg_match('/^r[a-z2-7]{24}$/D', $token)) { return null; }
        $bytes = self::unbase32(substr($token, 1));
        if ($bytes === null || strlen($bytes) !== 15 || ord($bytes[0]) !== self::VERSION) { return null; }
        $conversationId = unpack('N', substr($bytes, 1, 4))[1];
        if ($conversationId < 1) { return null; }

        return hash_equals($this->mac($assetId, $conversationId), substr($bytes, 5)) ? $conversationId : null;
    }

    /** Tokens found in the +tag of addresses that belong to the account mailbox. */
    public static function candidates(array $addresses, string $accountAddress): array
    {
        [$local, $domain] = self::split($accountAddress);
        $found = [];
        foreach ($addresses as $address) {
            [$candidateLocal, $candidateDomain] = self::split((string) $address);
            if ($candidateDomain !== $domain || !str_starts_with($candidateLocal, $local.'+')) { continue; }
            $tag = substr($candidateLocal, strlen($local) + 1);
            if (preg_match('/^r[a-z2-7]{24}$/D', $tag)) { $found[$tag] = true; }
        }

        return array_keys($found);
    }

    /** The account address itself for any +tagged variant of it; other addresses unchanged. */
    public static function untagged(string $address, string $accountAddress): string
    {
        [$local, $domain] = self::split($address);
        [$accountLocal, $accountDomain] = self::split($accountAddress);
        if ($domain === $accountDomain && ($local === $accountLocal || str_starts_with($local, $accountLocal.'+'))) {
            return $accountAddress;
        }

        return $address;
    }

    private function mac(int $assetId, int $conversationId): string
    {
        return substr(hash_hmac('sha256', 'multimail-thread|v1|'.$assetId.'|'.$conversationId, (string) $this->key, true), 0, 10);
    }

    /** @return array{0: string, 1: string} lowercase local part and domain */
    private static function split(string $address): array
    {
        $address = strtolower(trim($address));
        $at = strrpos($address, '@');

        return $at === false ? [$address, ''] : [substr($address, 0, $at), substr($address, $at + 1)];
    }

    private static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) { $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT); }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) { $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))]; }

        return $out;
    }

    private static function unbase32(string $text): ?string
    {
        $bits = '';
        foreach (str_split($text) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value === false) { return null; }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $byte) { $out .= chr(bindec($byte)); }

        return $out;
    }
}
