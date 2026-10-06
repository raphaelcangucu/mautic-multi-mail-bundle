<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Temporary private files and mock HTTP only; no Mautic kernel, database or network.
require __DIR__.'/bootstrap.php';

use MauticPlugin\MauticMultiMailBundle\Application\{ConnectionStore, ConnectionTester};
use MauticPlugin\MauticMultiMailBundle\Mailer\{ConnectionBuilder, MultiMailTransportFactory};
use Symfony\Component\HttpClient\{MockHttpClient, Response\MockResponse};
use Symfony\Component\Mailer\{Envelope, Exception\TransportException, Transport\Dsn};
use Symfony\Component\Mime\{Address, Email};
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Event\{MessageEvent, SentMessageEvent};

function checkProvider(bool $condition, string $reason): void { if (!$condition) { throw new RuntimeException($reason); } }

function providerSuccess(string $provider, array $payload): MockResponse
{
    $id = 'accepted-123';
    $body = match ($provider) {
        'mailjet' => ['Messages' => [array_merge(['Status' => 'success'], array_combine(['To', 'Cc', 'Bcc'], array_map(
            static fn ($category) => array_map(static fn ($recipient) => ['Email' => $recipient['Email'], 'MessageID' => 123456], $payload['Messages'][0][$category] ?? []), ['To', 'Cc', 'Bcc']))) ]],
        'mailersend' => null,
        'mandrill' => array_map(static fn ($recipient) => ['email' => $recipient['email'], 'status' => 'sent', '_id' => $id], $payload['message']['to']),
        'sparkpost' => ['results' => ['total_accepted_recipients' => count($payload['recipients']), 'total_rejected_recipients' => 0, 'id' => $id]],
        'smtp2go' => ['data' => ['succeeded' => count($payload['to']) + count($payload['cc'] ?? []) + count($payload['bcc'] ?? []), 'failed' => 0, 'failures' => [], 'email_id' => $id]],
    };
    return new MockResponse($body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR), ['http_code' => $provider === 'mailersend' ? 202 : 200,
        'response_headers' => $provider === 'mailersend' ? ['x-message-id: '.$id] : []]);
}

$root = sys_get_temp_dir().'/multimail_providers_unit_'.bin2hex(random_bytes(8));
mkdir($root.'/project', 0700, true);
$store = new ConnectionStore($root.'/project');
$base = ['name' => 'Unit provider', 'from_email' => 'configured@example.com', 'from_name' => 'Configured sender', 'reply_to' => 'reply@example.com', 'fallback' => ''];
$providers = ['mailjet', 'mailersend', 'mandrill', 'sparkpost', 'smtp2go'];
$revision = 0;
$endpoints = ['mailjet' => 'https://api.mailjet.com/v3.1/send', 'mailersend' => 'https://api.mailersend.com/v1/email',
    'mandrill' => 'https://mandrillapp.com/api/1.0/messages/send.json', 'sparkpost' => 'https://api.eu.sparkpost.com/api/v1/transmissions', 'smtp2go' => 'https://api.smtp2go.com/v3/email/send'];
try {
    foreach ($providers as $provider) {
        $secrets = array_fill_keys(ConnectionStore::PROVIDERS[$provider]['secrets'], 'unit-secret-'.$provider);
        $input = array_merge($base, ['provider' => $provider, 'settings' => $provider === 'sparkpost' ? ['region' => 'eu'] : [], 'secrets' => $secrets]);
        $view = $store->save($input, $revision++, 1);
        $reserve = end($view['connections'])['id'];
        $view = $store->save(array_replace($input, ['name' => 'Unit primary', 'fallback' => $reserve]), $revision++, 1);
        $primary = end($view['connections'])['id'];
        checkProvider(!str_contains(json_encode($view), 'unit-secret'), 'Public registry does not expose new credentials');
        $store->save(array_replace($input, ['id' => $primary, 'secrets' => array_fill_keys(array_keys($secrets), '')]), $revision++, 1);
        checkProvider($store->transportChain($primary)[0]['secrets'] === $secrets, 'Blank edits preserve every provider credential');
        // Restore the reserve after the edit; a save still uses explicit, visible fallback choice.
        $store->save(array_replace($input, ['id' => $primary, 'fallback' => $reserve]), $revision++, 1);

        $text = fopen('php://temp', 'w+'); fwrite($text, 'Plain report');
        $html = fopen('php://temp', 'w+'); fwrite($html, '<b>HTML report</b><img src="cid:logo.png">');
        $attachment = fopen('php://temp', 'w+'); fwrite($attachment, 'report contents');
        $email = (new Email())->from('Mautic <mautic@example.com>')->to('visible@example.com')
            ->cc('cc@example.com', 'excluded@example.com')->bcc('bcc@example.com', 'excluded-bcc@example.com')
            ->replyTo('reply@example.com')->subject('Native report')->text($text)->html($html)
            ->attach($attachment, 'report.txt', 'text/plain')->embed('image-bytes', 'logo.png', 'image/png');
        $email->getHeaders()->addTextHeader('X-Mautic-Test', 'tracking-preserved');
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<https://example.com/unsubscribe>');
        $envelope = new Envelope(new Address('mautic@example.com', 'Mautic'), [new Address('to@example.com'), new Address('cc@example.com'), new Address('bcc@example.com')]);
        $calls = 0; $request = null; $auth = null;
        $client = new MockHttpClient(function ($method, $url, $options) use ($provider, &$calls, &$request, &$auth, $endpoints) {
            ++$calls; $request = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            $auth = $options['normalized_headers'];
            checkProvider($method === 'POST' && $url === $endpoints[$provider], 'Fixed official endpoint');
            checkProvider($options['max_redirects'] === 0, 'API credentials cannot follow redirects');
            return providerSuccess($provider, $request);
        });
        $events = new EventDispatcher(); $before = $after = 0;
        $events->addListener(MessageEvent::class, function () use (&$before) { ++$before; });
        $events->addListener(SentMessageEvent::class, function () use (&$after) { ++$after; });
        $transport = (new MultiMailTransportFactory($store, new ConnectionBuilder($client), $events))->create(new Dsn('multimail', $primary));
        $sent = $transport->send($email, $envelope);
        checkProvider($calls === 1 && $before === 1 && $after === 1, 'One request and native event pair');
        checkProvider($sent->getMessageId() === ($provider === 'mailjet' ? '123456' : 'accepted-123'), 'Actual provider identifier preserved');
        checkProvider(!str_contains((string) $transport, 'unit-secret'), 'Transport descriptions redact credentials');
        $serialized = json_encode($request);
        if ($provider !== 'sparkpost') {
            checkProvider(!str_contains($serialized, 'excluded') && !str_contains($serialized, 'visible@example.com'), 'API recipient categories obey the explicit envelope');
        }
        switch ($provider) {
            case 'mailjet':
                $body = $request['Messages'][0];
                checkProvider($body['From']['Email'] === 'mautic@example.com' && $body['ReplyTo']['Email'] === 'reply@example.com', 'Mailjet sender/reply-to');
                checkProvider($body['TextPart'] === 'Plain report' && str_contains($body['HTMLPart'], '<b>HTML report</b>'), 'Mailjet stream alternatives');
                checkProvider(base64_decode($body['Attachments'][0]['Base64Content']) === 'report contents', 'Mailjet attachment');
                checkProvider(str_contains($body['HTMLPart'], 'cid:'.$body['InlinedAttachments'][0]['ContentID']), 'Mailjet inline CID');
                checkProvider(isset($auth['authorization']) && str_contains(implode(' ', $auth['authorization']), base64_encode('unit-secret-mailjet:unit-secret-mailjet')), 'Mailjet key pair authentication');
                break;
            case 'mailersend':
                checkProvider($request['from']['email'] === 'mautic@example.com' && $request['reply_to']['email'] === 'reply@example.com', 'MailerSend sender/reply-to');
                checkProvider($request['text'] === 'Plain report' && str_contains($request['html'], '<b>HTML report</b>'), 'MailerSend stream alternatives');
                checkProvider(base64_decode($request['attachments'][0]['content']) === 'report contents', 'MailerSend stream attachment');
                checkProvider(str_contains($request['html'], 'cid:'.$request['attachments'][1]['id']), 'MailerSend inline CID');
                checkProvider(str_contains(implode(' ', $auth['authorization']), 'Bearer unit-secret-mailersend'), 'MailerSend bearer authentication');
                break;
            case 'mandrill':
                $body = $request['message'];
                checkProvider($body['from_email'] === 'mautic@example.com' && $body['headers']['Reply-To'] === 'reply@example.com', 'Mandrill sender/reply-to');
                checkProvider($body['text'] === 'Plain report' && str_contains($body['html'], '<b>HTML report</b>'), 'Mandrill stream alternatives');
                checkProvider(base64_decode($body['attachments'][0]['content']) === 'report contents', 'Mandrill attachment');
                checkProvider(str_contains($body['html'], 'cid:'.$body['images'][0]['name']), 'Mandrill inline CID');
                checkProvider($request['key'] === 'unit-secret-mandrill', 'Mandrill JSON authentication');
                break;
            case 'sparkpost':
                $mime = $request['content']['email_rfc822'];
                checkProvider(str_contains($mime, 'Reply-To: reply@example.com') && str_contains($mime, 'From: Mautic <mautic@example.com>') && !str_contains($mime, "\r\nBcc:"), 'SparkPost full MIME and Bcc privacy');
                checkProvider(str_contains($mime, 'List-Unsubscribe:') && str_contains($mime, 'tracking-preserved') && str_contains($mime, base64_encode('report contents')), 'SparkPost MIME headers/attachment');
                checkProvider($request['options'] === ['open_tracking' => false, 'click_tracking' => false], 'Mautic link tracking not rewritten');
                checkProvider(array_column(array_column($request['recipients'], 'address'), 'email') === ['to@example.com', 'cc@example.com', 'bcc@example.com'], 'SparkPost delivery envelope');
                checkProvider(str_contains(implode(' ', $auth['authorization']), 'unit-secret-sparkpost'), 'SparkPost API authorization');
                break;
            case 'smtp2go':
                checkProvider(str_contains($request['sender'], 'mautic@example.com') && $request['to'] === ['to@example.com'], 'SMTP2GO sender and envelope');
                checkProvider($request['fastaccept'] === false, 'SMTP2GO waits for individual acceptance counts');
                checkProvider($request['text_body'] === 'Plain report' && str_contains($request['html_body'], '<b>HTML report</b>'), 'SMTP2GO stream alternatives');
                checkProvider(base64_decode($request['attachments'][0]['fileblob']) === 'report contents' && $request['attachments'][0]['mimetype'] === 'text/plain', 'SMTP2GO encoded attachment and MIME');
                checkProvider(str_contains($request['html_body'], 'cid:'.$request['inlines'][0]['filename']), 'SMTP2GO inline CID');
                checkProvider(in_array(['header' => 'Reply-To', 'value' => 'reply@example.com'], $request['custom_headers'], true), 'SMTP2GO Reply-To custom header');
                checkProvider(in_array(['header' => 'List-Unsubscribe', 'value' => '<https://example.com/unsubscribe>'], $request['custom_headers'], true), 'SMTP2GO unsubscribe header');
                checkProvider(str_contains(implode(' ', $auth['x-smtp2go-api-key']), 'unit-secret-smtp2go'), 'SMTP2GO API authorization');
                break;
        }

        foreach ([401, 403, 429] as $refusal) {
            $calls = 0;
            $mock = new MockHttpClient(function ($method, $url, $options) use ($provider, $refusal, &$calls) {
                return ++$calls === 1 ? new MockResponse('{"message":"unit-secret"}', ['http_code' => $refusal]) : providerSuccess($provider, json_decode($options['body'], true));
            });
            (new MultiMailTransportFactory($store, new ConnectionBuilder($mock)))->create(new Dsn('multimail', $primary))->send($email, $envelope);
            checkProvider($calls === 2, 'Only confirmed API auth/permission/rate refusal invokes reserve');
        }
        foreach ([400, 408, 422, 500, 503, 'malformed', 'timeout'] as $failure) {
            $calls = 0;
            $mock = new MockHttpClient(function () use (&$calls, $failure, $provider) {
                ++$calls;
                if ($failure === 'timeout') { throw new Symfony\Component\HttpClient\Exception\TransportException('unit-secret timeout'); }
                return new MockResponse('not-json-unit-secret', ['http_code' => $failure === 'malformed' ? ($provider === 'mailersend' ? 202 : 200) : $failure]);
            });
            try {
                (new MultiMailTransportFactory($store, new ConnectionBuilder($mock)))->create(new Dsn('multimail', $primary))->send($email, $envelope);
                throw new RuntimeException('Unexpected successful malformed/uncertain send');
            } catch (TransportException $error) {
                checkProvider($calls === 1 && $error->getPrevious() === null && !str_contains($error->getMessage(), 'unit-secret'), 'Ambiguous handoff/payload rejection never duplicates or leaks credentials');
            }
        }
        // Test UI diagnostics with the same actual provider identifier, not a generated MIME ID.
        $mock = new MockHttpClient(static fn ($method, $url, $options) => providerSuccess($provider, json_decode($options['body'], true)));
        $diagnostic = (new ConnectionTester($store, new ConnectionBuilder($mock)))->send($primary, 'test@example.com', $revision);
        checkProvider($diagnostic['status'] === 'accepted' && isset($diagnostic['http_status'], $diagnostic['provider_message_id']), 'Diagnostic retains new provider ID and HTTP status');

        $refused = match ($provider) {
            'mailjet' => ['Messages' => [['Status' => 'error', 'Errors' => [['ErrorMessage' => 'unit-secret']]]]],
            'mailersend' => ['warnings' => [['type' => 'ALL_SUPPRESSED']]],
            'mandrill' => [['email' => 'test@example.com', 'status' => 'rejected', '_id' => 'unused']],
            'sparkpost' => ['results' => ['total_accepted_recipients' => 0, 'total_rejected_recipients' => 1, 'id' => 'unused']],
            'smtp2go' => ['data' => ['succeeded' => 0, 'failed' => 1, 'failures' => [['message' => 'unit-secret']]]],
        };
        $mock = new MockHttpClient([new MockResponse(json_encode($refused), ['http_code' => $provider === 'mailersend' ? 202 : 200])]);
        $diagnostic = (new ConnectionTester($store, new ConnectionBuilder($mock)))->send($primary, 'test@example.com', $revision);
        checkProvider($diagnostic['status'] === 'rejected' && !str_contains(json_encode($diagnostic), 'unit-secret'), 'HTTP success with business refusal is rejected safely');
        $invalidId = match ($provider) {
            'mailjet' => ['Messages' => [['Status' => 'success', 'To' => [['Email' => 'test@example.com', 'MessageID' => "unsafe\nID"]]]]],
            'mailersend' => [], // A queued response without x-message-id is insufficient.
            'mandrill' => [['email' => 'test@example.com', 'status' => 'sent', '_id' => "unsafe\nID"]],
            'sparkpost' => ['results' => ['total_accepted_recipients' => 1, 'total_rejected_recipients' => 0, 'id' => "unsafe\nID"]],
            'smtp2go' => ['data' => ['succeeded' => 1, 'failed' => 0, 'email_id' => "unsafe\nID"]],
        };
        $mock = new MockHttpClient([new MockResponse(json_encode($invalidId), ['http_code' => $provider === 'mailersend' ? 202 : 200])]);
        $diagnostic = (new ConnectionTester($store, new ConnectionBuilder($mock)))->send($primary, 'test@example.com', $revision);
        checkProvider($diagnostic['status'] === 'uncertain' && !isset($diagnostic['provider_message_id']), 'Missing or unsafe provider IDs never leak into diagnostics');
        foreach (["key\r\ninjection", str_repeat('x', 4097)] as $badKey) {
            try { $store->save(array_replace($input, ['secrets' => array_fill_keys(array_keys($secrets), $badKey)]), $revision, 1); throw new RuntimeException('Invalid credential accepted'); }
            catch (InvalidArgumentException) { checkProvider($store->overview()['revision'] === $revision, 'Invalid credentials do not modify storage'); }
        }
        $partial = match ($provider) {
            'mailjet' => ['Messages' => [['Status' => 'success', 'To' => [['Email' => 'to@example.com', 'MessageID' => 'partial']]]]],
            'mailersend' => ['warnings' => [['type' => 'SOME_SUPPRESSED']]],
            'mandrill' => [['email' => 'to@example.com', 'status' => 'sent', '_id' => 'partial'], ['email' => 'cc@example.com', 'status' => 'rejected'], ['email' => 'bcc@example.com', 'status' => 'invalid']],
            'sparkpost' => ['results' => ['total_accepted_recipients' => 1, 'total_rejected_recipients' => 2, 'id' => 'partial']],
            'smtp2go' => ['data' => ['succeeded' => 1, 'failed' => 2, 'failures' => [['message' => 'partial']]]],
        };
        $calls = 0;
        $mock = new MockHttpClient(function () use ($provider, $partial, &$calls) { ++$calls; return new MockResponse(json_encode($partial), ['http_code' => $provider === 'mailersend' ? 202 : ($provider === 'sparkpost' ? 422 : 200)]); });
        try { (new MultiMailTransportFactory($store, new ConnectionBuilder($mock)))->create(new Dsn('multimail', $primary))->send($email, $envelope); throw new RuntimeException('Partial acceptance was treated as success'); }
        catch (TransportException) { checkProvider($calls === 1, 'Partial acceptance never invokes a reserve'); }
        fclose($text); fclose($html); fclose($attachment);
    }
    // Region is a closed enum, never a hostname/path controlled by a submitted form.
    try {
        $store->save(array_merge($base, ['provider' => 'sparkpost', 'settings' => ['region' => 'https://evil.example'], 'secrets' => ['api_key' => 'unit-key']]), $revision, 1);
        throw new RuntimeException('Invalid region accepted');
    } catch (InvalidArgumentException) {}
    foreach (['sparkpost', 'smtp2go'] as $provider) {
        $calls = 0;
        $builder = new ConnectionBuilder(new MockHttpClient(function () use (&$calls) { ++$calls; throw new RuntimeException('Oversize message reached network'); }));
        $transport = $builder->build(['provider' => $provider, 'settings' => [], 'secrets' => ['api_key' => 'unit-key']])->transport;
        try { $transport->send((new Email())->from('from@example.com')->to('to@example.com')->text(str_repeat('x', 21 * 1024 * 1024))); throw new RuntimeException('Oversize accepted'); }
        catch (TransportException) { checkProvider($calls === 0, 'Oversize request stopped before handoff'); }
    }
    echo "New provider contracts: credentials, regions, envelopes, streams, MIME, attachments, inline CID, diagnostics, full/partial acceptance and fallback passed.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $path) { $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname()); }
    rmdir($root);
}
