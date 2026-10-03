<?php

namespace STS\HeloEmail\Resources;

use STS\HeloEmail\Page;
use STS\HeloEmail\Response;

class Webhooks extends Resource
{
    /**
     * @param list<string>|null $channelIds
     * @param array<string, mixed> $extra
     */
    public function list(?array $channelIds = null, int $limit = 100, int $offset = 0, array $extra = []): Page
    {
        return $this->offsetPage('/webhooks', $this->query(['channelIds' => $channelIds, 'limit' => $limit], $extra), $offset);
    }

    /**
     * The response's payloadSigningKey verifies this webhook's deliveries.
     *
     * @param list<string> $events
     * @param array<string, string> $additionalHeaders name => value; Helo prefixes each name with X-Customer-
     * @param array<string, mixed> $extra
     */
    public function create(
        string $url,
        array $events,
        ?string $channelId = null,
        array $additionalHeaders = [],
        bool $enabled = true,
        array $extra = [],
    ): Response {
        return $this->object('POST', '/webhooks', array_filter([
            'url' => $url,
            'events' => $events,
            'channelId' => $this->requireChannel($channelId),
            'additionalHeaders' => array_map(
                fn ($name, $value) => ['name' => $name, 'value' => $value],
                array_keys($additionalHeaders),
                $additionalHeaders,
            ),
            'enabled' => $enabled,
            ...$extra,
        ], fn ($value) => $value !== null && $value !== []));
    }

    public function get(string $id): Response
    {
        return $this->object('GET', "/webhooks/{$id}");
    }

    /**
     * Only the given fields change. A null channelId removes channel scoping.
     *
     * @param array<string, mixed> $changes
     */
    public function update(string $id, array $changes): Response
    {
        return $this->object('PATCH', "/webhooks/{$id}", $changes);
    }

    public function delete(string $id): void
    {
        $this->client->request('DELETE', "/webhooks/{$id}");
    }

    public function regenerateSigningKey(string $id): Response
    {
        return $this->object('POST', "/webhooks/{$id}/regenerate-signing-key");
    }
}
