<?php

declare(strict_types=1);

/*
 * Full-stack end-to-end test of the conversational email channel, across every piece:
 * Mautic 7.2 bench over HTTPS (Inbox mobile API) -> Meta queue -> Multi Mail dispatcher -> GreenMail
 * SMTPS -> lead's mailbox; lead's reply -> GreenMail IMAPS -> Multi Mail poller -> Meta conversation
 * -> Inbox history; survey link -> tracked contact -> form submission -> Inbox timeline.
 *
 * Needs the bench (Tests/e2e/run.sh prepares it): MAUTIC_DIR with every plugin installed,
 * MAUTIC_URL served over trusted HTTPS, GreenMail on 465/993 with users contato@macro.test,
 * lead@example.test and the operator mailbox. Opt-in: E2E=1. It writes to the bench database.
 */

if (PHP_SAPI !== 'cli') { exit(1); }
if (getenv('E2E') !== '1') { echo "SKIP: full-stack e2e; run Tests/e2e/run.sh\n"; exit(0); }

$mauticDir = getenv('MAUTIC_DIR') ?: getenv('HOME').'/mautic-dev';
$baseUrl = rtrim(getenv('MAUTIC_URL') ?: 'https://localhost:8443', '/');
$operatorEmail = getenv('E2E_OPERATOR') ?: 'atendente.e2e@example.test';
chdir($mauticDir);
require $mauticDir.'/vendor/autoload.php';

use Doctrine\ORM\EntityManagerInterface;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Model\FormModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\UserBundle\Entity\Role;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use MauticPlugin\MauticMultiMailBundle\Conversation\ConversationBridgeInterface;
use MauticPlugin\MauticMultiMailBundle\Conversation\InboundParser;
use MauticPlugin\MauticMultiMailBundle\Conversation\OutboundEmailBuilder;
use MauticPlugin\MauticMultiMailBundle\Imap\ImapClient;
use MauticPlugin\MauticMultiMailBundle\Mailer\ConnectionBuilder;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

$steps = 0;
function step(string $title): void { global $steps; $steps++; printf("\n[%02d] %s\n", $steps, $title); }
function ok(bool $condition, string $what, mixed $context = null): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $what\n".($context === null ? '' : json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"));
        exit(1);
    }
    echo "  ok  $what\n";
}
function eventually(callable $probe, int $seconds = 30): mixed
{
    $until = microtime(true) + $seconds;
    do {
        $value = $probe();
        if ($value !== null && $value !== false) { return $value; }
        usleep(500000);
    } while (microtime(true) < $until);
    return null;
}
function console(string ...$args): string
{
    global $mauticDir;
    $command = 'cd '.escapeshellarg($mauticDir).' && php -d memory_limit=1G bin/console '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1';
    exec($command, $output, $code);
    $text = implode("\n", $output);
    ok($code === 0, 'console '.implode(' ', $args), $text);
    return $text;
}

final class Http
{
    private string $jar;
    public ?string $bearer = null;
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'e2e-cookies'); }
    /** @return array{status:int, headers:array<string,string>, body:string, json:mixed} */
    public function request(string $method, string $path, array|string|null $body = null, bool $json = true, array $headers = []): array
    {
        $curl = curl_init(str_starts_with($path, 'http') ? $path : $this->base.$path);
        $sent = $headers;
        if ($this->bearer !== null && str_contains($path, '/inbox/mobile/api/')) { $sent[] = 'Authorization: Bearer '.$this->bearer; }
        if ($body !== null) {
            if ($json) { $body = json_encode($body, JSON_THROW_ON_ERROR); $sent[] = 'Content-Type: application/json'; }
            elseif (is_array($body)) { $body = http_build_query($body); $sent[] = 'Content-Type: application/x-www-form-urlencoded'; }
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $sent,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
            // Real TLS verification against the bench CA in the system store: no insecure switch.
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) { [$name, $value] = explode(':', $line, 2); $responseHeaders[strtolower(trim($name))] = trim($value); }
                return strlen($line);
            },
        ]);
        $raw = curl_exec($curl);
        if ($raw === false) { throw new RuntimeException('HTTP '.$method.' '.$path.': '.curl_error($curl)); }
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $raw, 'json' => json_decode($raw, true)];
    }
}

function mailbox(string $user): ImapClient
{
    $client = ImapClient::open('127.0.0.1', 993);
    $client->login($user, 'secret');
    return $client;
}
/** Waits for a message matching $match in $user's INBOX and returns its raw source. */
function waitForMail(string $user, callable $match, int $seconds = 30): ?string
{
    return eventually(function () use ($user, $match): ?string {
        $client = mailbox($user);
        $client->examine('INBOX');
        $found = null;
        $uids = $client->uidsAfter(0);
        if ($uids !== []) {
            foreach (array_reverse($client->fetch($uids, 'body'), true) as $item) {
                if ($match($item['raw'])) { $found = $item['raw']; break; }
            }
        }
        $client->logout();
        return $found;
    }, $seconds);
}
function historyOf(Http $http, int $id): array
{
    $response = $http->request('GET', '/inbox/mobile/api/conversations/'.$id.'/history?limit=40');
    ok($response['status'] === 200, 'history '.$id.' loads', $response['body']);
    $data = $response['json'];
    return $data['items'] ?? $data['data'] ?? $data;
}

$run = bin2hex(random_bytes(3));
echo "Full-stack email e2e, run $run against $baseUrl\n";

// ---------------------------------------------------------------- setup through the kernel
step('Bench setup: operator, lead, survey form and a conversational SMTP+IMAP connection');
$kernel = new AppKernel('prod', false);
$kernel->boot();
$container = $kernel->getContainer();
/** @var EntityManagerInterface $em */
$em = $container->get('doctrine.orm.entity_manager');

$operator = $em->getRepository(User::class)->findOneBy(['email' => $operatorEmail]);
if (!$operator instanceof User) {
    $role = $em->getRepository(Role::class)->findOneBy(['isAdmin' => true]);
    $operator = (new User())->setFirstName('Ana')->setLastName('Atendente')->setUsername(strstr($operatorEmail, '@', true))->setEmail($operatorEmail)->setRole($role);
    $operator->setPassword(password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT));
    $operator->setIsPublished(true);
    $em->persist($operator);
    $em->flush();
}
ok($operator->getId() > 0, 'operator '.$operatorEmail);

$leadEmail = 'lead@example.test';
foreach ($em->getRepository(Lead::class)->findBy(['email' => $leadEmail]) as $old) { $em->remove($old); }
$em->flush();
$lead = (new Lead())->setFirstname('João')->setLastname('Ribeiro')->setEmail($leadEmail);
$em->persist($lead);
$em->flush();
ok($lead->getId() > 0, 'lead João <'.$leadEmail.'> #'.$lead->getId());

/** @var FormModel $forms */
$forms = $container->get('mautic.form.model.form');
$form = (new Form())->setName('Pesquisa: por que não depositou '.$run)->setAlias('pesquisa'.$run)->setFormType('standalone')->setIsPublished(true);
$reason = (new Field())->setLabel('Principal motivo')->setAlias('motivo')->setType('text')->setOrder(1)->setForm($form);
$submit = (new Field())->setLabel('Enviar')->setAlias('enviar')->setType('button')->setOrder(2)->setForm($form);
$form->addField('motivo', $reason);
$form->addField('enviar', $submit);
$forms->saveEntity($form);
ok($form->getId() > 0, 'published form #'.$form->getId());

/** @var ConnectionStore $store */
$store = $container->get(ConnectionStore::class);
$existing = array_values(array_filter($store->overview()['connections'], static fn(array $c): bool => $c['from_email'] === 'contato@macro.test'));
$revision = $store->overview()['revision'];
foreach ($existing as $old) { $revision = $store->remove($old['id'], $revision, (int) $operator->getId())['revision']; }
$view = $store->save([
    'provider' => 'smtp', 'name' => 'Macro Markets e2e', 'from_email' => 'contato@macro.test', 'from_name' => 'Macro Markets', 'reply_to' => '', 'fallback' => '',
    'conversational' => '1', 'pool_enabled' => '0',
    'settings' => ['host' => 'localhost', 'port' => '465', 'encryption' => 'ssl', 'username' => 'contato@macro.test',
        'imap_host' => 'localhost', 'imap_port' => '993', 'imap_folder' => 'INBOX', 'imap_username' => 'contato@macro.test'],
    'secrets' => ['password' => 'secret', 'imap_password' => 'secret'],
], $revision, (int) $operator->getId());
$connection = array_values(array_filter($view['connections'], static fn(array $c): bool => $c['from_email'] === 'contato@macro.test'))[0];
$container->get(ConversationBridgeInterface::class)->synchronize($store->conversationalConnections());
$asset = $em->getRepository(MetaAsset::class)->findOneBy(['externalId' => $connection['id']]);
ok($asset instanceof MetaAsset && $asset->getStatus() === 'active' && $asset->getType()->value === 'email_account', 'email_account asset #'.$asset?->getId().' for connection '.$connection['id']);
ok(($asset->getSettings()['email_address'] ?? null) === 'contato@macro.test', 'asset settings carry the account address', $asset->getSettings());
$leadId = (int) $lead->getId();
$assetId = (int) $asset->getId();
$formId = (int) $form->getId();
$kernel->shutdown();

step('IMAP baseline: the first poll pass starts at UIDNEXT');
echo '  '.str_replace("\n", "\n  ", trim(console('mautic:multimail:imap:poll', '--connection='.$connection['id'])))."\n";

// ---------------------------------------------------------------- mobile login, as the app does it
step('Mobile login by magic code (code delivered by GreenMail)');
$http = new Http($baseUrl);
$config = $http->request('GET', '/inbox/mobile/config');
ok($config['status'] === 200 && in_array('email', $config['json']['capabilities']['channels'] ?? [], true), 'discovery advertises the email channel', $config['json']);
$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$started = microtime(true);
$magic = $http->request('POST', '/inbox/mobile/magic-code', ['email' => $operatorEmail, 'code_challenge' => $challenge]);
if (($magic['json']['error'] ?? null) === 'rate_limited') {
    // One code per address per minute: a run right after another waits it out once.
    echo "  ..  rate limited by the previous run, waiting 61 s\n";
    sleep(61);
    $magic = $http->request('POST', '/inbox/mobile/magic-code', ['email' => $operatorEmail, 'code_challenge' => $challenge]);
}
ok($magic['status'] === 202 && is_string($magic['json']['request_id'] ?? null), 'magic code requested', $magic['json']);
// The body may travel as base64 or quoted-printable: read it through the parser, never the raw MIME.
$codeText = static fn(string $raw): string => (string) (new InboundParser())->parse($raw)->text;
$codeMail = waitForMail($operatorEmail, static fn(string $raw): bool => preg_match('/Código de acesso: \d{6}/u', $codeText($raw)) === 1, 40);
ok($codeMail !== null, 'access code e-mail arrived in GreenMail');
preg_match('/Código de acesso: (\d{6})/u', $codeText($codeMail), $code);
$token = $http->request('POST', '/inbox/mobile/token', ['grant_type' => 'email_code', 'request_id' => $magic['json']['request_id'], 'code' => $code[1], 'code_verifier' => $verifier]);
ok($token['status'] === 200 && is_string($token['json']['access_token'] ?? null), 'token exchanged', $token['json']);
$http->bearer = $token['json']['access_token'];

// ---------------------------------------------------------------- start the conversation from the contact
step('Contact sheet offers the email account; start the conversation');
$contact = $http->request('GET', '/inbox/mobile/api/contacts/'.$leadId);
ok($contact['status'] === 200, 'contact detail', $contact['body']);
$options = array_values(array_filter($contact['json']['channels'] ?? [], static fn(array $o): bool => $o['key'] === 'email:'.$assetId));
ok(count($options) === 1 && $options[0]['available'] === true && $options[0]['channel'] === 'email' && ($options[0]['address'] ?? null) === 'contato@macro.test', 'email option available', $contact['json']['channels'] ?? null);
$start = $http->request('POST', '/inbox/mobile/api/contacts/'.$leadId.'/start', ['channel_key' => 'email:'.$assetId]);
ok(in_array($start['status'], [200, 201], true) && ($start['json']['channel'] ?? null) === 'email', 'conversation started', $start['json']);
$conversationId = (int) $start['json']['id'];
ok(($start['json']['recipient'] ?? null) === $leadEmail, 'recipient is the lead address in lower case');

step('Forms are listed for the composer');
$formList = $http->request('GET', '/inbox/mobile/api/forms');
$listed = $formList['json']['items'] ?? $formList['json'];
ok($formList['status'] === 200 && in_array($formId, array_map(static fn(array $f): int => (int) $f['id'], $listed), true), 'survey form is offered', $formList['json']);

// ---------------------------------------------------------------- first email out
step('Reply with subject and survey marker; the email leaves through GreenMail SMTPS');
$subject = 'Por que você ainda não depositou? '.$run;
$requestId = 'e2e'.bin2hex(random_bytes(8));
$reply = $http->request('POST', '/inbox/mobile/api/conversations/'.$conversationId.'/reply', [
    'body' => "Oi, **João**! Vi que você abriu sua conta e ainda não depositou. Ficou alguma dúvida?\n\nSe puder, responda esta pesquisa rápida: [[form:$formId]]",
    'request_id' => $requestId, 'subject' => $subject, 'reply_mode' => 'private',
]);
ok(in_array($reply['status'], [200, 202], true), 'reply accepted', $reply['json']);
$outbound = waitForMail($leadEmail, static fn(string $raw): bool => str_contains(iconv_mime_decode_headers($raw, 0, 'UTF-8')['Subject'] ?? '', $run), 10);
if ($outbound === null) {
    // Not dispatched in the request: the queue worker is the safety net.
    console('mautic:meta:queue:process', '--limit=20');
    $outbound = waitForMail($leadEmail, static fn(string $raw): bool => str_contains(iconv_mime_decode_headers($raw, 0, 'UTF-8')['Subject'] ?? '', $run), 30);
}
ok($outbound !== null, 'the lead received the email');
$sent = (new InboundParser())->parse($outbound);
$headers = iconv_mime_decode_headers($outbound, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
ok($sent->subject === $subject, 'subject as typed');
ok(preg_match('/^contato\+r[a-z2-7]{24}@macro\.test$/', strtolower(trim($headers['Reply-To'] ?? '', '<> '))) === 1 || preg_match('/<?(contato\+r[a-z2-7]{24}@macro\.test)>?/i', $headers['Reply-To'] ?? '') === 1, 'Reply-To carries the signed +tag', $headers['Reply-To'] ?? null);
preg_match('/(contato\+r[a-z2-7]{24}@macro\.test)/i', $headers['Reply-To'] ?? '', $tagMatch);
$replyTo = $tagMatch[1];
ok(str_contains($headers['From'] ?? '', 'Ana') && str_contains($headers['From'] ?? '', 'contato@macro.test'), 'From is human: the operator with the account address', $headers['From'] ?? null);
ok(str_starts_with(trim($sent->text), OutboundEmailBuilder::SENTINEL), 'reply-above sentinel at the top');
ok(!str_contains($outbound, '[[form:'), 'survey marker expanded');
ok(preg_match('#https://localhost:8443/inbox/f/([A-Za-z0-9_.-]+)#', $sent->text.' '.$sent->html, $formLink) === 1, 'per-contact survey link in the email');
$messageId = $sent->messageId;
ok(is_string($messageId) && str_ends_with($messageId, '@macro.test>'), 'explicit Message-ID '.$messageId);

$history = historyOf($http, $conversationId);
$outItems = array_values(array_filter($history, static fn(array $i): bool => ($i['direction'] ?? null) === 'outbound' || ($i['kind'] ?? null) === 'outbound'));
ok(count($outItems) === 1, 'exactly one outbound item in the thread (no duplicate)', $history);
ok(($outItems[0]['subject'] ?? null) === $subject, 'outbound item keeps the subject', $outItems[0]);

// ---------------------------------------------------------------- the survey link
step('Survey link identifies the contact and records the opening');
// The timeline orders by the second; let people-paced actions land in distinct seconds.
sleep(2);
$open = $http->request('GET', $formLink[0]);
ok($open['status'] === 302 && str_contains($open['headers']['location'] ?? '', '/form/'), 'link redirects to the public form', $open['headers']);
$page = $http->request('GET', $open['headers']['location']);
ok($page['status'] === 200 && str_contains($page['body'], 'mauticform'), 'public form renders');
preg_match('/name="mauticform\[formName\]" value="([^"]+)"/', $page['body'], $formName);
preg_match('/<form[^>]+action="([^"]+)"/', $page['body'], $action);
$submitted = $http->request('POST', html_entity_decode($action[1] ?? '/form/submit?formId='.$formId), [
    'mauticform' => ['motivo' => 'Ainda comparando corretoras', 'formId' => $formId, 'return' => '', 'formName' => $formName[1] ?? '', 'messenger' => 1],
], false, ['X-Requested-With: XMLHttpRequest']);
ok(in_array($submitted['status'], [200, 302], true), 'form submitted', substr($submitted['body'], 0, 400));
$history = historyOf($http, $conversationId);
$events = array_column(array_filter($history, static fn(array $i): bool => in_array($i['event_type'] ?? $i['type'] ?? null, ['form_opened', 'form_submitted'], true)), 'event_type');
$flat = json_encode($history, JSON_UNESCAPED_UNICODE);
ok(str_contains($flat, 'form_opened'), 'form_opened event in the thread', $history);
ok(str_contains($flat, 'form_submitted') && str_contains($flat, 'Ainda comparando corretoras'), 'form_submitted event with the answer', $history);

// ---------------------------------------------------------------- the lead answers
step('The lead replies to the +tag address, quoting the email, with an attachment');
sleep(2);
$leadSmtp = (new ConnectionBuilder())->build(['provider' => 'smtp', 'settings' => ['host' => 'localhost', 'port' => '465', 'encryption' => 'ssl', 'username' => $leadEmail], 'secrets' => ['password' => 'secret']]);
$leadReply = (new Email())->from(new Address($leadEmail, 'João Ribeiro'))->to(new Address($replyTo, 'Macro Markets'))->subject('Re: '.$subject)
    ->text("Oi! Ainda estou decidindo. Qual é o depósito mínimo? Aceitam Pix?\n\nEm 24/10/2026, Ana · Macro Markets <$replyTo> escreveu:\n> ".OutboundEmailBuilder::SENTINEL."\n>\n> Oi, João! Vi que você abriu sua conta.")
    ->html('<div dir="ltr">Oi! Ainda estou decidindo. <b>Qual é o depósito mínimo?</b> Aceitam Pix?</div><div class="gmail_quote"><div>Em 24/10/2026, Ana escreveu:</div><blockquote class="gmail_quote">'.OutboundEmailBuilder::SENTINEL.'<br>Oi, João!</blockquote></div>')
    ->addPart(new DataPart("%PDF-1.4\n% e2e\n", 'extrato.pdf', 'application/pdf'));
$leadReply->getHeaders()->addIdHeader('Message-ID', 'lead-'.$run.'@example.test');
$leadReply->getHeaders()->addIdHeader('In-Reply-To', trim($messageId, '<>'));
$leadReply->getHeaders()->addIdHeader('References', [trim($messageId, '<>')]);
$leadReply->getHeaders()->addTextHeader('Delivered-To', $replyTo);
// GreenMail has no +tags: play the MTA that delivers contato+tag@ into contato@.
$leadSmtp->transport->send($leadReply, new Envelope(new Address($leadEmail), [new Address('contato@macro.test')]));
$leadSmtp->close();

step('Poll the mailbox; the reply lands in the same conversation, quote cut');
$inbound = eventually(function () use ($http, $conversationId, $connection): ?array {
    console('mautic:multimail:imap:poll', '--connection='.$connection['id']);
    foreach (historyOf($http, $conversationId) as $item) {
        if (($item['direction'] ?? null) === 'inbound' && str_contains((string) ($item['body'] ?? ''), 'depósito mínimo')) { return $item; }
    }
    return null;
}, 40);
ok($inbound !== null, 'inbound reply in the thread');
ok(!str_contains($inbound['body'], 'Responda acima') && !str_contains($inbound['body'], 'Vi que você abriu'), 'quoted text cut from the body', $inbound['body']);
ok(str_contains((string) ($inbound['email']['quoted'] ?? ''), 'Vi que você abriu'), 'quoted text kept apart for the "..." toggle', $inbound['email'] ?? null);
ok(($inbound['subject'] ?? null) === 'Re: '.$subject, 'reply subject', $inbound['subject'] ?? null);
ok(($inbound['email']['from']['address'] ?? null) === $leadEmail, 'sender', $inbound['email']['from'] ?? null);
ok(!str_contains(json_encode($inbound, JSON_UNESCAPED_UNICODE), '+r'), 'the +tag token never reaches the interface');
$attachments = $inbound['attachments'] ?? [];
ok(count($attachments) === 1 && ($attachments[0]['label'] ?? '') === 'extrato.pdf', 'attachment listed', $attachments);
ok(($attachments[0]['available'] ?? null) === false, 'without an antivirus configured the attachment stays unavailable', $attachments[0]);

$list = $http->request('GET', '/inbox/mobile/api/conversations?kind=private&queue=all&lifecycle=all&limit=50');
$summary = array_values(array_filter($list['json']['items'] ?? $list['json']['data'] ?? $list['json'], static fn(array $c): bool => (int) $c['id'] === $conversationId))[0] ?? null;
ok($summary !== null && $summary['channel'] === 'email' && ($summary['subject'] ?? null) === 'Re: '.$subject && (int) ($summary['unread'] ?? 0) >= 1, 'list row: email, subject, unread', $summary);
ok(($summary['email']['inbound'] ?? null) === 'ok', 'mailbox reading is healthy', $summary['email'] ?? null);

step('Original HTML through a short-lived signed link, sandboxed');
$link = $http->request('POST', '/inbox/mobile/api/messages/'.$inbound['message_id_ref'].'/original-link');
if ($link['status'] === 404 || !isset($link['json']['url'])) {
    $id = (int) preg_replace('/\D/', '', (string) ($inbound['id'] ?? ''));
    $link = $http->request('POST', '/inbox/mobile/api/messages/'.$id.'/original-link');
}
ok($link['status'] === 200 && str_starts_with($link['json']['url'] ?? '', $baseUrl.'/inbox/mobile/original/'), 'signed link issued', $link['json']);
$original = (new Http($baseUrl))->request('GET', $link['json']['url']);
ok($original['status'] === 200 && str_contains($original['body'], 'depósito mínimo'), 'original HTML served without a session', substr($original['body'], 0, 300));
ok(str_contains($original['headers']['content-security-policy'] ?? '', 'sandbox'), 'served with CSP sandbox', $original['headers']);

// ---------------------------------------------------------------- second email keeps the thread
step('Second reply threads with In-Reply-To the lead message');
sleep(2);
$reply2 = $http->request('POST', '/inbox/mobile/api/conversations/'.$conversationId.'/reply', [
    'body' => 'O depósito mínimo é R$ 100 e aceitamos Pix, sim. Posso te ajudar com o primeiro?', 'request_id' => 'e2e'.bin2hex(random_bytes(8)), 'reply_mode' => 'private',
]);
ok(in_array($reply2['status'], [200, 202], true), 'second reply accepted (subject defaults to Re:)', $reply2['json']);
$second = waitForMail($leadEmail, static fn(string $raw): bool => str_contains($raw, 'aceitamos Pix'), 10);
if ($second === null) { console('mautic:meta:queue:process', '--limit=20'); $second = waitForMail($leadEmail, static fn(string $raw): bool => str_contains($raw, 'aceitamos Pix'), 30); }
ok($second !== null, 'second email delivered');
$secondHeaders = iconv_mime_decode_headers($second, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
ok(str_contains($secondHeaders['In-Reply-To'] ?? '', 'lead-'.$run.'@example.test'), 'In-Reply-To points at the lead message', $secondHeaders['In-Reply-To'] ?? null);
ok(str_contains($secondHeaders['References'] ?? '', trim($messageId, '<>')), 'References keep the chain', $secondHeaders['References'] ?? null);
// RFC 2047 decoding through the real parser: iconv joins folded encoded-words and drops spaces.
preg_match('/^Subject:.*(?:\r?\n[ \t].*)*/mi', $second, $rawSubject);
ok((new InboundParser())->parse($second)->subject === 'Re: '.$subject, 'subject continues the thread', $rawSubject[0] ?? null);

$history = historyOf($http, $conversationId);
$outCount = count(array_filter($history, static fn(array $i): bool => ($i['direction'] ?? null) === 'outbound' || ($i['kind'] ?? null) === 'outbound'));
ok($outCount === 2, 'two outbound items, still no duplicates', $history);

if (($summaryFile = getenv('E2E_SUMMARY')) !== false && $summaryFile !== '') {
    // The live app e2e (mautic-inbox-mobile e2e/flows/email-live) continues from this thread.
    file_put_contents($summaryFile, json_encode([
        'run' => $run, 'base_url' => $baseUrl, 'operator' => $operatorEmail, 'conversation_id' => $conversationId,
        'subject' => 'Re: '.$subject, 'lead' => $leadEmail, 'lead_message_id' => 'lead-'.$run.'@example.test',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

echo "\nPASS: full-stack email e2e ($steps steps): magic-code login, email option and start, survey link, SMTPS send with signed +tag, Message-ID and sentinel, form opening and submission in the thread, lead reply threaded over IMAPS with quote cut and attachment, list row, sandboxed original, In-Reply-To/References on the next reply, no duplicate items\n";
