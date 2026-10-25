<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__.'/../Application/ConnectionStore.php';
require __DIR__.'/../Application/PrivateStorage.php';
require __DIR__.'/../Application/HourlyQuota.php';
require __DIR__.'/../Application/NativeDsnGuard.php';

use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;

function check(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
function refuses(callable $action, string $class = InvalidArgumentException::class): void {
    try { $action(); } catch (Throwable $error) {
        check($error instanceof $class, 'Unexpected error category');
        check(!str_contains($error->getMessage(), 'unit-secret'), 'Secret in error');
        return;
    }
    throw new RuntimeException('Expected refusal');
}

$root = sys_get_temp_dir().'/mautic_mail_unit_'.bin2hex(random_bytes(8));
mkdir($root.'/project', 0700, true);
$store = new ConnectionStore($root.'/project');
$private = $root.'/project-multimail-private';
$input = ['name' => 'SMTP A', 'provider' => 'smtp', 'from_email' => 'support@example.com', 'from_name' => 'Support',
    'reply_to' => 'reports@example.com', 'fallback' => '',
    'settings' => ['host' => 'smtp.example.com', 'port' => '587', 'encryption' => 'tls', 'username' => 'support@example.com'],
    'secrets' => ['password' => 'unit-secret^#']];
try {
    check($store->overview() === ['revision' => 0, 'connections' => []], 'Empty registry');
    mkdir($root.'/other', 0700);
    $other = new ConnectionStore($root.'/other');
    check($other->overview() === ['revision' => 0, 'connections' => []], 'Separate installation starts empty');
    $a = $store->save($input, 0, 8);
    check($other->overview() === ['revision' => 0, 'connections' => []], 'Separate installation never shares credentials');
    check($a['revision'] === 1 && count($a['connections']) === 1, 'Persist first connection');
    $idA = $a['connections'][0]['id'];
    check($a['connections'][0]['secret_configured']['password'], 'Credential status available');
    check(!str_contains(json_encode($a), 'unit-secret'), 'Public view never returns credentials');
    check((fileperms($private) & 0777) === 0700 && (fileperms($private.'/connections.json') & 0777) === 0600
        && (fileperms($private.'/connections.lock') & 0777) === 0600, 'Private permissions');
    $b = $store->save(array_replace($input, ['name' => 'SMTP B']), 1, 8);
    $idB = $b['connections'][1]['id'];
    $resend = array_replace($input, ['provider' => 'resend', 'name' => 'Resend', 'settings' => [],
        'secrets' => ['api_key' => 're_unit-secret-abcdefghijklmnop'], 'fallback' => $idB]);
    $c = $store->save($resend, 2, 8);
    $idC = $c['connections'][2]['id'];
    $d = $store->save(array_replace($input, ['id' => $idA, 'fallback' => $idC, 'secrets' => ['password' => '']]), 3, 8);
    check(array_column($d['connections'][0]['chain'], 'id') === [$idC, $idB], 'Per-connection ordered fallback chain');
    check(array_column($store->transportChain($idA), 'id') === [$idA, $idC, $idB], 'Native transport reads ordered internal chain');
    refuses(fn() => $store->transportChain(str_repeat('f', 32)), RuntimeException::class);
    $disk = json_decode(file_get_contents($private.'/connections.json'), true);
    check($disk['connections'][$idA]['secrets']['password'] === 'unit-secret^#', 'Blank edit preserves exact credential');
    check(!array_key_exists('expires_at', $disk['connections'][$idA]), 'No automatic expiry');
    refuses(fn() => $store->save(array_replace($input, ['id' => $idB, 'fallback' => $idA]), 4, 8));
    refuses(fn() => $store->save(array_replace($input, ['id' => $idB, 'fallback' => $idB]), 4, 8));
    refuses(fn() => $store->save(array_replace($input, ['id' => $idB, 'fallback' => str_repeat('f', 32)]), 4, 8));
    check($store->overview()['revision'] === 4, 'Failed graph changes are atomic');
    refuses(fn() => $store->remove($idB, 4));
    refuses(fn() => $store->save($input, 3, 8), DomainException::class);
    refuses(fn() => $store->save(array_replace($input, ['id' => $idA, 'provider' => 'ses']), 4, 8));
    foreach ([['name' => "bad\r\nheader"], ['reply_to' => 'bad'], ['secrets' => 'unit-secret'],
        ['settings' => ['host' => 'smtp.example.com', 'port' => '25', 'encryption' => 'tls', 'username' => 'support@example.com']]] as $change) {
        refuses(fn() => $store->save(array_replace($input, $change), 4, 8));
    }
    $apiInputs = [
        ['provider' => 'ses', 'name' => 'SES São Paulo', 'settings' => ['region' => 'sa-east-1'], 'secrets' => ['access_key' => 'unit-secret-id', 'secret_key' => 'unit-secret-key']],
        ['provider' => 'mailgun', 'name' => 'Mailgun EU', 'settings' => ['domain' => 'mg.example.com', 'region' => 'eu'], 'secrets' => ['api_key' => 'unit-secret-api']],
        ['provider' => 'sendgrid', 'name' => 'SendGrid', 'settings' => [], 'secrets' => ['api_key' => 'unit-secret-api']],
        ['provider' => 'postmark', 'name' => 'Postmark', 'settings' => [], 'secrets' => ['api_key' => 'unit-secret-api']],
        ['provider' => 'brevo', 'name' => 'Brevo', 'settings' => [], 'secrets' => ['api_key' => 'unit-secret-api']],
    ];
    $revision = 4;
    foreach ($apiInputs as $change) {
        $view = $store->save(array_replace($input, $change), $revision++, 8);
        check(!str_contains(json_encode($view), 'unit-secret'), 'API secrets excluded');
    }
    refuses(fn() => $store->save(array_replace($input, $apiInputs[0], ['settings' => ['region' => 'invalid']]), $revision, 8));
    refuses(fn() => $store->save(array_replace($input, $apiInputs[1], ['settings' => ['domain' => 'mg.example.com', 'region' => 'invalid']]), $revision, 8));
    $last = $view['connections'][count($view['connections']) - 1]['id'];
    $removed = $store->remove($last, $revision);
    check(count($removed['connections']) === 7, 'Remove unreferenced connection');
    $nativeInput = array_replace($input, ['provider' => 'native', 'name' => 'Installed transport', 'settings' => [],
        'secrets' => ['dsn' => 'failover(smtp://user:unit-secret@smtp.example.com:587 null://default)']]);
    $nativeView = $store->save($nativeInput, $removed['revision'], 8);
    $nativeId = $nativeView['connections'][array_key_last($nativeView['connections'])]['id'];
    check(!str_contains(json_encode($nativeView), 'unit-secret'), 'Native DSN never exposed');
    refuses(fn() => $store->save(array_replace($nativeInput, ['id' => $nativeId, 'fallback' => $idA]), $nativeView['revision'], 8));
    refuses(fn() => $store->save(array_replace($input, ['id' => $idA, 'fallback' => $nativeId]), $nativeView['revision'], 8));
    foreach (['multimail://'.$idA, 'failover(smtp://user:unit-secret@smtp.example.com MULTIMAIL://'.$idA.')', 'invalid', "smtp://user:unit-secret@host\r\n"] as $dsn) {
        refuses(fn() => $store->save(array_replace($nativeInput, ['secrets' => ['dsn' => $dsn]]), $nativeView['revision'], 8));
    }
    chmod($private.'/connections.json', 0644);
    refuses(fn() => $store->overview(), RuntimeException::class);
    chmod($private.'/connections.json', 0600);
    chmod($private.'/connections.lock', 0644);
    refuses(fn() => $store->overview(), RuntimeException::class);
    chmod($private.'/connections.lock', 0600);
    chmod($private, 0755);
    refuses(fn() => $store->overview(), RuntimeException::class);
    chmod($private, 0700);
    rename($private.'/connections.json', $private.'/source.json');
    symlink($private.'/source.json', $private.'/connections.json');
    refuses(fn() => $store->overview(), RuntimeException::class);
    unlink($private.'/connections.json');
    file_put_contents($private.'/connections.json', '{invalid'); chmod($private.'/connections.json', 0600);
    refuses(fn() => $store->overview(), RuntimeException::class);
    mkdir($root.'/deployment/releases/first', 0700, true);
    mkdir($root.'/deployment/releases/second', 0700);
    mkdir($root.'/deployment/shared', 0700);
    $firstRelease = new ConnectionStore($root.'/deployment/releases/first');
    $savedRelease = $firstRelease->save($input, 0, 8);
    $secondRelease = new ConnectionStore($root.'/deployment/releases/second');
    check($secondRelease->overview() === $savedRelease, 'Registry survives an atomic release switch');
    check(!file_exists($root.'/deployment/releases/inbox-mail-private'), 'Credentials are outside release retention');

    // Conversational email: optional IMAP settings and secret on SMTP connections only.
    mkdir($root.'/conversation', 0700);
    $conversation = new ConnectionStore($root.'/conversation');
    $conversationPrivate = $root.'/conversation-multimail-private';
    $imap = array_replace_recursive($input, ['conversational' => '1', 'settings' => ['imap_host' => 'imap.example.com', 'imap_port' => '993',
        'imap_username' => 'support@example.com', 'imap_folder' => '', 'imap_sent_folder' => 'Itens Enviados'], 'secrets' => ['imap_password' => 'unit-secret-imap']]);
    $view = $conversation->save($imap, 0, 8);
    $saved = $view['connections'][0];
    check($saved['conversational'] === true && $saved['plus_addressing'] === true && $saved['plus_addressing_checked_at'] === null, 'Conversational flag and +tag default');
    check($saved['settings']['imap_folder'] === 'INBOX' && $saved['settings']['imap_sent_folder'] === 'Itens Enviados' && $saved['settings']['imap_port'] === '993', 'IMAP defaults and values');
    check($saved['secret_configured']['imap_password'] === true && !str_contains(json_encode($view), 'unit-secret'), 'IMAP password stored as a secret, never public');
    $disk = json_decode(file_get_contents($conversationPrivate.'/connections.json'), true);
    check($disk['connections'][$saved['id']]['secrets']['imap_password'] === 'unit-secret-imap', 'IMAP password in the private file');
    $view = $conversation->save(array_replace_recursive($imap, ['id' => $saved['id'], 'secrets' => ['password' => '', 'imap_password' => '']]), 1, 8);
    $disk = json_decode(file_get_contents($conversationPrivate.'/connections.json'), true);
    check($disk['connections'][$saved['id']]['secrets']['imap_password'] === 'unit-secret-imap', 'Blank IMAP password keeps the saved one');
    $internal = $conversation->conversationalConnections();
    check(count($internal) === 1 && $internal[0]['secrets']['imap_password'] === 'unit-secret-imap', 'Poller reads conversational connections with secrets');
    foreach ([['settings' => ['imap_port' => '110']], ['settings' => ['imap_host' => 'bad host']], ['settings' => ['imap_folder' => "INBOX\r\nx"]],
        ['settings' => ['imap_folder' => 'INBOX*']], ['settings' => ['imap_host' => '']], ['secrets' => ['imap_password' => ['unit-secret']]], ['conversational' => 'yes']] as $change) {
        refuses(fn() => $conversation->save(array_replace_recursive($imap, ['id' => $saved['id']], $change), 2, 8));
    }
    refuses(fn() => $conversation->save(array_replace($input, ['provider' => 'resend', 'settings' => [], 'secrets' => ['api_key' => 're_unit-secret-abcdefghijklmnop'], 'conversational' => '1']), 2, 8));
    $plain = $conversation->save(array_replace_recursive($input, ['name' => 'Transport only', 'settings' => ['imap_host' => '']]), 2, 8);
    check($plain['connections'][1]['conversational'] === false && $plain['connections'][1]['settings']['imap_host'] === '', 'IMAP stays optional for a transport-only SMTP connection');
    check(count($conversation->conversationalConnections()) === 1, 'Only conversational connections are polled');
    $conversation->recordPlusAddressing($saved['id'], false);
    $after = $conversation->overview();
    check($after['revision'] === 3 && $after['connections'][0]['plus_addressing'] === false && is_string($after['connections'][0]['plus_addressing_checked_at']), 'Round-trip result recorded without a revision change');
    $after = $conversation->save(array_replace_recursive($imap, ['id' => $saved['id']]), 3, 8);
    check($after['connections'][0]['plus_addressing'] === false, 'Editing other fields keeps the round-trip result');
    $after = $conversation->save(array_replace_recursive($imap, ['id' => $saved['id'], 'reply_to' => 'contato@example.com']), 4, 8);
    check($after['connections'][0]['plus_addressing'] === true && $after['connections'][0]['plus_addressing_checked_at'] === null, 'A new account address needs a new round trip');
    check(ConnectionStore::accountAddress($after['connections'][0]) === 'contato@example.com' && ConnectionStore::accountAddress(['reply_to' => '', 'from_email' => 'Support@Example.com']) === 'support@example.com', 'Account address');
    // A registry written by 0.4.0 has none of the new keys.
    $legacy = json_decode(file_get_contents($conversationPrivate.'/connections.json'), true);
    foreach ($legacy['connections'] as &$record) {
        unset($record['conversational'], $record['plus_addressing'], $record['plus_addressing_checked_at']);
        foreach (array_keys(ConnectionStore::IMAP_SETTINGS) as $key) { unset($record['settings'][$key]); }
    }
    unset($record);
    file_put_contents($conversationPrivate.'/connections.json', json_encode($legacy));
    $upgraded = $conversation->overview()['connections'][0];
    check($upgraded['conversational'] === false && $upgraded['settings']['imap_folder'] === 'INBOX' && $upgraded['settings']['imap_port'] === '993' && $upgraded['plus_addressing'] === true, 'Legacy records receive defaults');
    check($conversation->conversationalConnections() === [], 'Legacy records are not conversational');
} finally {
    foreach (glob($root.'/conversation-multimail-private/*') ?: [] as $file) { unlink($file); }
    if (is_dir($root.'/conversation')) { rmdir($root.'/conversation-multimail-private'); rmdir($root.'/conversation'); }
    foreach (glob($private.'/*') ?: [] as $file) { unlink($file); }
    if (is_dir($root.'/deployment')) {
        foreach (glob($root.'/deployment/shared/inbox-mail-private/*') ?: [] as $file) { unlink($file); }
        rmdir($root.'/deployment/shared/inbox-mail-private'); rmdir($root.'/deployment/shared');
        rmdir($root.'/deployment/releases/first'); rmdir($root.'/deployment/releases/second');
        rmdir($root.'/deployment/releases'); rmdir($root.'/deployment');
    }
    foreach (glob($root.'/other-multimail-private/*') ?: [] as $file) { unlink($file); }
    rmdir($root.'/other-multimail-private'); rmdir($root.'/other');
    rmdir($private); rmdir($root.'/project'); rmdir($root);
}
echo "PASS: private multi-provider registry, secret preservation/redaction, no expiry, fallback chains/cycles/references, atomic rejection, revision conflicts, provider validation, filesystem guards and optional conversational IMAP settings/secret/defaults; no database or network\n";
