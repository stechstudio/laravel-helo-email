<?php

namespace STS\HeloEmail\Tests\Resources;

use Illuminate\Support\Facades\Http;
use STS\HeloEmail\Facades\Helo;
use STS\HeloEmail\Tests\TestCase;

class ChannelsTest extends TestCase
{
    public function testCreatesAChannelWithOptionalTracking(): void
    {
        Http::fake(['api.helohq.com/channels' => Http::response(['id' => 'ch-1', 'name' => 'ABC Construction'])]);

        $this->assertSame('ch-1', Helo::channels()->create('ABC Construction', trackOpens: true)->id);
        Helo::channels()->create('Sandbox', deliveryType: 'sandbox');

        Http::assertSent(fn ($request) => $request->data() === ['name' => 'ABC Construction', 'deliveryType' => 'live', 'tracking' => ['opens' => true]]);
        Http::assertSent(fn ($request) => $request->data() === ['name' => 'Sandbox', 'deliveryType' => 'sandbox']);
    }

    public function testListsGetsUpdatesAndDeletes(): void
    {
        Http::fake([
            'api.helohq.com/channels/ch-1' => Http::response(['id' => 'ch-1', 'name' => 'Renamed']),
            'api.helohq.com/channels*' => Http::response(['totalCount' => 1, 'results' => [['id' => 'ch-1']]]),
        ]);

        $this->assertSame(1, Helo::channels()->list(name: 'ABC', deliveryType: 'live')->total());
        $this->assertSame('Renamed', Helo::channels()->get('ch-1')->name);
        Helo::channels()->update('ch-1', ['name' => 'Renamed']);
        Helo::channels()->delete('ch-1');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/channels?name=ABC&deliveryType=live&limit=100&offset=0');
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request->data() === ['name' => 'Renamed']);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    }
}
