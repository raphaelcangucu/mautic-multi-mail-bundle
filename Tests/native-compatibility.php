<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';

use Mautic\EmailBundle\Mailer\Transport\{TransportFactory as MauticFactory, TokenTransportInterface, BounceProcessorInterface, UnsubscriptionProcessorInterface};
use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use MauticPlugin\MauticMultiMailBundle\Mailer\{MultiMailTransportFactory, NativeTransportResolver, ConnectionBuilder};
use Symfony\Component\DependencyInjection\{ContainerBuilder, Reference};
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\{Transport, SentMessage};
use Symfony\Component\Mailer\Transport\{AbstractTransport, Dsn, TransportFactoryInterface, TransportInterface};
use Symfony\Component\Mailer\Exception\{InvalidArgumentException, TransportException};
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mime\Email;
use Symfony\Component\EventDispatcher\EventDispatcher;

function assertCompatible(bool $ok, string $reason): void { if (!$ok) { throw new RuntimeException($reason); } }

final class InstalledBatchProvider extends AbstractTransport implements TokenTransportInterface, BounceProcessorInterface, UnsubscriptionProcessorInterface
{
    public function __toString(): string { return 'thirdparty+api://default'; }
    protected function doSend(SentMessage $message): void { $message->setMessageId('native-provider-id'); }
    public function getMaxBatchLimit(): int { return 500; }
    public function getBatchRecipientCount(Email $message, int $toBeAdded = 1, string $type = 'to'): int { return $toBeAdded; }
    public function processBounce(\Mautic\EmailBundle\MonitoredEmail\Message $message): \Mautic\EmailBundle\MonitoredEmail\Processor\Bounce\BouncedEmail { throw new RuntimeException('Fixture only'); }
    public function processUnsubscription(\Mautic\EmailBundle\MonitoredEmail\Message $message): \Mautic\EmailBundle\MonitoredEmail\Processor\Unsubscription\UnsubscribedEmail { throw new RuntimeException('Fixture only'); }
}
final class InstalledProviderFactory implements TransportFactoryInterface
{
    public function __construct(public readonly InstalledBatchProvider $transport) {}
    public function supports(Dsn $dsn): bool { return $dsn->getScheme() === 'thirdparty+api'; }
    public function create(Dsn $dsn): TransportInterface { return $this->transport; }
}

$root = sys_get_temp_dir().'/multimail_compat_unit_'.bin2hex(random_bytes(8));
mkdir($root.'/project', 0700, true);
try {
    $events = new EventDispatcher(); $sentEvents = 0;
    $events->addListener(SentMessageEvent::class, function () use (&$sentEvents) { ++$sentEvents; });
    $mockHttp = new MockHttpClient(static fn() => throw new RuntimeException('Compatibility test must never send HTTP'));
    $originalFactories = iterator_to_array(Transport::getDefaultFactories($events, $mockHttp));
    $provider = new InstalledBatchProvider($events);
    $thirdParty = new InstalledProviderFactory($provider);
    $originalFactories[] = $thirdParty;
    $originalRegistry = new Transport($originalFactories);

    // Compile the actual lazy resolver against a registry containing Multi Mail itself.
    $container = new ContainerBuilder();
    $container->setParameter('kernel.project_dir', $root.'/project');
    $container->register(ConnectionStore::class, ConnectionStore::class)->setAutowired(true)->setPublic(true);
    $container->register(ConnectionBuilder::class, ConnectionBuilder::class)->setPublic(true);
    $container->register(NativeTransportResolver::class, NativeTransportResolver::class)->setAutowired(true)->setPublic(true);
    $container->register(MultiMailTransportFactory::class, MultiMailTransportFactory::class)->setAutowired(true)->setPublic(true)->addTag('mailer.transport_factory');
    foreach ($originalFactories as $index => $nativeFactory) {
        $id = 'test.native_factory.'.$index;
        $container->register($id, $nativeFactory::class)->setSynthetic(true)->setPublic(true)->addTag('mailer.transport_factory');
    }
    $container->register('test.native_registry', Transport::class)->setArguments([new TaggedIteratorArgument('mailer.transport_factory')])->setPublic(true);
    $container->register(MauticFactory::class, MauticFactory::class)->setArguments([new Reference('test.native_registry')])->setPublic(true);
    $container->compile();
    foreach ($originalFactories as $index => $nativeFactory) { $container->set('test.native_factory.'.$index, $nativeFactory); }
    $store = $container->get(ConnectionStore::class);
    $installedRegistry = $container->get('test.native_registry');
    $multi = $container->get(MultiMailTransportFactory::class);

    $schemes = [];
    foreach ($originalFactories as $nativeFactory) {
        if ($nativeFactory instanceof InstalledProviderFactory) { $supported = ['thirdparty+api']; }
        else { $supported = (new ReflectionMethod($nativeFactory, 'getSupportedSchemes'))->invoke($nativeFactory); }
        foreach ($supported as $scheme) {
            $schemes[] = $scheme;
            $dsn = new Dsn($scheme, 'default', 'unit-key', 'mg.example.com', null, ['region' => 'us-east-1']);
            assertCompatible(!$multi->supports($dsn), 'Multi Mail must not intercept native scheme '.$scheme);
            // Mailgun uses us/eu instead of an AWS region.
            if (str_starts_with($scheme, 'mailgun')) { $dsn = new Dsn($scheme, 'default', 'unit-key', 'mg.example.com', null, ['region' => 'us']); }
            try { $expected = $originalRegistry->fromDsnObject($dsn); }
            catch (Throwable $before) {
                try { $installedRegistry->fromDsnObject($dsn); throw new RuntimeException('Changed native refusal'); }
                catch (Throwable $after) { assertCompatible($before::class === $after::class && $before->getMessage() === $after->getMessage(), 'Original validation failure preserved'); }
                continue;
            }
            $actual = $installedRegistry->fromDsnObject($dsn);
            assertCompatible($expected::class === $actual::class, 'Original transport class preserved for '.$scheme);
        }
    }
    foreach (['failover(null://default null://default)', 'roundrobin(null://default null://default)'] as $dsn) {
        assertCompatible($originalRegistry->fromString($dsn)::class === $installedRegistry->fromString($dsn)::class, 'Native composite transport preserved');
    }
    $input = ['provider' => 'native', 'name' => 'Installed adapter', 'from_email' => 'sender@example.com', 'from_name' => 'Mautic',
        'reply_to' => '', 'fallback' => '', 'settings' => [], 'secrets' => ['dsn' => 'thirdparty+api://unit-secret@default?option=preserved']];
    $view = $store->save($input, 0, 1); $id = $view['connections'][0]['id'];
    assertCompatible(!str_contains(json_encode($view), 'unit-secret'), 'Private native DSN not exposed');
    $transport = $installedRegistry->fromString('multimail://'.$id);
    assertCompatible($transport === $provider, 'Return exact installed provider instance; no wrapper');
    assertCompatible($transport instanceof TokenTransportInterface && $transport->getMaxBatchLimit() === 500, 'Batch interface preserved');
    assertCompatible($transport instanceof BounceProcessorInterface && $transport instanceof UnsubscriptionProcessorInterface, 'Bounce/unsubscription interfaces preserved');
    $email = (new Email())->from('sender@example.com')->to('recipient@example.com')->subject('Unit test')->text('No network');
    assertCompatible($transport->send($email)->getMessageId() === 'native-provider-id' && $sentEvents === 1, 'Provider message id and single event preserved');
    $view = $store->save(array_replace($input, ['id' => $id, 'secrets' => ['dsn' => '']]), 1, 1);
    assertCompatible($store->transportChain($id)[0]['secrets']['dsn'] === $input['secrets']['dsn'], 'Blank edit preserves full encoded DSN and options');
    foreach (['failover(null://default null://default)', 'roundrobin(null://default null://default)'] as $dsn) {
        $view = $store->save(array_replace($input, ['secrets' => ['dsn' => $dsn]]), $view['revision'], 1);
        $newId = $view['connections'][array_key_last($view['connections'])]['id'];
        assertCompatible($installedRegistry->fromString('multimail://'.$newId)::class === $originalRegistry->fromString($dsn)::class, 'Native connection preserves composite behavior');
    }
    $view = $store->save(array_replace($input, ['secrets' => ['dsn' => 'notinstalled+api://unit-secret@default']]), $view['revision'], 1);
    $unknown = $view['connections'][array_key_last($view['connections'])]['id'];
    try { $installedRegistry->fromString('multimail://'.$unknown); throw new RuntimeException('Expected unsupported provider refusal'); }
    catch (InvalidArgumentException $error) { assertCompatible(!str_contains($error->getMessage(), 'unit-secret') && $error->getPrevious() === null, 'Unknown adapter refusal is safe'); }
    echo 'PASS: unchanged native registry for '.count(array_unique($schemes)).' schemes, composites, lazy DI without cycle, installed plugin identity, batch/bounce/unsubscription interfaces, message id/events and private DSN preservation; no kernel/database/network'."\n";
} finally {
    foreach (glob($root.'/project-multimail-private/*') ?: [] as $file) { unlink($file); }
    if (is_dir($root.'/project-multimail-private')) { rmdir($root.'/project-multimail-private'); }
    rmdir($root.'/project'); rmdir($root);
}
