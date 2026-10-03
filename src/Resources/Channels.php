<?php

namespace STS\HeloEmail\Resources;

use STS\HeloEmail\Page;
use STS\HeloEmail\Response;

class Channels extends Resource
{
    /**
     * @param list<string>|null $channelIds
     * @param array<string, mixed> $extra
     */
    public function list(
        ?string $name = null,
        ?array $channelIds = null,
        ?string $deliveryType = null,
        int $limit = 100,
        int $offset = 0,
        array $extra = [],
    ): Page {
        return $this->offsetPage('/channels', $this->query([
            'name' => $name,
            'channelIds' => $channelIds,
            'deliveryType' => $deliveryType,
            'limit' => $limit,
        ], $extra), $offset);
    }

    /**
     * @param string $deliveryType "live", or "sandbox" to accept mail without delivering it
     * @param array<string, mixed> $extra
     */
    public function create(
        string $name,
        string $deliveryType = 'live',
        ?bool $trackOpens = null,
        ?bool $trackLinks = null,
        array $extra = [],
    ): Response {
        $tracking = array_filter(['opens' => $trackOpens, 'links' => $trackLinks], fn ($value) => $value !== null);

        return $this->object('POST', '/channels', array_filter([
            'name' => $name,
            'deliveryType' => $deliveryType,
            'tracking' => $tracking,
            ...$extra,
        ], fn ($value) => $value !== []));
    }

    public function get(string $id): Response
    {
        return $this->object('GET', "/channels/{$id}");
    }

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): Response
    {
        return $this->object('PATCH', "/channels/{$id}", $changes);
    }

    public function delete(string $id): void
    {
        $this->client->request('DELETE', "/channels/{$id}");
    }
}
