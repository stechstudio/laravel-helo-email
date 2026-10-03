<?php

namespace STS\HeloEmail\Facades;

use Illuminate\Support\Facades\Facade;
use STS\HeloEmail\HeloClient;

/**
 * @method static string|null channelId()
 * @method static HeloClient forChannel(?string $channelId)
 * @method static \STS\HeloEmail\Resources\Suppressions suppressions()
 * @method static \STS\HeloEmail\Resources\Webhooks webhooks()
 * @method static \STS\HeloEmail\Resources\Channels channels()
 * @method static \STS\HeloEmail\Resources\Domains domains()
 * @method static \STS\HeloEmail\Resources\Broadcasts broadcasts()
 * @method static \STS\HeloEmail\Resources\Activity activity()
 * @method static \STS\HeloEmail\Resources\Statistics statistics()
 * @method static array<mixed> request(string $method, string $path, array<string, mixed> $query = [], array<mixed>|null $json = null, array<string, string|null> $headers = [])
 *
 * @see HeloClient
 */
class Helo extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HeloClient::class;
    }
}
