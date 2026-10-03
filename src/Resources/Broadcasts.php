<?php

namespace STS\HeloEmail\Resources;

use STS\HeloEmail\Page;
use STS\HeloEmail\Response;

class Broadcasts extends Resource
{
    /** @param array<string, mixed> $extra */
    public function list(
        ?string $status = null,
        ?string $subject = null,
        ?string $channelId = null,
        int $limit = 100,
        int $offset = 0,
        array $extra = [],
    ): Page {
        return $this->offsetPage('/broadcasts', $this->query([
            'channelId' => $this->requireChannel($channelId),
            'status' => $status,
            'subject' => $subject,
            'limit' => $limit,
        ], $extra), $offset);
    }

    public function get(string $id): Response
    {
        return $this->object('GET', "/broadcasts/{$id}");
    }

    /**
     * Messages that failed permanently. Retried transient errors aren't listed.
     *
     * @param array<string, mixed> $extra
     */
    public function failures(string $id, int $limit = 100, int $offset = 0, array $extra = []): Page
    {
        return $this->offsetPage("/broadcasts/{$id}/failures", $this->query(['limit' => $limit], $extra), $offset);
    }

    /**
     * Recipients skipped because they were on the suppression list. Helo lists
     * bare addresses; each item here reads as ->email like other suppressions.
     *
     * @param array<string, mixed> $extra
     */
    public function suppressions(string $id, int $limit = 100, int $offset = 0, array $extra = []): Page
    {
        return $this->offsetPage(
            "/broadcasts/{$id}/suppressions",
            $this->query(['limit' => $limit], $extra),
            $offset,
            fn (array $json) => is_array($json['results'] ?? null)
                ? [...$json, 'results' => array_map(fn ($result) => is_string($result) ? ['email' => $result] : $result, $json['results'])]
                : $json,
        );
    }
}
