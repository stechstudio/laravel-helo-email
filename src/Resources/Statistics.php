<?php

namespace STS\HeloEmail\Resources;

use DateTimeInterface;
use STS\HeloEmail\Response;

class Statistics extends Resource
{
    /**
     * @param list<string>|null $tags
     * @param array<string, mixed> $extra
     */
    public function hourly(
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ?array $tags = null,
        ?string $channelId = null,
        array $extra = [],
    ): Response {
        return $this->stats('/statistics/hourly', ['from' => $from, 'to' => $to, 'tags' => $tags], $channelId, $extra);
    }

    /**
     * Helo's daily statistics take plain dates and a required timezone.
     *
     * @param list<string>|null $tags
     * @param array<string, mixed> $extra
     */
    public function daily(
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        string $timezone = 'UTC',
        ?array $tags = null,
        ?string $channelId = null,
        array $extra = [],
    ): Response {
        $date = fn ($value) => $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value;

        return $this->stats('/statistics/daily', ['from' => $date($from), 'to' => $date($to), 'tags' => $tags, 'timezone' => $timezone], $channelId, $extra);
    }

    /**
     * @param list<string>|null $tags
     * @param array<string, mixed> $extra
     */
    public function totals(
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ?array $tags = null,
        ?string $channelId = null,
        array $extra = [],
    ): Response {
        return $this->stats('/statistics/totals', ['from' => $from, 'to' => $to, 'tags' => $tags], $channelId, $extra);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $extra
     */
    protected function stats(string $path, array $params, ?string $channelId, array $extra): Response
    {
        $query = $this->query(['channelId' => $channelId ?? $this->client->channelId(), ...$params], $extra);

        return new Response($this->client->request('GET', $path, $query));
    }
}
