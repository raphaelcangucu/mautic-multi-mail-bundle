<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Simulated SMTP delivering into an in-memory IMAP mailbox; temporary private directory. No network.
require __DIR__.'/bootstrap.php';

use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use MauticPlugin\MauticMultiMailBundle\Conversation\{ConversationalSender, InboundParser, RoundTripTester};
use MauticPlugin\MauticMultiMailBundle\Imap\{ImapClient, ImapConnectorInterface};
use MauticPlugin\MauticMultiMailBundle\Mailer\ConnectionBuilder;
use MauticPlugin\MauticMultiMailBundle\Tests\Fixtures\{FakeImapServer, SimulatedSmtpServer};

function trip(bool $ok, string $reason): void { if (!$ok) { throw new RuntimeException($reason); } }

$root = sys_get_temp_dir().'/multimail_roundtrip_test_'.bin2hex(random_bytes(8));
mkdir($root.'/project', 0700, true);
try {
    $store = new ConnectionStore($root.'/project');
    $input = ['provider' => 'smtp', 'name' => 'Contato', 'from_email' => 'contato@macro.test', 'from_name' => 'Macro', 'reply_to' => '', 'fallback' => '', 'conversational' => '1',
        'settings' => ['host' => 'smtp.macro.test', 'port' => '465', 'encryption' => 'ssl', 'username' => 'contato@macro.test', 'imap_host' => 'imap.macro.test'],
        'secrets' => ['password' => 'secret']];
    $view = $store->save($input, 0, 1);
    $id = $view['connections'][0]['id'];
    $imap = new FakeImapServer(['INBOX' => ['uidvalidity' => 1, 'messages' => []]]);
    $mta = 'deliver';
    $rcpt = "250 2.1.5 Ok\r\n";
    $builder = new ConnectionBuilder(null, function () use (&$mta, &$rcpt, $imap): SimulatedSmtpServer {
        $server = new SimulatedSmtpServer();
        $server->replies['rcpt'] = $rcpt;
        // The MTA of a +tag mailbox: deliver into the base INBOX with the tag in Delivered-To.
        $server->onDataEnd = function (string $data) use (&$mta, $imap): void {
            if ($mta !== 'deliver') { return; }
            preg_match('/^To: (\S+)\r$/m', $data, $to);
            $uid = count($imap->folders['INBOX']['messages']) + 1;
            $imap->folders['INBOX']['messages'][$uid] = ['raw' => 'Delivered-To: '.$to[1]."\r\n".$data, 'date' => gmdate('Y-m-d H:i:s')];
        };
        return $server;
    });
    $connector = new class($imap) implements ImapConnectorInterface {
        public string $password = 'secret';
        public function __construct(private FakeImapServer $server) {}
        public function connect(array $connection): ImapClient
        {
            $client = new ImapClient($this->server->reconnect(), 'imap.macro.test', false);
            $client->login('contato@macro.test', $this->password);
            return $client;
        }
    };
    $sleeps = [];
    $sleep = function (int $seconds) use (&$sleeps): void { $sleeps[] = $seconds; };
    $tester = new RoundTripTester($store, new ConversationalSender($store, $builder), $connector, new InboundParser());

    $result = $tester->run($id, 1, 60, $sleep);
    trip($result['status'] === 'arrived' && $result['plus_addressing'] === true && $result['seconds'] === 0, 'Arrived at once: '.json_encode($result));
    trip(preg_match('/^contato\+teste[a-f0-9]{8}@macro\.test$/D', $result['address']) === 1 && $result['delivered_to'] === $result['address'], 'Delivered to the +tag address');
    $saved = $store->overview()['connections'][0];
    trip($saved['plus_addressing'] === true && is_string($saved['plus_addressing_checked_at']) && $store->overview()['revision'] === 1, '+tag support recorded without a revision change');
    trip(in_array('UID SEARCH HEADER Message-ID "'.trim(preg_match('/^Message-ID: (\S+)\r$/m', end($imap->folders['INBOX']['messages'])['raw'], $m) ? $m[1] : '').'"', $imap->commands, true), 'Found by its Message-ID');

    $mta = 'drop';
    $result = $tester->run($id, 1, 9, $sleep);
    trip($result['status'] === 'not_arrived' && $result['plus_addressing'] === false && $sleeps === [3, 3, 3], 'Not arrived after the wait, polling every 3 s: '.json_encode($sleeps));
    trip($store->overview()['connections'][0]['plus_addressing'] === false, 'No +tag recorded');

    $rcpt = "550 5.1.1 <contato+teste@macro.test>: Recipient address rejected\r\n";
    $result = $tester->run($id, 1, 0, $sleep);
    trip($result['status'] === 'rejected' && $result['plus_addressing'] === false && str_contains($result['reason'], '550 5.1.1'), 'Refused +tag recipient');

    $store->recordPlusAddressing($id, true);
    $rcpt = "451 4.3.0 Try again later\r\n";
    $result = $tester->run($id, 1, 0, $sleep);
    trip($result['status'] === 'send_failed' && $result['plus_addressing'] === null && $store->overview()['connections'][0]['plus_addressing'] === true, 'Temporary SMTP failure changes nothing');

    $rcpt = "250 2.1.5 Ok\r\n";
    $mta = 'deliver';
    $connector->password = 'wrong';
    $result = $tester->run($id, 1, 0, $sleep);
    trip($result['status'] === 'imap_failed' && $result['plus_addressing'] === null && !str_contains(json_encode($result), 'secret'), 'IMAP failure reported without credentials');

    try { $tester->run($id, 0, 0, $sleep); throw new LogicException('Stale revision accepted'); } catch (DomainException) {}
    $store->save(array_replace_recursive($input, ['id' => $id, 'conversational' => '0', 'settings' => ['imap_host' => '']]), 1, 1);
    try { $tester->run($id, 2, 0, $sleep); throw new LogicException('Round trip without IMAP accepted'); } catch (InvalidArgumentException $error) { trip($error->getMessage() === 'mautic.multimail.roundtrip.not_configured', 'Translated refusal'); }
} finally {
    foreach (glob($root.'/project-multimail-private/*') ?: [] as $file) { unlink($file); }
    if (is_dir($root.'/project-multimail-private')) { rmdir($root.'/project-multimail-private'); }
    rmdir($root.'/project'); rmdir($root);
}
echo "PASS: round trip to local+teste<random>@, arrival by Message-ID, delivered address, +tag recorded true/false, 3-second polling until the deadline, refused tag, temporary SMTP failure, IMAP failure, stale revision and missing IMAP; no network\n";
