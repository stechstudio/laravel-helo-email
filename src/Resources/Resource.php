<?php

namespace STS\HeloEmail\Resources;

use Closure;
use DateTimeInterface;
use STS\HeloEmail\HeloException;
use STS\HeloEmail\HeloClient;
use STS\HeloEmail\Page;
use STS\HeloEmail\Response;

abstract class Resource
{
    public function __construct(protected HeloClient $client)
    {
    }

    /**
     * Encode query parameters the way Helo expects: drop empty values, join
     * lists with commas, and send dates as ISO 8601. $extra passes through
     * parameters the package doesn't know about yet.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function query(array $params, array $extra = []): array
    {
        $query = [];

        foreach ([...$params, ...$extra] as $key => $value) {
            $query[$key] = match (true) {
                $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
                is_array($value) => $value === [] ? null : implode(',', $value),
                is_bool($value) => $value ? 'true' : 'false',
                default => $value,
            };
        }

        return array_filter($query, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The given channel, else the client's. Endpoints that act on one channel's
     * data need one, so fail before sending rather than let Helo guess.
     */
    protected function requireChannel(?string $channelId): string
    {
        return $channelId ?? $this->client->channelId()
            ?? throw new HeloException('Pass a channel ID or set HELO_CHANNEL_ID; this Helo endpoint needs one.');
    }

    /**
     * @param array<string, mixed> $query
     * @param (Closure(array<mixed>): array<mixed>)|null $map reshapes each raw page before paging
     */
    protected function offsetPage(string $path, array $query, int $offset, ?Closure $map = null): Page
    {
        return Page::offset(function (int $offset) use ($path, $query, $map) {
            $json = $this->client->request('GET', $path, [...$query, 'offset' => $offset]);

            return $map ? $map($json) : $json;
        }, $offset);
    }

    /** @param array<mixed>|null $json */
    protected function object(string $method, string $path, ?array $json = null): Response
    {
        return new Response($this->client->request($method, $path, json: $json));
    }
}
