<?php

namespace STS\HeloEmail\Tests;

use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use STS\HeloEmail\Events\HeloMessageSent;
use STS\HeloEmail\HeloEmailServiceProvider;
use STS\HeloEmail\Mail\HeloTransport;

class MailerRegistrationTest extends TestCase
{
    public function testMailMailerHeloWorksWithoutAMailConfigEntry(): void
    {
        config(['mail.default' => 'helo']);
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => 'helo-id'])]);

        $sent = Mail::raw('Hello', fn ($message) => $message->to('a@example.com')->from('sender@example.com')->subject('Hi'));

        $this->assertSame(['transport' => 'helo'], config('mail.mailers.helo'));
        $this->assertInstanceOf(HeloTransport::class, Mail::mailer('helo')->getSymfonyTransport());
        $this->assertSame('helo-id', $sent->getMessageId());
        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/send/transactional'
            && $request->hasHeader('Authorization', 'Bearer api-key')
            && $request->hasHeader('X-Helo-Channel-Id', 'channel-id'));
    }

    public function testAnAppsOwnMailerEntryWins(): void
    {
        config(['mail.mailers.helo' => ['transport' => 'helo', 'key' => 'mail-key', 'channel_id' => 'mail-channel', 'mail_type' => 'broadcast']]);
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => 'helo-id'])]);

        Mail::mailer('helo')->raw('Hello', fn ($message) => $message->to('a@example.com')->from('sender@example.com'));

        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/send/broadcast/message'
            && $request->hasHeader('Authorization', 'Bearer mail-key')
            && $request->hasHeader('X-Helo-Channel-Id', 'mail-channel'));
    }

    public function testAMailerCanClearTheDefaultChannel(): void
    {
        config(['mail.mailers.helo' => ['transport' => 'helo', 'channel_id' => null]]);
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => 'helo-id'])]);

        Mail::mailer('helo')->raw('Hello', fn ($message) => $message->to('a@example.com')->from('sender@example.com'));

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer api-key')
            && ! $request->hasHeader('X-Helo-Channel-Id'));
    }

    public function testKeepsAMailerTheAppDefinedBeforeTheProviderRegistered(): void
    {
        // As when config/mail.php defines its own helo mailer.
        config(['mail.mailers.helo' => ['transport' => 'helo', 'mail_type' => 'broadcast']]);
        (new HeloEmailServiceProvider($this->app))->register();
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => 'helo-id'])]);

        Mail::mailer('helo')->raw('Hello', fn ($message) => $message->to('a@example.com')->from('sender@example.com'));

        $this->assertSame(['transport' => 'helo', 'mail_type' => 'broadcast'], config('mail.mailers.helo'));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/send/broadcast/message');
    }

    public function testExtendsAMailManagerResolvedBeforeTheProviderBoots(): void
    {
        // Bound as an instance, so no resolving callback has extended it yet.
        $manager = new MailManager($this->app);
        $this->app->instance('mail.manager', $manager);

        (new HeloEmailServiceProvider($this->app))->boot();

        $this->assertInstanceOf(HeloTransport::class, $manager->mailer('helo')->getSymfonyTransport());
    }

    public function testFiresHeloMessageSentWithTheResult(): void
    {
        Event::fake([HeloMessageSent::class]);
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => 'helo-id', 'suppressions' => ['b@example.com']])]);

        Mail::mailer('helo')->raw('Hello', fn ($message) => $message->to(['a@example.com', 'b@example.com'])->from('sender@example.com'));

        Event::assertDispatched(HeloMessageSent::class, fn (HeloMessageSent $event) => $event->result->messageId === 'helo-id'
            && $event->result->suppressions === ['b@example.com']
            && $event->sent->getMessageId() === 'helo-id');
    }

    public function testDoesNotFireForOtherMailers(): void
    {
        Event::fake([HeloMessageSent::class]);

        Mail::mailer('array')->raw('Hello', fn ($message) => $message->to('a@example.com')->from('sender@example.com'));

        Event::assertNotDispatched(HeloMessageSent::class);
    }
}
