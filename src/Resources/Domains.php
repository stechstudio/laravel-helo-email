<?php

namespace STS\HeloEmail\Resources;

use STS\HeloEmail\Page;
use STS\HeloEmail\Response;

class Domains extends Resource
{
    /**
     * @param list<string>|null $channelIds
     * @param array<string, mixed> $extra
     */
    public function list(?string $name = null, ?array $channelIds = null, int $limit = 100, int $offset = 0, array $extra = []): Page
    {
        return $this->offsetPage('/domains', $this->query([
            'name' => $name,
            'channelIds' => $channelIds,
            'limit' => $limit,
        ], $extra), $offset);
    }

    /**
     * The response lists the DNS records to add before calling verify().
     *
     * @param list<string> $channelIds
     * @param array<string, mixed> $extra
     */
    public function create(string $name, array $channelIds = [], array $extra = []): Response
    {
        return $this->object('POST', '/domains', array_filter(['name' => $name, 'channelIds' => $channelIds, ...$extra], fn ($value) => $value !== []));
    }

    public function get(string $id): Response
    {
        return $this->object('GET', "/domains/{$id}");
    }

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): Response
    {
        return $this->object('PATCH', "/domains/{$id}", $changes);
    }

    public function delete(string $id): void
    {
        $this->client->request('DELETE', "/domains/{$id}");
    }

    /** Check the domain's DNS records now. */
    public function verify(string $id): Response
    {
        return $this->object('POST', "/domains/{$id}/verify");
    }

    /** Generate a new DKIM key; the response is the record to publish. */
    public function rotateKey(string $id): Response
    {
        return $this->object('POST', "/domains/{$id}/rotate-key");
    }
}
