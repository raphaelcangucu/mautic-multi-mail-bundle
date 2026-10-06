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
        }; }
    }
    class CoreParametersHelper {
        public function get(string $name): string { return 'smtp://unit-user:unit-secret@example.com'; }
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
        $calls = []; $status = 200;
        $http = new MockHttpClient(function ($method, $url, $options) use (&$calls, &$status) {
            $calls[] = json_decode($options['body'], true);
            return new MockResponse($status === 200 ? '{"id":"synthetic-provider-id"}' : '{"message":"synthetic-secret refusal"}', ['http_code' => $status]);
        });
        $builder = new ConnectionBuilder($http);
        $tester = new ConnectionTester($store, $builder);
        $result = $tester->send($selected, 'recipient@example.com', 2);
        checkExample($result['status'] === 'accepted' && count($calls) === 1, 'Selected connection accepts one diagnostic');
        checkExample(str_contains($calls[0]['from'], 'Selected sender') && str_contains($calls[0]['from'], 'selected@example.com') && $calls[0]['reply_to'] === 'reply@example.com', 'Diagnostic uses saved From/Reply-To');
        $status = 401; $calls = [];
        checkExample($tester->send($selected, 'recipient@example.com', 2)['status'] === 'rejected' && count($calls) === 1, 'Diagnostic refusal must never send via reserve');
        $status = 503; $calls = [];
        checkExample($tester->send($selected, 'recipient@example.com', 2)['status'] === 'uncertain' && count($calls) === 1, 'Uncertain handoff must never retry');
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
        checkExample($transports->send($email)->getMessageId() === 'synthetic-provider-id' && $global->calls === 0, 'Example uses selected connection, not global');
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
    } finally {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($root);
    }
}
