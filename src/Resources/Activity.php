<?php

namespace STS\HeloEmail\Resources;

use DateTimeInterface;
use STS\HeloEmail\Page;
use STS\HeloEmail\Response;

/** Activity lists page by an "after" cursor, not an offset. */
class Activity extends Resource
{
    /**
     * @param list<string>|null $eventTypes
     * @param list<string>|null $tags
     * @param array<string, mixed> $extra
     */
    public function events(
        ?string $messageId = null,
        ?string $recipient = null,
        ?array $eventTypes = null,
        ?string $mailType = null,
        ?array $tags = null,
        ?string $subject = null,
        DateTimeInterface|string|null $startDate = null,
        DateTimeInterface|string|null $endDate = null,
        ?string $channelId = null,
        int $limit = 100,
        ?int $after = null,
        array $extra = [],
    ): Page {
        return $this->cursorPage('/activity/events', $this->query([
            'channelId' => $channelId ?? $this->client->channelId(),
            'messageId' => $messageId,
            'recipient' => $recipient,
            'eventTypes' => $eventTypes,
            'mailType' => $mailType,
            'tags' => $tags,
            'subject' => $subject,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'limit' => $limit,
        ], $extra), $after);
    }

    /**
     * @param list<string>|null $tags
     * @param array<string, mixed> $extra
     */
    public function messages(
        ?string $recipient = null,
        ?string $status = null,
        ?string $mailType = null,
        ?array $tags = null,
        ?string $subject = null,
        DateTimeInterface|string|null $startDate = null,
        DateTimeInterface|string|null $endDate = null,
        ?string $channelId = null,
        int $limit = 100,
        ?int $after = null,
        array $extra = [],
    ): Page {
        return $this->cursorPage('/activity/messages', $this->query([
            'channelId' => $channelId ?? $this->client->channelId(),
            'recipient' => $recipient,
            'status' => $status,
            'mailType' => $mailType,
            'tags' => $tags,
            'subject' => $subject,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'limit' => $limit,
        ], $extra), $after);
    }

    /** One message with all of its events. */
    public function message(string $id): Response
    {
        return $this->object('GET', "/activity/messages/{$id}");
    }

    /** @param array<string, mixed> $query */
    protected function cursorPage(string $path, array $query, ?int $after): Page
    {
        return Page::cursor(
            fn (?int $after) => $this->client->request('GET', $path, $this->query([...$query, 'after' => $after])),
            $after,
        );
    }
}
