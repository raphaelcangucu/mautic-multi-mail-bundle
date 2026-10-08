<?php

declare(strict_types=1);

// Pure CLI tests: synthetic connections, mocked HTTP, Symfony forms and no Mautic kernel/database.
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    require __DIR__.'/bootstrap.php';
}
// Minimal Mautic type contracts for the isolated form/subscriber test, not framework replacements.
namespace Mautic\CoreBundle\Helper {
    class UserHelper {
        public function __construct(public bool $admin = true) {}
        public function getUser(bool $force = false): object { return new class($this->admin) {
            public function __construct(private bool $admin) {}
            public function isAdmin(): bool { return $this->admin; }
            public function getEmail(): string { return 'operator@example.com'; }
        }; }
    }
    class CoreParametersHelper {
        public function get(string $name): string { return 'smtp://unit-user:unit-secret@example.com'; }
    }
}
namespace Mautic\CoreBundle\Controller {
    class CommonController {
        public bool $validCsrf = true;
        public array $viewArguments = [];
        protected function isCsrfTokenValid(string $id, mixed $token): bool { return $this->validCsrf; }
        protected function createAccessDeniedException(): \RuntimeException { return new \RuntimeException('denied'); }
        protected function createNotFoundException(string $message): \RuntimeException { return new \RuntimeException($message); }
        protected function generateUrl(string $route): string { return '/s/mail-connections'; }
        protected function delegateView(array $arguments): \Symfony\Component\HttpFoundation\Response {
            $this->viewArguments = $arguments; return new \Symfony\Component\HttpFoundation\Response('view');
        }
    }
}
namespace Mautic\EmailBundle\Form\Type {
    class ExampleSendType extends \Symfony\Component\Form\AbstractType {}
}
namespace Mautic\EmailBundle {
    class EmailEvents { public const EMAIL_PRE_SEND = 'mautic.email_pre_send'; }
}
namespace Mautic\EmailBundle\Event {
    class EmailSendEvent {
        public array $headers = [];
        public function __construct(private int $emailId = 95) {}
        public function getEmail(): object { return new class($this->emailId) {
            public function __construct(private int $id) {}
            public function getId(): int { return $this->id; }
        }; }
        public function addTextHeader(string $name, string $value): void { $this->headers[$name] = $value; }
    }
}
namespace {
    use Mautic\CoreBundle\Helper\{UserHelper, CoreParametersHelper};
    use Mautic\EmailBundle\Form\Type\ExampleSendType;
    use Mautic\EmailBundle\Event\EmailSendEvent;
    use MauticPlugin\MauticMultiMailBundle\Application\{ConnectionStore, ConnectionTester, TransportStatus, ExampleRouting};
    use MauticPlugin\MauticMultiMailBundle\Mailer\{ConnectionBuilder, MultiMailTransportFactory, ExampleTransport, ExampleTransportFactory};
    use MauticPlugin\MauticMultiMailBundle\DependencyInjection\Compiler\ExampleTransportPass;
    use MauticPlugin\MauticMultiMailBundle\Form\Extension\ExampleSendExtension;
    use MauticPlugin\MauticMultiMailBundle\EventSubscriber\ExampleSendSubscriber;
    use Symfony\Component\HttpClient\MockHttpClient;
    use Symfony\Component\HttpClient\Response\MockResponse;
    use Symfony\Component\HttpFoundation\{Request, RequestStack};
    use Symfony\Component\HttpFoundation\Session\Session;
    use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
    use MauticPlugin\MauticMultiMailBundle\Controller\ConnectionsController;
    use Symfony\Component\Mailer\{Transport, SentMessage};
    use Symfony\Component\Mailer\Transport\{AbstractTransport, Dsn};
    use Symfony\Component\Mailer\Exception\TransportException;
    use Symfony\Component\Mime\Email;
    use Symfony\Component\DependencyInjection\ContainerBuilder;
    use Symfony\Component\Form\Forms;
    use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
    use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
    use Symfony\Component\Security\Csrf\CsrfTokenManager;
    use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;
    use Symfony\Component\Translation\Translator;
    use Symfony\Component\Validator\Validation;

    function checkExample(bool $valid, string $reason): void { if (!$valid) { throw new \RuntimeException($reason); } }
    final class UnitTokenStorage implements TokenStorageInterface {
        private array $tokens = [];
        public function getToken(string $tokenId): string { return $this->tokens[$tokenId]; }
        public function setToken(string $tokenId, string $token): void { $this->tokens[$tokenId] = $token; }
        public function removeToken(string $tokenId): ?string { $value = $this->tokens[$tokenId] ?? null; unset($this->tokens[$tokenId]); return $value; }
        public function hasToken(string $tokenId): bool { return isset($this->tokens[$tokenId]); }
    }
    final class GlobalTransportSpy extends AbstractTransport {
        public int $calls = 0;
        protected function doSend(SentMessage $message): void { ++$this->calls; }
        public function __toString(): string { return 'global-spy://'; }
    }
    final class GlobalFactorySpy extends \Symfony\Component\Mailer\Transport\AbstractTransportFactory {
        public function __construct(public GlobalTransportSpy $spy) { parent::__construct(); }
        protected function getSupportedSchemes(): array { return ['global-spy']; }
        public function create(Dsn $dsn): \Symfony\Component\Mailer\Transport\TransportInterface { return $this->spy; }
    }
    $root = sys_get_temp_dir().'/multimail_example_unit_'.bin2hex(random_bytes(8));
    mkdir($root.'/project', 0700, true);
    try {
        $store = new ConnectionStore($root.'/project');
        $input = ['provider' => 'resend', 'name' => 'Selected connection', 'from_email' => 'selected@example.com',
            'from_name' => 'Selected sender', 'reply_to' => 'reply@example.com', 'fallback' => '', 'settings' => [],
            'secrets' => ['api_key' => 're_synthetic-secret-abcdefghijklmnop']];
        $view = $store->save(array_replace($input, ['name' => 'Reserve']), 0, 1);
        $reserve = $view['connections'][0]['id'];
        $view = $store->save(array_replace($input, ['fallback' => $reserve]), 1, 1);
        $selected = $view['connections'][1]['id'];
        $calls = []; $status = 200; $responseBody = null; $timeout = false;
        $providerId = '37e4414c-5e25-4dbc-a071-43552a4bd53b';
        $http = new MockHttpClient(function ($method, $url, $options) use (&$calls, &$status, &$responseBody, &$timeout, $providerId) {
            checkExample($method === 'POST' && $url === 'https://api.resend.com/emails', 'Resend factory uses the official send endpoint');
            checkExample(in_array('Authorization: Bearer re_synthetic-secret-abcdefghijklmnop', $options['headers'], true), 'Saved key reaches only the provider Authorization header');
            $calls[] = json_decode($options['body'], true);
            if ($timeout) { throw new \Symfony\Component\HttpClient\Exception\TransportException('synthetic-secret timeout'); }
            return new MockResponse($responseBody ?? ($status === 200 ? json_encode(['id' => $providerId]) : '{"name":"validation_error","message":"synthetic-secret refusal"}'), ['http_code' => $status]);
        });
        $builder = new ConnectionBuilder($http);
        $tester = new ConnectionTester($store, $builder);
        $result = $tester->send($selected, 'recipient@example.com', 2);
        checkExample($result['status'] === 'accepted' && count($calls) === 1, 'Selected connection accepts one diagnostic');
        checkExample($result['http_status'] === 200 && $result['provider_message_id'] === $providerId, 'Accepted diagnostic retains the provider response ID and HTTP status');
        checkExample(str_contains($calls[0]['from'], 'Selected sender') && str_contains($calls[0]['from'], 'selected@example.com') && $calls[0]['reply_to'] === 'reply@example.com', 'Diagnostic uses saved From/Reply-To');
        $status = 401; $calls = [];
        checkExample($tester->send($selected, 'recipient@example.com', 2)['status'] === 'rejected' && count($calls) === 1, 'Diagnostic refusal must never send via reserve');
        $status = 503; $calls = [];
        checkExample($tester->send($selected, 'recipient@example.com', 2)['status'] === 'uncertain' && count($calls) === 1, 'Uncertain handoff must never retry');
        foreach ([400, 401, 403, 404, 405, 422, 429] as $refused) {
            $status = $refused; $calls = [];
            $result = $tester->send($selected, 'recipient@example.com', 2);
            checkExample($result['status'] === 'rejected' && $result['http_status'] === $refused
                && $result['error_code'] === 'validation_error' && count($calls) === 1, 'Explicit refusal is observable without fallback');
            checkExample(!str_contains(json_encode($result), 'synthetic-secret') && !isset($result['provider_message_id']), 'Error content and credentials are never exposed');
        }
        $status = 403; $responseBody = '{"name":"synthetic-secret","message":"private provider details"}';
        checkExample($tester->send($selected, 'recipient@example.com', 2)['error_code'] === 'provider_error', 'Unknown error codes are redacted');
        $responseBody = '<html>synthetic-secret</html>';
        checkExample($tester->send($selected, 'recipient@example.com', 2)['error_code'] === 'provider_error', 'Non-JSON error bodies remain redacted');
        $status = 200;
        foreach (['<html>synthetic-secret</html>', '{"id":"synthetic-secret"}'] as $malformed) {
            $responseBody = $malformed; $calls = [];
            $result = $tester->send($selected, 'recipient@example.com', 2);
            checkExample($result['status'] === 'uncertain' && $result['error_code'] === 'invalid_response'
                && $result['http_status'] === 200 && count($calls) === 1 && !str_contains(json_encode($result), 'synthetic-secret'), 'Malformed acceptance never claims success or retries');
        }
        $timeout = true; $calls = [];
        $result = $tester->send($selected, 'recipient@example.com', 2);
        checkExample($result['status'] === 'uncertain' && !isset($result['http_status']) && count($calls) === 1, 'Network failures do not invent a provider response');
        $timeout = false; $responseBody = null; $status = 200;

        $controller = new ConnectionsController(); $operator = new UserHelper(); $translator = new Translator('en');
        $session = new Session(new MockArraySessionStorage());
        $post = new Request([], ['action' => 'test', 'id' => $selected, 'revision' => '2', 'recipient' => 'recipient@example.com'], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $post->setSession($session); $calls = [];
        $response = $controller->index($post, $operator, $store, $tester, new CoreParametersHelper(), $translator);
        $json = json_decode($response->getContent(), true);
        checkExample($response->getStatusCode() === 200 && str_contains($response->headers->get('Content-Type'), 'application/json')
            && $json['provider_message_id'] === $providerId && $json['http_status'] === 200 && count($calls) === 1, 'Controller returns the real bridge outcome as JSON');
        $saved = $session->get('_multimail_last_test_result');
        checkExample(!str_contains(json_encode($saved), 'recipient@example.com') && !str_contains(json_encode($saved), 'synthetic-secret'), 'Recoverable session result contains no recipient or credentials');
        $get = new Request(['test' => $selected]); $get->setSession($session);
        $controller->index($get, $operator, $store, $tester, new CoreParametersHelper(), $translator);
        checkExample($controller->viewArguments['viewParameters']['lastTest']['result']['provider_message_id'] === $providerId && count($calls) === 1, 'Reload restores the latest outcome without sending again');
        $get = new Request(['test' => $reserve]); $get->setSession($session);
        $controller->index($get, $operator, $store, $tester, new CoreParametersHelper(), $translator);
        checkExample($controller->viewArguments['viewParameters']['lastTest'] === null, 'Result is never attributed to a different connection');
        $response = $controller->index($post, $operator, $store, $tester, new CoreParametersHelper(), $translator);
        checkExample($response->getStatusCode() === 429 && count($calls) === 1, 'Repeated test is throttled before send');
        $controller->validCsrf = false;
        checkExample($controller->index($post, $operator, $store, $tester, new CoreParametersHelper(), $translator)->getStatusCode() === 403 && count($calls) === 1, 'Invalid controller CSRF cannot send');
        $controller->validCsrf = true; $operator->admin = false;
        try { $controller->index($post, $operator, $store, $tester, new CoreParametersHelper(), $translator); throw new \LogicException('Non-admin allowed'); }
        catch (\RuntimeException $e) { checkExample($e->getMessage() === 'denied' && count($calls) === 1, 'Non-admin denied before send'); }
        $calls = [];
        foreach (["recipient@example.com\r\nBcc: victim@example.com", 'one@example.com,two@example.com', 'bad', str_repeat('a', 255).'@example.com'] as $invalid) {
            try { $tester->send($selected, $invalid, 2); throw new \RuntimeException('Invalid recipient accepted'); }
            catch (\InvalidArgumentException) {}
        }
        try { $tester->send($selected, 'recipient@example.com', 1); throw new \RuntimeException('Stale revision accepted'); }
        catch (\DomainException) {}
        checkExample(count($calls) === 0, 'Invalid/stale tests must not send');

        $summary = TransportStatus::describe('smtp://private-user:private-password@sensitive-host?api_key=private-key', $view['connections']);
        checkExample($summary['scheme'] === 'smtp' && !str_contains(json_encode($summary), 'private-'), 'Global status redacts secrets');
        checkExample(TransportStatus::describe('multimail://'.$selected, $view['connections'])['connection_id'] === $selected, 'Active connection matches exact ID');
        checkExample(TransportStatus::describe('failover(smtp://secret@host smtp://other@host)', [])['scheme'] === 'failover', 'Composite status is safe');

        $multi = new MultiMailTransportFactory($store, $builder);
        $example = new ExampleTransport($multi);
        $global = new GlobalTransportSpy();
        $registry = new Transport([new GlobalFactorySpy($global), new ExampleTransportFactory($example)]);
        $container = new ContainerBuilder();
        $container->register('mailer.transports')->setArguments([['main' => 'global-spy://default']]);
        (new ExampleTransportPass())->process($container);
        $named = $container->getDefinition('mailer.transports')->getArgument(0);
        checkExample(array_key_first($named) === 'main' && $named['main'] === 'global-spy://default', 'Compiler pass preserves original default');
        $transports = $registry->fromStrings($named);
        $status = 200; $calls = [];
        $email = (new Email())->from('original@example.com')->to('recipient@example.com')->subject('Original template')
            ->html('<p>Original HTML</p>')->text('Original plain text')->attach('attachment contents', 'test.txt', 'text/plain');
        $email->getHeaders()->addTextHeader('X-Transport', ExampleTransport::NAME);
        $email->getHeaders()->addTextHeader(ExampleTransport::HEADER, $selected);
        checkExample($transports->send($email)->getMessageId() === $providerId && $global->calls === 0, 'Example uses selected connection, not global');
        checkExample($calls[0]['from'] === 'original@example.com' && $calls[0]['html'] === '<p>Original HTML</p>' && count($calls[0]['attachments']) === 1, 'Native example preserves sender/body/attachments');
        checkExample(!str_contains(json_encode($calls[0]), 'X-MultiMail') && !str_contains(json_encode($calls[0]), 'X-Transport'), 'Routing headers never reach provider');
        $transports->send((new Email())->from('original@example.com')->to('recipient@example.com')->subject('Global')->text('Global'));
        checkExample($global->calls === 1, 'Ordinary native sends retain original transport');
        $failure = clone $email;
        $failure->getHeaders()->addTextHeader('X-Transport', ExampleTransport::NAME);
        $failure->getHeaders()->remove(ExampleTransport::HEADER);
        $failure->getHeaders()->addTextHeader(ExampleTransport::HEADER, str_repeat('f', 32));
        try { $transports->send($failure); throw new \RuntimeException('Deleted connection used global'); }
        catch (TransportException $exception) { checkExample(!str_contains($exception->getMessage(), 'synthetic-secret'), 'Failure redacts secrets'); }
        checkExample($global->calls === 1, 'Unavailable selection does not use global');

        $stack = new RequestStack();
        $request = new Request([], [], ['_route' => 'mautic_email_action', 'objectAction' => 'sendExample', 'objectId' => '95'], [], [], ['REQUEST_METHOD' => 'POST']);
        $stack->push($request);
        $routing = new ExampleRouting($stack); $users = new UserHelper();
        $extension = new ExampleSendExtension($users, $store, new CoreParametersHelper(), $routing, new Translator('en'));
        $csrf = new CsrfTokenManager(null, new UnitTokenStorage());
        $forms = Forms::createFormFactoryBuilder()->addTypeExtension($extension)
            ->addExtension(new CsrfExtension($csrf))->addExtension(new ValidatorExtension(Validation::createValidator()))->getFormFactory();
        $options = ['csrf_token_id' => 'example-unit'];
        $submit = function (array $data) use ($forms, $options, $request) {
            $request->attributes->remove(ExampleRouting::ATTRIBUTE);
            $form = $forms->create(ExampleSendType::class, null, $options); $form->submit($data); return $form;
        };
        $token = (string) $csrf->getToken('example-unit');
        $subscriber = new ExampleSendSubscriber($routing, $users);
        foreach ([['multimail_connection' => $selected, '_token' => 'wrong'], ['multimail_connection' => '', '_token' => $token], ['multimail_connection' => str_repeat('f', 32), '_token' => $token]] as $bad) {
            checkExample(!$submit($bad)->isValid() && $routing->selected() === null, 'Invalid CSRF/empty/forged choice cannot activate routing');
        }
        checkExample($submit(['multimail_connection' => $selected, '_token' => $token])->isValid(), 'Validated native form choice');
        $event = new EmailSendEvent(); $subscriber->onPreSend($event);
        checkExample($event->headers === ['X-Transport' => ExampleTransport::NAME, ExampleTransport::HEADER => $selected], 'Validated admin sample attaches exact routing');
        $nested = new EmailSendEvent(96); $subscriber->onPreSend($nested);
        checkExample($nested->headers === [], 'Other email side effects in the same request retain original routing');
        checkExample($submit(['multimail_connection' => ExampleRouting::DEFAULT, '_token' => $token])->isValid() && $routing->selected() === null, 'Global transport must be explicitly chosen');
        $submit(['multimail_connection' => $selected, '_token' => $token]); $users->admin = false;
        $event = new EmailSendEvent(); $subscriber->onPreSend($event);
        checkExample($event->headers === [], 'Non-admin cannot route example');
        $users->admin = true; $request->attributes->set('objectAction', 'send');
        checkExample($routing->selected() === null, 'Selection does not apply to another controller action');
        $request->attributes->set('objectAction', 'sendExample'); $request->setMethod('GET');
        checkExample($routing->selected() === null, 'GET cannot activate sending');
        echo "PASS: isolated diagnostics, no reserve on refusal/uncertainty, safe recipient/revisions/status, explicit native example routing, MIME/attachments preserved, global unchanged, CSRF/choice/admin/request guards; no kernel/database/network\n";
        $usageRequest = Request::create('/s/mail-connections?usage=1', 'GET');
        $usageResponse = $controller->index($usageRequest, new UserHelper(), $store, $tester, new CoreParametersHelper(), $translator);
        $usagePayload = json_decode($usageResponse->getContent(), true);
        checkExample($usageResponse->getStatusCode() === 200 && isset($usagePayload['connections'][0]['hourly'])
            && !str_contains($usageResponse->getContent(), 'synthetic-secret') && !isset($usagePayload['connections'][0]['secret_configured']), 'Administrator counters response excludes credential metadata and secrets');
        try { $controller->index($usageRequest, new UserHelper(false), $store, $tester, new CoreParametersHelper(), $translator); throw new \LogicException('Expected denial'); }
        catch (\RuntimeException $error) { checkExample($error->getMessage() === 'denied', 'Non-admin cannot inspect account counters'); }
    } finally {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($root);
    }
}
