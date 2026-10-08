<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use Symfony\Component\Mailer\{Envelope, SentMessage};
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\{Dsn, TransportInterface};
use Symfony\Component\Mime\{Message, RawMessage};

/** Named transport for native email examples; the selected ID survives Messenger queues. */
final class ExampleTransport implements TransportInterface
{
    public const NAME = 'multimail_example';
    public const HEADER = 'X-MultiMail-Example-Connection';

    public function __construct(private readonly MultiMailTransportFactory $factory) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (!$message instanceof Message) { throw new TransportException('Multi Mail: exemplo sem conexão selecionada.'); }
        $id = $message->getHeaders()->get(self::HEADER)?->getBody();
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new TransportException('Multi Mail: exemplo sem conexão selecionada.');
        }
        $message = clone $message;
        $message->getHeaders()->remove(self::HEADER);
        try {
            $sent = $this->factory->create(new Dsn('multimail', $id))->send($message, $envelope);
            if ($sent === null) { throw new \RuntimeException('Message not accepted.'); }
            return $sent;
        } catch (\Throwable) {
            // An explicit selection may never silently fall back to Mautic's global transport.
            throw new TransportException('Multi Mail: envio pela conexão selecionada não confirmado. O transporte padrão não foi utilizado.');
        }
    }

    public function __toString(): string { return 'multimail-example://default'; }
}
