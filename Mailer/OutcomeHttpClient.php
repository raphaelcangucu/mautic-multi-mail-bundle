<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use Symfony\Contracts\HttpClient\{HttpClientInterface, ResponseInterface, ResponseStreamInterface};

final class OutcomeHttpClient implements HttpClientInterface
{
    private ?int $lastStatus = null;

    public function __construct(private HttpClientInterface $client)
    {
    }

    public function request(string $method, string $url, #[\SensitiveParameter] array $options = []): ResponseInterface
    {
        $this->lastStatus = null;
        $response = $this->client->request($method, $url, $options);
        $this->lastStatus = $response->getStatusCode();

        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(#[\SensitiveParameter] array $options): static
    {
        // Share the status tracker when an SDK adds options to the supplied client.
        $this->client = $this->client->withOptions($options);

        return $this;
    }

    public function confirmedNotAccepted(): bool
    {
        // Never retry an HTTP timeout, a 5xx or a successful acceptance on another provider.
        return in_array($this->lastStatus, [401, 403, 429], true);
    }

    public function statusCode(): ?int
    {
        return $this->lastStatus;
    }
}
