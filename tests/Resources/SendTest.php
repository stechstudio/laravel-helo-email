<?php

namespace STS\HeloEmail\Tests\Resources;

use Illuminate\Support\Facades\Http;
use STS\HeloEmail\Facades\Helo;
use STS\HeloEmail\HeloException;
use STS\HeloEmail\Tests\TestCase;

class SendTest extends TestCase
{
    protected array $message = [
        'from' => ['email' => 'sender@example.com'],
        'to' => [['email' => 'a@example.com']],
        'subject' => 'Hi',
        'html' => '<p>Hi</p>',
    ];

    protected array $broadcast = [
        'from' => ['email' => 'sender@example.com'],
        'template' => ['subject' => 'News for {{name}}', 'html' => '<p>Hi {{name}}</p>'],
        'messages' => [['to' => [['email' => 'a@example.com']], 'variables' => ['name' => 'Ann']]],
    ];

    public function testSendsTransactionalMailWithChannelAndIdempotencyHeaders(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => 'm-1', 'suppressions' => []])]);

        $result = Helo::send()->transactional($this->message, 'order-42');

        $this->assertSame('m-1', $result->messageId);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/send/transactional'
            && $request->hasHeader('X-Helo-Channel-Id', 'channel-id')
            && $request->hasHeader('X-Helo-Idempotency-Key', 'order-42')
            && $request->data() === $this->message);
    }

    public function testSendsBroadcastMessagesBatchesAndBroadcasts(): void
    {
        Http::fake([
            'api.helohq.com/send/transactional/batch' => Http::response(['responses' => [['status' => 'accepted', 'messageId' => 'm-1'], ['status' => 'failed', 'errorCode' => 'invalid_recipient', 'errorMessage' => 'Bad address']]]),
            'api.helohq.com/send/broadcast/message' => Http::response(['status' => 'accepted', 'messageId' => 'm-2']),
            'api.helohq.com/send/broadcast' => Http::response(['status' => 'accepted', 'broadcastId' => 'b-1']),
        ]);

        $this->assertSame('m-2', Helo::send()->broadcastMessage($this->message)->messageId);
        // Batch results arrive per message; a failed one doesn't throw.
        $this->assertSame('failed', Helo::send()->transactionalBatch([$this->message, $this->message])->responses[1]['status']);
        $this->assertSame('b-1', Helo::send()->broadcast($this->broadcast)->broadcastId);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/send/transactional/batch'
            && $request->data() === ['requests' => [$this->message, $this->message]]);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/send/broadcast' && $request->data() === $this->broadcast);
        Http::assertNotSent(fn ($request) => $request->hasHeader('X-Helo-Idempotency-Key'));
    }

    public function testThrowsWhenHeloRefusesASingleSend(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'failed', 'errorCode' => 'invalid_sender', 'errorMessage' => 'Sender domain is not verified.'])]);

        try {
            Helo::send()->transactional($this->message);
            $this->fail('Expected a HeloException.');
        } catch (HeloException $exception) {
            $this->assertSame('Helo did not accept the message (invalid_sender): Sender domain is not verified.', $exception->getMessage());
            $this->assertSame('invalid_sender', $exception->errorCode());
        }
    }

    public function testNormalizesAReplayedIdempotencyResponse(): void
    {
        // Captured from the live API: a replayed key answers in PascalCase.
        Http::fake(['api.helohq.com/*' => Http::response(['Status' => 'accepted', 'MessageId' => 'm-1'])]);

        $result = Helo::send()->transactional($this->message, 'order-42');

        $this->assertSame('accepted', $result->status);
        $this->assertSame('m-1', $result->messageId);
    }

    public function testForChannelSendsThroughAnotherChannel(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => 'm-1'])]);

        Helo::forChannel('tenant-channel')->send()->transactional($this->message);

        Http::assertSent(fn ($request) => $request->hasHeader('X-Helo-Channel-Id', 'tenant-channel'));
    }
}
