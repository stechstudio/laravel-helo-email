<?php

namespace STS\HeloEmail\Mail;

use Illuminate\Http\Client\HttpClientException;
use STS\HeloEmail\HeloClient;
use STS\HeloEmail\HeloException;
use STS\HeloEmail\HeloResult;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

class HeloTransport extends AbstractTransport
{
    /** Headers Helo builds itself, plus the package's own. */
    protected const SKIPPED_HEADERS = [
        'from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'subject', 'content-type',
        'content-transfer-encoding', 'mime-version', 'x-helo-idempotency-key',
        'x-helo-trackopens', 'x-helo-tracklinks', 'x-helo-result',
    ];

    public function __construct(protected HeloClient $client, protected string $mailType = 'transactional')
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            throw new TransportException('Helo requires a MIME message.');
        }

        $email = MessageConverter::toEmail($original);
        $payload = $this->payload($email, $message->getEnvelope());
        $idempotencyKey = $email->getHeaders()->get('X-Helo-Idempotency-Key')?->getBodyAsString();

        try {
            $response = match ($this->mailType) {
                'transactional' => $this->client->send()->transactional($payload, $idempotencyKey),
                'broadcast' => $this->client->send()->broadcastMessage($payload, $idempotencyKey),
                default => throw new TransportException('Helo mail_type must be transactional or broadcast.'),
            };
            $result = HeloResult::fromResponse($response);
        } catch (HeloException|HttpClientException $exception) {
            // Laravel's failover mailer recognizes TransportException.
            throw new TransportException('Helo send failed: '.$exception->getMessage(), 0, $exception);
        }

        $message->setMessageId($result->messageId);

        // Added after the API call, so it never goes out on the wire. Written on
        // the original: converting a plain MIME message produces a new Email.
        $original->getHeaders()->remove(HeloResult::HEADER);
        $original->getHeaders()->addTextHeader(HeloResult::HEADER, json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    protected function payload(Email $email, Envelope $envelope): array
    {
        // Prepare inline attachments first, so Symfony resolves cid:name to
        // the same Content-ID we send in the attachment object.
        $email = MessageConverter::toEmail(new Message($email->getHeaders(), $email->getBody()));

        // Send each envelope recipient once, in its most visible header role.
        // Recipients missing from every header (a custom envelope) go to To.
        $listed = fn (array $addresses) => array_map(fn (Address $address) => strtolower($address->getAddress()), $addresses);
        [$toHeader, $ccHeader, $bccHeader] = [$listed($email->getTo()), $listed($email->getCc()), $listed($email->getBcc())];
        $roles = ['to' => [], 'cc' => [], 'bcc' => []];

        foreach ($envelope->getRecipients() as $recipient) {
            $key = strtolower($recipient->getAddress());
            $role = match (true) {
                in_array($key, $toHeader, true) => 'to',
                in_array($key, $ccHeader, true) => 'cc',
                in_array($key, $bccHeader, true) => 'bcc',
                default => 'to',
            };
            $roles[$role][$key] ??= $this->address($recipient);
        }

        $payload = [
            'from' => $this->address($email->getFrom()[0] ?? $envelope->getSender()),
            'to' => array_values($roles['to']),
            'cc' => array_values($roles['cc']),
            'bcc' => array_values($roles['bcc']),
            'replyTo' => array_map($this->address(...), $email->getReplyTo()),
            'subject' => $email->getSubject(),
            'html' => $this->body($email->getHtmlBody()),
            'text' => $this->body($email->getTextBody()),
        ];

        foreach ($email->getAttachments() as $attachment) {
            $item = [
                'fileName' => $attachment->getFilename() ?? 'attachment',
                'contentType' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
                'content' => base64_encode($attachment->getBody()),
                'disposition' => $attachment->getDisposition(),
            ];
            if ($attachment->getDisposition() === 'inline') {
                $item['contentId'] = $attachment->getContentId();
            }
            $payload['attachments'][] = $item;
        }

        $skip = self::SKIPPED_HEADERS;
        // A fresh Message-ID on each attempt would make a retry a different
        // request, and Helo would reject the reused idempotency key.
        if ($email->getHeaders()->has('X-Helo-Idempotency-Key')) {
            $skip[] = 'message-id';
        }

        foreach ($email->getHeaders()->all() as $header) {
            $name = strtolower($header->getName());

            if ($header instanceof TagHeader) {
                $payload['tags'][] = $header->getBodyAsString();
            } elseif ($header instanceof MetadataHeader) {
                $payload['metadata'][$header->getKey()] = $header->getBodyAsString();
            } elseif ($name === 'x-helo-trackopens' || $name === 'x-helo-tracklinks') {
                $payload['tracking'][$name === 'x-helo-trackopens' ? 'opens' : 'links'] = filter_var($header->getBodyAsString(), FILTER_VALIDATE_BOOL);
            } elseif (! in_array($name, $skip, true)) {
                $payload['headers'][$header->getName()] = $header->getBodyAsString();
            }
        }

        // Helo requires "to", even when it's empty (a Bcc-only message).
        return array_filter($payload, fn ($value, $key) => $key === 'to' || ($value !== null && $value !== []), ARRAY_FILTER_USE_BOTH);
    }

    /** @return array{email: string, name: string} */
    protected function address(Address $address): array
    {
        return ['email' => $address->getAddress(), 'name' => $address->getName()];
    }

    /** @param resource|string|null $body */
    protected function body($body): ?string
    {
        if (is_resource($body)) {
            rewind($body);

            return stream_get_contents($body) ?: null;
        }

        return $body;
    }

    public function __toString(): string
    {
        return 'helo';
    }
}
