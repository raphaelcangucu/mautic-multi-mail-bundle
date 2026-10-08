<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use Symfony\Component\Mailer\{Envelope, SentMessage};
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\{Address, Email, Message};

/** Fixed HTTPS endpoints; no user-supplied URLs, remote attachments or SDK retry loops. */
final class AdditionalApiTransport extends AbstractTransport
{
    private const MAX_REQUEST_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly string $provider, #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $region, private readonly OutcomeHttpClient $client)
    {
        if (!in_array($provider, ['sparkpost', 'smtp2go'], true) || !in_array($region, ['us', 'eu'], true)) {
            throw new \InvalidArgumentException('Unsupported API connection.');
        }
        parent::__construct();
    }

    public function __toString(): string { return $this->provider.'+api://'.$this->endpoint(); }

    protected function doSend(SentMessage $message): void
    {
        if ($this->provider === 'sparkpost') {
            $mime = $message->getMessage()->toString();
            $payload = [
                'options' => ['open_tracking' => false, 'click_tracking' => false],
                'recipients' => array_map(static fn (Address $address) => ['address' => ['email' => $address->getAddress()]], $message->getEnvelope()->getRecipients()),
                'content' => ['email_rfc822' => $mime],
            ];
            $headers = ['Authorization' => $this->apiKey];
        } else {
            $original = $message->getOriginalMessage();
            if (!$original instanceof Message) { throw new TransportException('An API connection requires a MIME message.'); }
            $email = ApiEmail::prepare($original, $message->getEnvelope());
            $payload = $this->smtp2goPayload($email, $message->getEnvelope());
            $headers = ['X-Smtp2go-Api-Key' => $this->apiKey];
        }
        // Bound the serialized request, including encoded attachments, before network handoff.
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > self::MAX_REQUEST_BYTES) {
            throw new TransportException('API message exceeds the 20 MiB request limit.');
        }
        $this->client->request('POST', 'https://'.$this->endpoint(), ['headers' => $headers, 'json' => $payload]);
        $message->setMessageId($this->client->providerMessageId() ?? throw new ProviderResponseException(false));
    }

    private function endpoint(): string
    {
        return $this->provider === 'smtp2go' ? 'api.smtp2go.com/v3/email/send'
            : ($this->region === 'eu' ? 'api.eu.sparkpost.com' : 'api.sparkpost.com').'/api/v1/transmissions';
    }

    private function smtp2goPayload(Email $email, Envelope $envelope): array
    {
        $cc = $email->getCc(); $bcc = $email->getBcc();
        $to = array_filter($envelope->getRecipients(), static fn (Address $address) => !in_array($address, [...$cc, ...$bcc], true));
        if (count($envelope->getRecipients()) > 100 || $to === []) {
            throw new TransportException('SMTP2GO requires a To recipient and at most 100 envelope recipients.');
        }
        $format = static fn (Address $address) => $address->toString();
        $payload = ['sender' => $envelope->getSender()->toString(), 'to' => array_values(array_map($format, $to)),
            'subject' => $email->getSubject(), 'fastaccept' => false];
        if ($cc) { $payload['cc'] = array_map($format, $cc); }
        if ($bcc) { $payload['bcc'] = array_map($format, $bcc); }
        if ($email->getTextBody() !== null) { $payload['text_body'] = $email->getTextBody(); }
        if ($email->getHtmlBody() !== null) { $payload['html_body'] = $email->getHtmlBody(); }
        foreach ($email->getHeaders()->all() as $header) {
            if (in_array(strtolower($header->getName()), ['from', 'sender', 'return-path', 'to', 'cc', 'bcc', 'subject', 'content-type', 'content-transfer-encoding', 'mime-version'], true)) { continue; }
            $payload['custom_headers'][] = ['header' => $header->getName(), 'value' => $header->getBodyAsString()];
        }
        foreach ($email->getAttachments() as $attachment) {
            $inline = $attachment->getDisposition() === 'inline';
            $filename = $inline ? $attachment->getContentId() : ($attachment->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename') ?: 'attachment');
            $payload[$inline ? 'inlines' : 'attachments'][] = [
                'filename' => $filename, 'fileblob' => base64_encode($attachment->getBody()),
                'mimetype' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
            ];
        }
        return $payload;
    }
}
