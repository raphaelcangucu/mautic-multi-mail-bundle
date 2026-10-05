<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ConnectionBuilder
{
    public function __construct(private readonly ?HttpClientInterface $httpClient = null)
    {
    }

    public function build(#[\SensitiveParameter] array $connection): Attempt
    {
        $settings = $connection['settings'];
        $secrets = $connection['secrets'];
        if ($connection['provider'] === 'smtp') {
            $smtp = new ConfirmedSmtpTransport($settings['host'], (int) $settings['port'], $settings['encryption'] === 'ssl');
            $smtp->setRequireTls(true);
            $smtp->setUsername($settings['username']);
            $smtp->setPassword($secrets['password']);
            $smtp->getStream()->setTimeout(20);

            return new Attempt($smtp, $smtp);
        }
        $factories = [
            'ses' => ['Amazon\\Transport\\SesTransportFactory', 'ses+https'],
            'resend' => ['Resend\\Transport\\ResendTransportFactory', 'resend+api'],
            'mailgun' => ['Mailgun\\Transport\\MailgunTransportFactory', 'mailgun+api'],
            'sendgrid' => ['Sendgrid\\Transport\\SendgridTransportFactory', 'sendgrid+api'],
            'postmark' => ['Postmark\\Transport\\PostmarkTransportFactory', 'postmark+api'],
            'brevo' => ['Brevo\\Transport\\BrevoTransportFactory', 'brevo+api'],
        ];
        [$suffix, $scheme] = $factories[$connection['provider']] ?? throw new \RuntimeException('Mail provider unavailable.');
        $class = 'Symfony\\Component\\Mailer\\Bridge\\'.$suffix;
        if (!class_exists($class)) { throw new \RuntimeException('Mail provider bridge unavailable.'); }
        $client = new OutcomeHttpClient($this->httpClient ?? HttpClient::create(['timeout' => 20, 'max_duration' => 30]));
        $factory = new $class(null, $client);
        $user = $secrets['api_key'] ?? $secrets['access_key'];
        $password = $connection['provider'] === 'ses' ? $secrets['secret_key'] : null;
        $options = [];
        if ($connection['provider'] === 'ses') { $options['region'] = $settings['region']; }
        if ($connection['provider'] === 'mailgun') {
            $password = $settings['domain'];
            $options['region'] = $settings['region'];
        }
        $transport = $factory->create(new Dsn($scheme, 'default', $user, $password, null, $options));

        return new Attempt($transport, $client);
    }
}
