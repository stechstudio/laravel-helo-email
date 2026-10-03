<?php

namespace STS\HeloEmail\Tests\Resources;

use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use STS\HeloEmail\Facades\Helo;
use STS\HeloEmail\Tests\TestCase;

class StatisticsTest extends TestCase
{
    public function testSendsDailyRangesAsDatesAndHourlyRangesAsTimes(): void
    {
        Http::fake(['api.helohq.com/statistics/*' => Http::response(['results' => []])]);

        Helo::statistics()->daily(new DateTimeImmutable('2026-10-01 15:30', new \DateTimeZone('UTC')), '2026-10-03', timezone: 'America/New_York');
        Helo::statistics()->hourly(new DateTimeImmutable('2026-10-01T00:00:00+00:00'), new DateTimeImmutable('2026-10-02T00:00:00+00:00'));

        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/statistics/daily?channelId=channel-id&from=2026-10-01&to=2026-10-03&timezone=America%2FNew_York');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/statistics/hourly?channelId=channel-id&from=2026-10-01T00%3A00%3A00%2B00%3A00&to=2026-10-02T00%3A00%3A00%2B00%3A00');
    }

    public function testReadsTotals(): void
    {
        Http::fake(['api.helohq.com/statistics/totals*' => Http::response(['transactional' => ['delivered' => 12], 'broadcast' => ['delivered' => 3]])]);

        $totals = Helo::statistics()->totals('2026-10-01T00:00:00Z', '2026-10-02T00:00:00Z', tags: ['receipt']);

        $this->assertSame(12, $totals->transactional['delivered']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'tags=receipt'));
    }
}
