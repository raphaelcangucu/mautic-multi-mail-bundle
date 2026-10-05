<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Standalone: mocked HTTP, simulated SMTP, temporary filesystem only.
require __DIR__.'/bootstrap.php';

use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use MauticPlugin\MauticMultiMailBundle\Mailer\{ConnectionBuilder, MultiMailTransportFactory, ConfirmedSmtpTransport};
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;
use Symfony\Component\Mime\Email;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Event\{MessageEvent, SentMessageEvent};

function verify(bool $ok, string $reason): void { if (!$ok) { throw new RuntimeException($reason); } }
final class SimulatedStream extends AbstractStream
{
    public array $writes = [];
    public function __construct(private array $responses) {}
    public function initialize(): void {}
    public function setHost(string $host): static { return $this; }
    public function setPort(int $port): static { return $this; }
    public function disableTls(): static { return $this; }
    public function isTLS(): bool { return true; }
    public function write(string $bytes, bool $debug = true): void { $this->writes[] = $bytes; }
    public function flush(): void {}
    public function readLine(): string {
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) { throw $response; }
        return $response ?? '';
    }
    protected function getReadConnectionDescription(): string { return 'simulated'; }
}

$root = sys_get_temp_dir().'/multimail_transport_unit_'.bin2hex(random_bytes(8));
mkdir($root.'/project', 0700, true);
$store = new ConnectionStore($root.'/project');
$input = ['provider' => 'resend', 'name' => 'Primary', 'from_email' => 'configured@example.com', 'from_name' => 'Configured',
    'reply_to' => '', 'fallback' => '', 'settings' => [], 'secrets' => ['api_key' => 're_unit-secret-abcdefghijklmnop']];
$email = (new Email())->from('Mautic <mautic@example.com>')->to('to@example.com')->cc('cc@example.com')->bcc('bcc@example.com')
    ->replyTo('reply@example.com')->subject('Tracked report')->text('Plain report')->html('<b>HTML report</b>')->attach('report contents', 'report.txt', 'text/plain');
$email->getHeaders()->addTextHeader('X-Mautic-Test', 'tracking-preserved');
try {
    $view = $store->save(array_replace($input, ['name' => 'Reserve']), 0, 1);
    $reserve = $view['connections'][0]['id'];
    $view = $store->save(array_replace($input, ['fallback' => $reserve]), 1, 1);
    $primary = $view['connections'][1]['id'];
    $bodies = [];
    $client = new MockHttpClient(function ($method, $url, $options) use (&$bodies) {
        verify($method === 'POST' && $url === 'https://api.resend.com/emails', 'Official Resend API endpoint');
        $bodies[] = json_decode($options['body'], true);
        return count($bodies) === 1 ? new MockResponse('{"message":"unit-secret rejected"}', ['http_code' => 401])
            : new MockResponse('{"id":"accepted-unit-id"}', ['http_code' => 200]);
    });
    $events = new EventDispatcher(); $before = $after = 0;
    $events->addListener(MessageEvent::class, function () use (&$before) { ++$before; });
    $events->addListener(SentMessageEvent::class, function () use (&$after) { ++$after; });
    $factory = new MultiMailTransportFactory($store, new ConnectionBuilder($client), $events);
    $transport = $factory->create(new Dsn('multimail', $primary));
    verify((string) $transport === 'multimail://'.$primary, 'Public transport description has no credentials');
    $sent = $transport->send($email);
    verify($sent->getMessageId() === 'accepted-unit-id' && count($bodies) === 2, 'Refusal falls back and returns accepted provider id');
    verify($before === 1 && $after === 1, 'One native event pair per logical send');
    verify($bodies[0] === $bodies[1], 'Fallback preserves identical email payload');
    $body = $bodies[1];
    verify(str_contains($body['from'], 'mautic@example.com'), 'Native Mautic sender preserved');
    verify(in_array('to@example.com', $body['to']) && in_array('cc@example.com', $body['cc']) && in_array('bcc@example.com', $body['bcc']), 'All recipient categories preserved');
    verify($body['text'] === 'Plain report' && $body['html'] === '<b>HTML report</b>', 'Both message alternatives preserved');
    verify($body['attachments'][0]['filename'] === 'report.txt' && base64_decode($body['attachments'][0]['content']) === 'report contents', 'Attachment preserved');
    verify($body['headers']['X-Mautic-Test'] === 'tracking-preserved', 'Tracking header preserved');
    foreach ([400, 408, 422, 500, 503, 200, 'timeout'] as $failure) {
        $calls = 0;
        $mock = new MockHttpClient(function () use (&$calls, $failure) {
            ++$calls;
            if ($failure === 'timeout') { throw new Symfony\Component\HttpClient\Exception\TransportException('unit-secret timeout'); }
            return new MockResponse('{"id":null,"message":"unit-secret invalid response"}', ['http_code' => $failure]);
        });
        try { (new MultiMailTransportFactory($store, new ConnectionBuilder($mock)))->create(new Dsn('multimail', $primary))->send($email); throw new RuntimeException('Expected error'); }
        catch (TransportException $error) { verify(!str_contains($error->getMessage(), 'unit-secret') && $error->getPrevious() === null, 'Provider credentials/debug not propagated'); }
        verify($calls === 1, 'Uncertain/invalid send is never duplicated');
    }
    foreach ([403, 429] as $status) {
        $mock = new MockHttpClient([new MockResponse('{}', ['http_code' => $status]), new MockResponse('{"id":"fallback"}', ['http_code' => 200])]);
        verify((new MultiMailTransportFactory($store, new ConnectionBuilder($mock)))->create(new Dsn('multimail', $primary))->send($email)->getMessageId() === 'fallback', 'Confirmed refusal fallback');
    }
    foreach ([['smtp', ['host' => 'smtp.example.com', 'port' => '587', 'encryption' => 'tls', 'username' => 'user'], ['password' => 'unit-secret']],
        ['ses', ['region' => 'sa-east-1'], ['access_key' => 'unit-secret-id', 'secret_key' => 'unit-secret']],
        ['mailgun', ['domain' => 'mg.example.com', 'region' => 'eu'], ['api_key' => 'unit-secret']],
        ['resend', [], ['api_key' => 'unit-secret']], ['sendgrid', [], ['api_key' => 'unit-secret']],
        ['postmark', [], ['api_key' => 'unit-secret']], ['brevo', [], ['api_key' => 'unit-secret']]] as [$provider, $settings, $secrets]) {
        $attempt = (new ConnectionBuilder(new MockHttpClient()))->build(compact('provider', 'settings', 'secrets'));
        verify($attempt->transport instanceof Symfony\Component\Mailer\Transport\TransportInterface, 'Official provider transport instantiated: '.$provider);
        $attempt->close();
    }
    foreach (['250 queued id\r\n' => false, '451 refused\r\n' => true, 'timeout' => false] as $final => $retry) {
        $response = $final === 'timeout' ? new TransportException('unit-secret timeout') : str_replace('\\r\\n', "\r\n", $final);
        $stream = new SimulatedStream(["220 hello\r\n", "250 hello\r\n", "250 sender\r\n", "250 to\r\n", "250 cc\r\n", "250 bcc\r\n", "354 body\r\n", $response, "221 goodbye\r\n"]);
        $smtp = new ConfirmedSmtpTransport('smtp.example.com', 465, true, stream: $stream);
        $smtp->setRequireTls(true);
        try { $smtp->send($email); } catch (TransportException) {}
        verify($smtp->confirmedNotAccepted() === $retry, 'SMTP acceptance boundary '.$final);
        try { $smtp->stop(); } catch (Throwable) {}
    }
    try { $factory->create(new Dsn('multimail', 'invalid')); throw new RuntimeException('Expected invalid DSN'); }
    catch (Symfony\Component\Mailer\Exception\InvalidArgumentException) {}
} finally {
    foreach (glob($root.'/project-multimail-private/*') ?: [] as $file) { unlink($file); }
    rmdir($root.'/project-multimail-private'); rmdir($root.'/project'); rmdir($root);
}
echo "PASS: native transport registration, official provider construction, message/envelope preservation, events, confirmed refusal fallback, timeout safety, SMTP acceptance boundaries and secret-safe errors; no database/network\n";
