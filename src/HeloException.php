<?php

namespace STS\HeloEmail;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/** A failed Helo API call, or a response the client can't use. */
class HeloException extends RuntimeException
{
    public function __construct(
        string $message,
        protected ?Response $response = null,
        protected ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Build the message from Helo's error body; Laravel's own exception truncates it. */
    public static function fromResponse(Response $response): self
    {
        $code = $response->json('code');
        $detail = $response->json('detail') ?? $response->json('title');

        return new self(
            sprintf(
                'Helo API error %d%s: %s',
                $response->status(),
                is_string($code) ? " ({$code})" : '',
                is_string($detail) ? $detail : 'no error detail',
            ),
            $response,
            is_string($code) ? $code : null,
        );
    }

    public function status(): ?int
    {
        return $this->response?->status();
    }

    /** Helo's error code, such as "recipients_suppressed". */
    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function detail(): ?string
    {
        $detail = $this->response?->json('detail');

        return is_string($detail) ? $detail : null;
    }

    public function response(): ?Response
    {
        return $this->response;
    }
}
