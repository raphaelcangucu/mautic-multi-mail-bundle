<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Pure: no kernel, database, filesystem or network.
require __DIR__.'/bootstrap.php';

use MauticPlugin\MauticMultiMailBundle\Conversation\{MessageIds, ThreadToken};

function token(bool $ok, string $reason): void { if (!$ok) { throw new RuntimeException($reason); } }

$tokens = new ThreadToken('unit-secret-key');
$token = $tokens->encode(31, 812);
token(preg_match('/^r[a-z2-7]{24}$/D', $token) === 1 && strlen($token) === 25, 'Token is r + 24 lowercase base32 characters');
token(strlen('contato+'.$token) <= 64, 'Token fits the 64-character local part with a long mailbox name');
token($tokens->decode($token, 31) === 812, 'Round trip');
token($tokens->decode(strtoupper($token), 31) === 812, 'Servers that fold the local part to upper case still match');
token($tokens->encode(31, 812) === $token, 'Deterministic for one conversation');
token($tokens->decode($token, 32) === null, 'A token from another account is ignored');
token((new ThreadToken('rotated-secret'))->decode($token, 31) === null, 'A rotated secret_key invalidates old tokens');
foreach (range(1, 24) as $position) {
    $tampered = $token;
    $tampered[$position] = $tampered[$position] === 'a' ? 'b' : 'a';
    token($tokens->decode($tampered, 31) === null, 'Tampered character '.$position);
}
token($tokens->decode(substr($token, 0, -1), 31) === null && $tokens->decode($token.'a', 31) === null, 'Wrong length');
token($tokens->decode('x'.substr($token, 1), 31) === null && $tokens->decode('r'.str_repeat('1', 24), 31) === null, 'Wrong prefix or alphabet');
// Same MAC, different version byte: encode version 2 by hand and expect refusal.
$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
$bits = '';
foreach (str_split(chr(2).pack('N', 812).str_repeat("\0", 10)) as $byte) { $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT); }
$future = 'r'.implode('', array_map(fn(string $chunk): string => $alphabet[bindec($chunk)], str_split($bits, 5)));
token($tokens->decode($future, 31) === null, 'Unknown version is refused');
token($tokens->decode('r'.str_repeat('a', 24), 31) === null, 'All-zero conversation id refused');
foreach ([[0, 1], [31, 0], [31, 0x100000000]] as [$asset, $conversation]) {
    try { $tokens->encode($asset, $conversation); throw new LogicException('Accepted invalid reference'); } catch (InvalidArgumentException) {}
}
token($tokens->encode(31, 0xFFFFFFFF) !== '' && $tokens->decode($tokens->encode(31, 0xFFFFFFFF), 31) === 0xFFFFFFFF, 'Largest 32-bit conversation id');
foreach (['', null] as $missing) {
    $unconfigured = new ThreadToken($missing); // Container still compiles; only the send fails.
    try { $unconfigured->encode(31, 812); throw new LogicException('Empty secret accepted'); } catch (DomainException) {}
    token($unconfigured->decode($token, 31) === null, 'No key, no match');
}

$candidates = ThreadToken::candidates(['Contato+'.strtoupper($token).'@Macro.test', 'contato@macro.test', 'other+'.$token.'@macro.test',
    'contato+'.$token.'@elsewhere.test', 'contato+rshort@macro.test'], 'contato@macro.test');
token($candidates === [$token], 'Only +tags of the account mailbox are candidates, case-insensitively');
token(ThreadToken::untagged('contato+'.$token.'@macro.test', 'contato@macro.test') === 'contato@macro.test', 'Displayed To hides the token');
token(ThreadToken::untagged('joao@example.com', 'contato@macro.test') === 'joao@example.com', 'Other addresses unchanged');

$id = MessageIds::generate(31, 'Contato@Macro.Test');
token(preg_match('/^<[a-f0-9]{32}\.31@macro\.test>$/D', $id) === 1 && MessageIds::looksOwn($id), 'Generated Message-ID format');
token($id !== MessageIds::generate(31, 'contato@macro.test'), 'Random part');
token(MessageIds::normalize(' abc@x.test ') === '<abc@x.test>' && MessageIds::normalize('<a b@x>') === null && MessageIds::normalize('') === null, 'Normalization');
token(MessageIds::parseList("<a@x>\r\n <b@x> <A@x>") === ['<a@x>', '<b@x>'], 'Reference list in order without duplicates');
token(MessageIds::externalId(31, '<ABC@x.test>') === MessageIds::externalId(31, '<abc@x.test>'), 'Scoped id is case-insensitive');
token(MessageIds::externalId(31, '<abc@x.test>') !== MessageIds::externalId(32, '<abc@x.test>'), 'Scoped id differs per asset');
token(str_starts_with(MessageIds::externalId(31, '<abc@x.test>'), 'email:') && strlen(MessageIds::externalId(31, '<abc@x.test>')) === 70, 'email: + sha256');
echo "PASS: thread token round trip, upper case, tampering, other account, rotated key, version, bounds, +tag candidates and Message-ID rules; no kernel/database/network\n";
