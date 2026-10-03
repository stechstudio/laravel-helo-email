<?php

namespace STS\HeloEmail\Resources;

use STS\HeloEmail\Page;
use STS\HeloEmail\Response;

/** Helo keeps one suppression list per channel and mail type. */
class Suppressions extends Resource
{
    /** @param array<string, mixed> $extra */
    public function list(
        string $mailType = 'transactional',
        ?string $reason = null,
        ?string $email = null,
        ?string $channelId = null,
        int $limit = 100,
        int $offset = 0,
        array $extra = [],
    ): Page {
        return $this->offsetPage('/suppressions', $this->query([
            'channelId' => $this->requireChannel($channelId),
            'mailType' => $mailType,
            'reason' => $reason,
            'email' => $email,
            'limit' => $limit,
        ], $extra), $offset);
    }

    /**
     * Per-address results come back inside HTTP 200; check each one's "success".
     *
     * @param list<string> $emails
     * @param array<string, mixed> $extra
     */
    public function add(array $emails, string $mailType = 'transactional', ?string $channelId = null, array $extra = []): Response
    {
        return $this->object('POST', '/suppressions', $this->body($emails, $mailType, $channelId, $extra));
    }

    /**
     * Helo refuses to remove unsubscribe and complaint suppressions, and says
     * so per address inside HTTP 200; check each result's "success".
     *
     * @param list<string> $emails
     * @param array<string, mixed> $extra
     */
    public function remove(array $emails, string $mailType = 'transactional', ?string $channelId = null, array $extra = []): Response
    {
        return $this->object('POST', '/suppressions/remove', $this->body($emails, $mailType, $channelId, $extra));
    }

    /**
     * @param list<string> $emails
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function body(array $emails, string $mailType, ?string $channelId, array $extra): array
    {
        return ['channelId' => $this->requireChannel($channelId), 'mailType' => $mailType, 'emails' => $emails, ...$extra];
    }
}
