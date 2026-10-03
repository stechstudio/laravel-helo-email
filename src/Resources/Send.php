<?php

namespace STS\HeloEmail\Resources;

use STS\HeloEmail\HeloException;
use STS\HeloEmail\Response;

/**
 * Message arrays use Helo's request shape: from, to, cc, bcc, replyTo,
 * subject, html, text, attachments, tags, headers, metadata, tracking.
 */
class Send extends Resource
{
    /** @param array<string, mixed> $message */
    public function transactional(array $message, ?string $idempotencyKey = null): Response
    {
        return $this->single('/send/transactional', $message, $idempotencyKey);
    }

    /** @param array<string, mixed> $message */
    public function broadcastMessage(array $message, ?string $idempotencyKey = null): Response
    {
        return $this->single('/send/broadcast/message', $message, $idempotencyKey);
    }

    /**
     * Each message gets its own result in "responses"; a failed one doesn't throw.
     *
     * @param list<array<string, mixed>> $messages
     */
    public function transactionalBatch(array $messages, ?string $idempotencyKey = null): Response
    {
        return new Response($this->post('/send/transactional/batch', ['requests' => $messages], $idempotencyKey));
    }

    /**
     * A whole broadcast. Track it with broadcasts()->get($response->broadcastId).
     *
     * @param array<string, mixed> $broadcast
     */
    public function broadcast(array $broadcast, ?string $idempotencyKey = null): Response
    {
        return new Response($this->post('/send/broadcast', $broadcast, $idempotencyKey));
    }

    /** @param array<string, mixed> $message */
    protected function single(string $path, array $message, ?string $idempotencyKey): Response
    {
        $json = $this->post($path, $message, $idempotencyKey);

        if (($json['status'] ?? null) === 'failed') {
            $code = is_string($json['errorCode'] ?? null) ? $json['errorCode'] : null;

            throw new HeloException(sprintf(
                'Helo did not accept the message (%s): %s',
                $code ?? 'unknown',
                is_string($json['errorMessage'] ?? null) ? $json['errorMessage'] : 'no detail',
            ), errorCode: $code);
        }

        return new Response($json);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<mixed>
     */
    protected function post(string $path, array $body, ?string $idempotencyKey): array
    {
        $json = $this->client->request('POST', $path, json: $body, headers: [
            'X-Helo-Channel-Id' => $this->client->channelId(),
            'X-Helo-Idempotency-Key' => $idempotencyKey,
        ]);

        // A replayed idempotency key answers with PascalCase keys (Status, MessageId).
        return collect($json)->mapWithKeys(fn ($value, $key) => [lcfirst((string) $key) => $value])->all();
    }
}
