# Laravel Helo Email

Send Laravel mail through [Helo](https://helohq.com)'s API, and call the rest of
Helo's API from your app. Set your mailer to `helo`, add an API key, and your
Mailables, notifications, and `Mail::raw` calls send through Helo.

## Install

```bash
composer require stechstudio/laravel-helo-email
```

Add these lines to `.env`:

```dotenv
MAIL_MAILER=helo
HELO_API_KEY=...
HELO_CHANNEL_ID=...
```

A Helo API key covers either all channels or one. With a key for all channels,
`HELO_CHANNEL_ID` picks the channel to send through. With a key for one
channel, sending works without it, but set it anyway: Helo requires a channel
ID for suppressions, broadcast lists, and new webhooks.

You don't need to change `config/mail.php`. The package adds a `helo` mailer
when your config doesn't define one.

To change the defaults, publish the config:

```bash
php artisan vendor:publish --tag=helo-config
```

| Key | Env | Default |
|---|---|---|
| `key` | `HELO_API_KEY` | none |
| `channel_id` | `HELO_CHANNEL_ID` | none |
| `mail_type` | `HELO_MAIL_TYPE` | `transactional` |
| `base_url` | `HELO_BASE_URL` | `https://api.helohq.com` |
| `timeout` | | 30 seconds |

A missing API key fails on the first request, not at boot, so `config:cache`
and test suites that never send keep working.

## Several channels or mail types

Add a mailer to `config/mail.php` for each extra channel or mail type.
Settings you put there override `config/helo.php`. A key limited to one
channel can't reach another, so a mailer for another channel needs a key for
it, unless your main key covers all channels.

```php
'helo-newsletter' => [
    'transport' => 'helo',
    'key' => env('HELO_NEWSLETTER_API_KEY'),
    'channel_id' => env('HELO_NEWSLETTER_CHANNEL_ID'),
    'mail_type' => 'broadcast',
],
```

To send broadcast mail through your main channel, leave out `key` and
`channel_id` and set only `mail_type`.

```php
Mail::mailer('helo-newsletter')->to($user)->send(new Newsletter);
```

## Sending options

**Tags and metadata.** Laravel's `tag()` and `metadata()` become Helo's tags
and metadata:

```php
(new OrderShipped($order))->tag('receipt')->metadata('order_id', $order->id);
```

**Tracking.** Turn open or link tracking on or off for one message:

```php
$this->withSymfonyMessage(function (Email $message) {
    $message->getHeaders()->addTextHeader('X-Helo-TrackOpens', 'true');
    $message->getHeaders()->addTextHeader('X-Helo-TrackLinks', 'false');
});
```

**Safe retries.** Add an `X-Helo-Idempotency-Key` header with a value that's
unique to the message, such as `order-42-receipt`. Helo sends a message with a
given key only once, so this is the only safe way to retry a send that may
have gone through.

A retry must send exactly the same request, or Helo rejects the key. The
transport leaves out the `Message-ID` header and gives inline images stable
IDs, so rebuilding the same email gives the same request.

**Broadcast mail.** Set `mail_type` to `broadcast` for marketing and bulk mail.
Helo adds its own unsubscribe link and `List-Unsubscribe` headers to every
broadcast message, and you can't point them at your own page. Put
`{{{ helo: unsubscribe }}}` in your template to choose where the link appears.
Without it, Helo adds the link at the end. Transactional mail gets no link.

## After sending

`$sent->getMessageId()` returns Helo's message ID, the same ID Helo's webhooks
carry.

`HeloResult::from($sent)` returns the full result, including any recipients
Helo skipped because they're suppressed:

```php
use STS\HeloEmail\HeloResult;

$sent = Mail::to($user)->send(new Welcome);
$result = HeloResult::from($sent); // null if the mail didn't go through Helo

$result->messageId;
$result->status;       // "accepted" or "delayed"
$result->suppressions; // ["gone@example.com"]
```

The package also fires `STS\HeloEmail\Events\HeloMessageSent` after each send,
with the `sent` message and the `result`.

## API client

The `Helo` facade covers the rest of Helo's API. Methods take named arguments
that match Helo's parameter names.

```php
use STS\HeloEmail\Facades\Helo;

// Send
Helo::send()->transactional([
    'from' => ['email' => 'hello@example.com'],
    'to' => [['email' => 'a@example.com']],
    'subject' => 'Hi',
    'html' => '<p>Hi</p>',
], idempotencyKey: 'welcome-42');
Helo::send()->broadcastMessage($message);
Helo::send()->transactionalBatch([$first, $second]);
Helo::send()->broadcast($broadcast);

// Suppressions: one list per channel and mail type
Helo::suppressions()->list(mailType: 'broadcast', reason: 'bounce');
Helo::suppressions()->add(['a@example.com'], mailType: 'broadcast');
Helo::suppressions()->remove(['a@example.com']);

// Webhooks
$webhook = Helo::webhooks()->create('https://app.example/webhooks/helo', ['email-delivered', 'email-bounced']);
$webhook->payloadSigningKey;
Helo::webhooks()->list();
Helo::webhooks()->update($id, ['enabled' => false]);
Helo::webhooks()->regenerateSigningKey($id);
Helo::webhooks()->delete($id);

// Channels
Helo::channels()->create('ABC Construction', trackOpens: true);
Helo::channels()->list(name: 'ABC');
Helo::channels()->update($id, ['name' => 'ABC Builders']);

// Domains
Helo::domains()->create('mail.example.com', channelIds: [$channelId]);
$check = Helo::domains()->verify($id);
$check->domainKeyActive['status'];
Helo::domains()->rotateKey($id);

// Activity
Helo::activity()->events(recipient: 'a@example.com', eventTypes: ['email-bounced']);
Helo::activity()->messages(status: 'sent', tags: ['receipt']);
Helo::activity()->message($messageId)->events;

// Broadcasts
Helo::broadcasts()->list(status: 'completed');
Helo::broadcasts()->failures($broadcastId);
Helo::broadcasts()->suppressions($broadcastId);

// Statistics
Helo::statistics()->daily('2026-10-01', '2026-10-31', timezone: 'America/New_York');
Helo::statistics()->hourly(now()->subDay(), now());
Helo::statistics()->totals(now()->startOfMonth(), now());
```

Every resource also has `get`, `update`, and `delete` where Helo supports them.

**Responses.** A single object is a `Response`. Read a field as
`$domain->status` or `$domain['status']`. Fields Helo adds later pass through.
Key fields:

| Resource | Fields |
|---|---|
| Send | `status`, `messageId`, `suppressions`; a broadcast returns `broadcastId` |
| Suppression | `email`, `reason`, `createdAt` |
| Webhook | `id`, `url`, `events`, `enabled`, `payloadSigningKey` |
| Channel | `id`, `name`, `deliveryType` |
| Domain verify | `domainKeyActive`, `returnPath`, each with `host`, `type`, `status` |
| Activity | events have `eventType`; messages have `id`, `status`, `events` |
| Broadcast | `id`, `status` |

A batch send or suppression change that Helo rejects for one address comes
back inside a successful response. Check each suppression result's `success`,
or each batch response's `status`. A single send that Helo rejects throws.

**Paging.** A list returns a `Page` with the first page of items. Call
`->lazy()` to walk every page; it fetches the next page only when the loop
needs it.

```php
foreach (Helo::suppressions()->list(mailType: 'broadcast')->lazy() as $suppression) {
    $suppression->email;
}
```

**Other channels.** `Helo::forChannel($id, $key)` returns a client for
another channel, for apps that send for many customers. If your key covers
all channels, the channel ID is enough. If each channel has its own key, pass
that key too.

```php
Helo::forChannel($tenant->helo_channel_id, $tenant->helo_api_key)->suppressions()->list();
```

Suppressions, broadcast lists, and new webhooks need a channel ID, even with
a key for one channel. Pass `channelId:`, use
`forChannel()`, or set `HELO_CHANNEL_ID`.

**New parameters.** Methods that take named parameters also accept `extra:`,
which is merged into the request. A new Helo parameter works before this package
adds it.

```php
Helo::suppressions()->list(extra: ['newFilter' => 'value']);
```

## Errors

A failed request throws `STS\HeloEmail\HeloException`:

- `status()`: the HTTP status
- `errorCode()`: Helo's error code, such as `recipients_suppressed`
- `detail()`: Helo's error message
- `response()`: the Laravel HTTP response

Connection failures throw Laravel's `ConnectionException`.

When Laravel sends mail, any failure becomes a Symfony `TransportException`, so
Laravel's failover mailer works. The package never retries a send on its own,
because a retry could deliver twice. Use an idempotency key to retry safely.

## Testing your app

Fake Helo's API with Laravel's HTTP fake:

```php
Http::fake([
    'api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => 'test-id']),
]);

Mail::to('a@example.com')->send(new Welcome);

Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/send/transactional');
```

## Not included

- **Webhooks.** This package doesn't receive Helo's webhooks. For delivery
  tracking, bounces, and suppression sync, use
  [stechstudio/laravel-postmaster](https://github.com/stechstudio/laravel-postmaster).
- **Use outside Laravel.** The package needs Laravel.

## Live check

`scripts/live-check.php` sends one email through a real Helo account and lists
the first page of suppressions. CI never runs it.

```bash
HELO_API_KEY=... HELO_CHANNEL_ID=... HELO_FROM=you@your-domain HELO_TO=you@example.com \
  php scripts/live-check.php
```

## License

MIT. See [LICENSE.md](LICENSE.md).
