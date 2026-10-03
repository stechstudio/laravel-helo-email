<?php

namespace STS\HeloEmail\Tests\Resources;

use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use STS\HeloEmail\Facades\Helo;
use STS\HeloEmail\Tests\TestCase;

class ActivityTest extends TestCase
{
    public function testWalksEventsByCursorWithEncodedFilters(): void
    {
        Http::fake(['api.helohq.com/activity/events*' => Http::sequence()
            ->push(['after' => 42, 'totalCount' => 2, 'results' => [['eventType' => 'email-delivered']]])
            ->push(['after' => 43, 'totalCount' => 2, 'results' => [['eventType' => 'email-opened']]])
            ->push(['totalCount' => 2, 'results' => []])]);

        $types = Helo::activity()->events(
            recipient: 'a@example.com',
            eventTypes: ['email-delivered', 'email-opened'],
            startDate: new DateTimeImmutable('2026-10-01T00:00:00+00:00'),
        )->lazy()->pluck('eventType')->all();

        $this->assertSame(['email-delivered', 'email-opened'], $types);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/activity/events?channelId=channel-id&recipient=a%40example.com&eventTypes=email-delivered%2Cemail-opened&startDate=2026-10-01T00%3A00%3A00%2B00%3A00&limit=100');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '&after=42'));
        Http::assertSentCount(3);
    }

    public function testListsMessagesAndReadsOneMessage(): void
    {
        Http::fake([
            'api.helohq.com/activity/messages/m-1' => Http::response(['id' => 'm-1', 'events' => [['eventType' => 'email-delivered']]]),
            'api.helohq.com/activity/messages*' => Http::response(['totalCount' => 1, 'results' => [['id' => 'm-1']]]),
        ]);

        $this->assertSame('m-1', Helo::activity()->messages(status: 'sent', tags: ['receipt'])->items()->first()->id);
        $this->assertSame('email-delivered', Helo::activity()->message('m-1')->events[0]['eventType']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'status=sent') && str_contains($request->url(), 'tags=receipt'));
    }
}
