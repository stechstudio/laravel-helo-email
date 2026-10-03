<?php

namespace STS\HeloEmail;

use Illuminate\Mail\SentMessage as LaravelSentMessage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Message;
use WeakMap;

/**
 * What Helo said about one send. The transport records it against the exact
 * message object it sent, in memory, so it never goes out on the wire and a
 * later send of a copy can't inherit it.
 */
final class HeloResult
{
    /** @var WeakMap<Message, self>|null */
    private static ?WeakMap $results = null;

    /** @param list<string> $suppressions recipients Helo dropped because they were suppressed */
    public function __construct(
        public readonly string $messageId,
        public readonly string $status,
        public readonly array $suppressions = [],
    ) {
    }

    public static function fromResponse(Response $response): self
    {
        $messageId = $response->get('messageId');
        $status = $response->get('status');
        $suppressions = $response->get('suppressions') ?? [];

        if (! is_string($messageId) || $messageId === '' || ! in_array($status, ['accepted', 'delayed'], true)
            || ! is_array($suppressions) || ! array_is_list($suppressions)
            || count(array_filter($suppressions, 'is_string')) !== count($suppressions)) {
            throw new HeloException('Helo returned an invalid send result.');
        }

        return new self($messageId, $status, $suppressions);
    }

    /** Read the result of a Helo API send; null when the mail didn't go through Helo. */
    public static function from(LaravelSentMessage|SentMessage|Message $source): ?self
    {
        $message = match (true) {
            $source instanceof LaravelSentMessage => $source->getSymfonySentMessage()->getOriginalMessage(),
            $source instanceof SentMessage => $source->getOriginalMessage(),
            default => $source,
        };

        return $message instanceof Message ? self::$results[$message] ?? null : null;
    }

    /** Record this as the result of sending $message. */
    public function attachTo(Message $message): void
    {
        self::$results ??= new WeakMap;
        self::$results[$message] = $this;
    }

    public function isDelayed(): bool
    {
        return $this->status === 'delayed';
    }

    /** @return array{messageId: string, status: string, suppressions: list<string>} */
    public function toArray(): array
    {
        return ['messageId' => $this->messageId, 'status' => $this->status, 'suppressions' => $this->suppressions];
    }
}
