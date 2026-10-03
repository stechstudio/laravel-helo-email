<?php

namespace STS\HeloEmail\Tests\Resources;

use Illuminate\Support\Facades\Http;
use STS\HeloEmail\Facades\Helo;
use STS\HeloEmail\HeloException;
use STS\HeloEmail\Tests\TestCase;

class BroadcastsTest extends TestCase
{
    public function testListsBroadcastsAndTheirFailuresAndSuppressions(): void
    {
        Http::fake([
            'api.helohq.com/broadcasts/b-1/failures*' => Http::response(['totalCount' => 1, 'results' => [['errorCode' => 'invalid_address']]]),
            'api.helohq.com/broadcasts/b-1' => Http::response(['id' => 'b-1', 'status' => 'completed']),
            'api.helohq.com/broadcasts*' => Http::response(['totalCount' => 1, 'results' => [['id' => 'b-1']]]),
        ]);

        $this->assertSame('b-1', Helo::broadcasts()->list(status: 'completed')->items()->first()->id);
        $this->assertSame('completed', Helo::broadcasts()->get('b-1')->status);
        $this->assertSame('invalid_address', Helo::broadcasts()->failures('b-1')->items()->first()->errorCode);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/broadcasts?channelId=channel-id&status=completed&limit=100&offset=0');
    }

    public function testBroadcastSuppressionsArePlainAddressesAcrossPages(): void
    {
        // Helo lists a broadcast's suppressed recipients as bare strings.
        Http::fake(['api.helohq.com/broadcasts/b-1/suppressions*' => Http::sequence()
            ->push(['totalCount' => 2, 'results' => ['gone@example.com']])
            ->push(['totalCount' => 2, 'results' => ['left@example.com']])]);

        $emails = Helo::broadcasts()->suppressions('b-1', limit: 1)->lazy()->map(fn ($row) => $row->email)->all();

        $this->assertSame(['gone@example.com', 'left@example.com'], $emails);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'offset=1'));
    }

    public function testExtraParametersPassThrough(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['totalCount' => 0, 'results' => []])]);

        Helo::broadcasts()->failures('b-1', extra: ['errorCode' => 'x']);
        Helo::broadcasts()->suppressions('b-1', extra: ['email' => 'y']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/failures') && $request['errorCode'] === 'x');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/suppressions') && $request['email'] === 'y');
    }

    public function testListingRequiresAChannel(): void
    {
        Http::fake();

        $this->expectException(HeloException::class);
        $this->expectExceptionMessage('HELO_CHANNEL_ID');

        Helo::forChannel(null)->broadcasts()->list();
    }
}
