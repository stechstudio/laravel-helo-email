<?php

namespace STS\HeloEmail;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class HeloClient
{
    public function __construct(
        protected ?string $key,
        protected ?string $channelId = null,
        protected string $baseUrl = 'https://api.helohq.com',
        protected int $timeout = 30,
    ) {
    }

    public function channelId(): ?string
    {
        return $this->channelId;
    }

    /** A client for another channel, for platforms that send for many customers. */
    public function forChannel(?string $channelId): static
    {
        $client = clone $this;
        $client->channelId = $channelId;

        return $client;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<mixed>|null $json
     * @param array<string, string|null> $headers
     * @return array<mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null, array $headers = []): array
    {
        $options = array_filter([
            'query' => array_filter($query, fn ($value) => $value !== null),
            'json' => $json,
        ], fn ($value) => $value !== null && $value !== []);

        $response = $this->http()
            ->withHeaders(array_filter($headers, fn ($value) => $value !== null && $value !== ''))
            ->send($method, $path, $options);

        if ($response->failed()) {
            throw HeloException::fromResponse($response);
        }

        if ($response->status() === 204 || trim($response->body()) === '') {
            return [];
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new HeloException('Helo returned a response that is not JSON.', $response);
        }

        return $json;
    }

    protected function http(): PendingRequest
    {
        if (blank($this->key)) {
            throw new HeloException('Set HELO_API_KEY before calling the Helo API.');
        }

        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->key)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout($this->timeout);
    }
}
