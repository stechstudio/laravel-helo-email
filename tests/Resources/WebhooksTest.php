<?php

namespace STS\HeloEmail\Tests\Resources;

use Illuminate\Support\Facades\Http;
use STS\HeloEmail\Facades\Helo;
use STS\HeloEmail\HeloException;
use STS\HeloEmail\Tests\TestCase;

class WebhooksTest extends TestCase
{
    public function testCreatesAWebhookForTheConfiguredChannel(): void
    {
        Http::fake(['api.helohq.com/webhooks' => Http::response(['id' => 'wh-1', 'payloadSigningKey' => 'secret'])]);

        $webhook = Helo::webhooks()->create('https://app.example/webhooks/helo', ['email-delivered', 'email-bounced'], additionalHeaders: ['Tenant' => 'abc']);

        $this->assertSame('secret', $webhook->payloadSigningKey);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->data() === [
            'url' => 'https://app.example/webhooks/helo',
            'events' => ['email-delivered', 'email-bounced'],
            'channelId' => 'channel-id',
            'additionalHeaders' => [['name' => 'Tenant', 'value' => 'abc']],
            'enabled' => true,
        ]);
    }

    public function testCreatingAWebhookRequiresAChannel(): void
    {
        Http::fake();

        $this->expectException(HeloException::class);
        $this->expectExceptionMessage('HELO_CHANNEL_ID');

        Helo::forChannel(null)->webhooks()->create('https://app.example/webhooks/helo', ['email-delivered']);
    }

    public function testListsGetsUpdatesDeletesAndRegeneratesKeys(): void
    {
        Http::fake([
            'api.helohq.com/webhooks/wh-1/regenerate-signing-key' => Http::response(['id' => 'wh-1', 'payloadSigningKey' => 'new']),
            'api.helohq.com/webhooks/wh-1' => Http::response(['id' => 'wh-1', 'enabled' => false]),
            'api.helohq.com/webhooks*' => Http::response(['totalCount' => 1, 'results' => [['id' => 'wh-1']]]),
        ]);

        $this->assertSame('wh-1', Helo::webhooks()->list(channelIds: ['a', 'b'])->items()->first()->id);
        $this->assertSame('wh-1', Helo::webhooks()->get('wh-1')->id);
        $this->assertFalse(Helo::webhooks()->update('wh-1', ['enabled' => false, 'channelId' => null])->enabled);
        Helo::webhooks()->delete('wh-1');
        $this->assertSame('new', Helo::webhooks()->regenerateSigningKey('wh-1')->payloadSigningKey);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'channelIds=a%2Cb'));
        // Only the given fields change; a null channelId removes channel scoping.
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request->data() === ['enabled' => false, 'channelId' => null]);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === 'https://api.helohq.com/webhooks/wh-1');
    }
}
