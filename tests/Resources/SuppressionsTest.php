<?php

namespace STS\HeloEmail\Tests\Resources;

use Illuminate\Support\Facades\Http;
use STS\HeloEmail\Facades\Helo;
use STS\HeloEmail\HeloException;
use STS\HeloEmail\Tests\TestCase;

class SuppressionsTest extends TestCase
{
    public function testListsTheConfiguredChannelAndWalksEveryPage(): void
    {
        Http::fake(['api.helohq.com/suppressions*' => Http::sequence()
            ->push(['totalCount' => 2, 'results' => [['email' => 'a@example.com', 'reason' => 'bounce', 'createdAt' => '2026-09-20T12:00:00Z']]])
            ->push(['totalCount' => 2, 'results' => [['email' => 'b@example.com', 'reason' => 'unsubscribe', 'createdAt' => '2026-09-21T12:00:00Z']]])]);

        $emails = Helo::suppressions()->list(mailType: 'broadcast', reason: 'bounce', limit: 1)->lazy()->pluck('email')->all();

        $this->assertSame(['a@example.com', 'b@example.com'], $emails);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/suppressions?channelId=channel-id&mailType=broadcast&reason=bounce&limit=1&offset=0');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'offset=1'));
    }

    public function testAnExplicitChannelWins(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['totalCount' => 0, 'results' => []])]);

        Helo::suppressions()->list(channelId: 'other-channel');

        Http::assertSent(fn ($request) => $request['channelId'] === 'other-channel' && $request['mailType'] === 'transactional');
    }

    public function testAddsAndRemovesAndReturnsPerAddressResults(): void
    {
        Http::fake([
            'api.helohq.com/suppressions/remove' => Http::response(['results' => [['email' => 'a@example.com', 'success' => false, 'message' => 'Cannot remove a complaint.']]]),
            'api.helohq.com/suppressions' => Http::response(['results' => [['email' => 'a@example.com', 'success' => true]]]),
        ]);

        $added = Helo::suppressions()->add(['a@example.com'], 'broadcast');
        // A rejected removal arrives inside HTTP 200; the caller decides what it means.
        $removed = Helo::suppressions()->remove(['a@example.com']);

        $this->assertTrue($added->results[0]['success']);
        $this->assertFalse($removed->results[0]['success']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/suppressions'
            && $request->data() === ['channelId' => 'channel-id', 'mailType' => 'broadcast', 'emails' => ['a@example.com']]);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/suppressions/remove'
            && $request->data() === ['channelId' => 'channel-id', 'mailType' => 'transactional', 'emails' => ['a@example.com']]);
    }

    public function testExtraParametersPassThrough(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['totalCount' => 0, 'results' => []])]);

        Helo::suppressions()->list(extra: ['newFilter' => 'x']);
        Helo::suppressions()->add(['a@example.com'], extra: ['note' => 'manual']);
        Helo::suppressions()->remove(['a@example.com'], extra: ['note' => 'manual']);

        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request['newFilter'] === 'x');
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/suppressions' && $request['note'] === 'manual');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/suppressions/remove' && $request['note'] === 'manual');
    }

    public function testRequiresAChannel(): void
    {
        Http::fake();
        $suppressions = Helo::forChannel(null)->suppressions();

        foreach ([fn () => $suppressions->list(), fn () => $suppressions->add(['a@example.com']), fn () => $suppressions->remove(['a@example.com'])] as $call) {
            try {
                $call();
                $this->fail('Expected a HeloException');
            } catch (HeloException $exception) {
                $this->assertStringContainsString('HELO_CHANNEL_ID', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }
}
