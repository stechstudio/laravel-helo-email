<?php

namespace STS\HeloEmail\Tests\Resources;

use Illuminate\Support\Facades\Http;
use STS\HeloEmail\Facades\Helo;
use STS\HeloEmail\Tests\TestCase;

class DomainsTest extends TestCase
{
    public function testCoversEveryDomainOperation(): void
    {
        Http::fake([
            'api.helohq.com/domains/d-1/verify' => Http::response(['domainKeyActive' => ['host' => 'helo1._domainkey.mail.example.com', 'type' => 'txt', 'status' => 'verified'], 'returnPath' => ['host' => 'bounce.mail.example.com', 'type' => 'cname', 'status' => 'pending']]),
            'api.helohq.com/domains/d-1/rotate-key' => Http::response(['host' => 'helo2._domainkey.mail.example.com', 'type' => 'txt', 'value' => 'v=DKIM1; k=rsa; p=abc']),
            'api.helohq.com/domains/d-1' => Http::response(['id' => 'd-1', 'name' => 'mail.example.com']),
            'api.helohq.com/domains' => Http::response(['id' => 'd-1', 'name' => 'mail.example.com']),
            'api.helohq.com/domains*' => Http::response(['totalCount' => 1, 'results' => [['id' => 'd-1']]]),
        ]);

        $this->assertSame('d-1', Helo::domains()->create('mail.example.com', ['ch-1'])->id);
        $this->assertSame(1, Helo::domains()->list(name: 'example.com')->total());
        $this->assertSame('mail.example.com', Helo::domains()->get('d-1')->name);
        Helo::domains()->update('d-1', ['channelIds' => ['ch-1', 'ch-2']]);
        $verified = Helo::domains()->verify('d-1');
        $this->assertSame('verified', $verified->domainKeyActive['status']);
        $this->assertSame('pending', $verified->returnPath['status']);
        $this->assertSame('helo2._domainkey.mail.example.com', Helo::domains()->rotateKey('d-1')->host);
        Helo::domains()->create('other.example.com', extra: ['note' => 'x']);
        Helo::domains()->delete('d-1');

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://api.helohq.com/domains'
            && $request->data() === ['name' => 'mail.example.com', 'channelIds' => ['ch-1']]);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://api.helohq.com/domains/d-1/verify');
        Http::assertSent(fn ($request) => $request->data() === ['name' => 'other.example.com', 'note' => 'x']);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request->data() === ['channelIds' => ['ch-1', 'ch-2']]);
    }
}
