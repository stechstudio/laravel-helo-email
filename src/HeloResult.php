<?php

namespace STS\HeloEmail;

use Illuminate\Mail\SentMessage as LaravelSentMessage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Message;

/**
 * What Helo said about one send. The transport leaves it on the sent message
 * after the API call, so it never goes out on the wire.
 */
final class HeloResult
{
    public const HEADER = 'X-Helo-Result';

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

        $header = $message instanceof Message ? $message->getHeaders()->get(self::HEADER) : null;

        if ($header === null) {
            return null;
        }

        $data = json_decode($header->getBodyAsString(), true, flags: JSON_THROW_ON_ERROR);

        return self::fromResponse(new Response(is_array($data) ? $data : []));
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
