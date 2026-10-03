<?php

namespace STS\HeloEmail\Tests;

use Illuminate\Support\Facades\Http;
use STS\HeloEmail\Facades\Helo;
use STS\HeloEmail\HeloClient;
use STS\HeloEmail\HeloException;

class HeloClientTest extends TestCase
{
    public function testSendsAuthenticatedJsonRequestsAndDecodesTheResponse(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['id' => 'abc'])]);

        $json = (new HeloClient('api-key'))->request('POST', '/channels', ['skip' => null], ['name' => 'Main'], ['X-Empty' => '', 'X-Kept' => 'yes']);

        $this->assertSame(['id' => 'abc'], $json);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/channels'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer api-key')
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('X-Kept', 'yes')
            && ! $request->hasHeader('X-Empty')
            && $request->data() === ['name' => 'Main']);
    }

    public function testSendsQueryParameters(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['results' => []])]);

        (new HeloClient('api-key'))->request('GET', '/domains', ['limit' => 10, 'name' => 'example.com']);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/domains?limit=10&name=example.com');
    }

    public function testUsesTheConfiguredBaseUrl(): void
    {
        Http::fake(['helo.test/*' => Http::response([])]);

        (new HeloClient('api-key', baseUrl: 'https://helo.test'))->request('GET', '/channels');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://helo.test/channels'));
    }

    public function testThrowsHelosErrorWithCodeAndDetail(): void
    {
        // Captured from the live API.
        Http::fake(['api.helohq.com/*' => Http::response([
            'title' => 'Send request failed', 'status' => 422,
            'code' => 'recipients_suppressed', 'detail' => 'All recipients are suppressed.',
        ], 422)]);

        try {
            (new HeloClient('api-key'))->request('POST', '/send/transactional', json: ['to' => []]);
            $this->fail('Expected a HeloException.');
        } catch (HeloException $exception) {
            $this->assertSame('Helo API error 422 (recipients_suppressed): All recipients are suppressed.', $exception->getMessage());
            $this->assertSame(422, $exception->status());
            $this->assertSame('recipients_suppressed', $exception->errorCode());
            $this->assertSame('All recipients are suppressed.', $exception->detail());
            $this->assertSame(422, $exception->response()?->status());
        }
    }

    public function testDescribesAnErrorWithoutABody(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response('<html>Bad gateway</html>', 502)]);

        $this->expectException(HeloException::class);
        $this->expectExceptionMessage('Helo API error 502: no error detail');

        (new HeloClient('api-key'))->request('GET', '/channels');
    }

    public function testRejectsANonJsonSuccessResponse(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response('<html>Captive portal</html>', 200)]);

        $this->expectException(HeloException::class);
        $this->expectExceptionMessage('Helo returned a response that is not JSON.');

        (new HeloClient('api-key'))->request('GET', '/channels');
    }

    public function testRejectsAnEmptySuccessResponse(): void
    {
        // Only Helo's 204s have no body; an empty 200 is not a success.
        foreach (['', '  '] as $body) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake(['api.helohq.com/*' => Http::response($body, 200)]);

            try {
                (new HeloClient('api-key'))->request('POST', '/channels', json: ['name' => 'x']);
                $this->fail('Expected a HeloException for an empty 200.');
            } catch (HeloException $exception) {
                $this->assertSame('Helo returned a response that is not JSON.', $exception->getMessage());
            }
        }
    }

    public function testReturnsAnEmptyArrayForNoContent(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(null, 204)]);

        $this->assertSame([], (new HeloClient('api-key'))->request('DELETE', '/webhooks/abc'));
    }

    public function testFailsBeforeSendingWhenNoKeyIsSet(): void
    {
        $this->expectException(HeloException::class);
        $this->expectExceptionMessage('Set HELO_API_KEY before calling the Helo API.');

        try {
            (new HeloClient(null))->request('GET', '/channels');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function testForChannelLeavesTheOriginalClientAlone(): void
    {
        $client = new HeloClient('api-key', 'channel-a');
        $other = $client->forChannel('channel-b');

        $this->assertSame('channel-a', $client->channelId());
        $this->assertSame('channel-b', $other->channelId());
        $this->assertNotSame($client, $other);
    }

    public function testForChannelCanUseThatChannelsKey(): void
    {
        // Each Helo API key belongs to one channel, so another channel needs its own key.
        Http::fake(['api.helohq.com/*' => Http::response(['totalCount' => 0, 'results' => []])]);
        $client = new HeloClient('api-key', 'channel-a');

        $client->forChannel('channel-b', 'channel-b-key')->suppressions()->list();
        $client->forChannel('channel-c')->suppressions()->list();

        Http::assertSent(fn ($request) => $request['channelId'] === 'channel-b' && $request->hasHeader('Authorization', 'Bearer channel-b-key'));
        Http::assertSent(fn ($request) => $request['channelId'] === 'channel-c' && $request->hasHeader('Authorization', 'Bearer api-key'));
    }

    public function testTheFacadeResolvesTheConfiguredSingleton(): void
    {
        $this->assertInstanceOf(HeloClient::class, Helo::getFacadeRoot());
        $this->assertSame('channel-id', Helo::channelId());
        $this->assertSame(app(HeloClient::class), app(HeloClient::class));
    }

    public function testBootsWithoutAKey(): void
    {
        // config:cache and test suites that never send must not fail.
        config(['helo.key' => null]);
        $this->app->forgetInstance(HeloClient::class);

        $this->assertInstanceOf(HeloClient::class, app(HeloClient::class));
    }
}
