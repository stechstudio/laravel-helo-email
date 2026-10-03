<?php

return [
    // Helo → API credentials. A key limited to one channel needs no channel_id
    // for sending, but suppressions, broadcasts, and webhooks always need one.
    'key' => env('HELO_API_KEY'),
    'channel_id' => env('HELO_CHANNEL_ID'),

    // "transactional" or "broadcast". A mailer entry can override it.
    'mail_type' => env('HELO_MAIL_TYPE', 'transactional'),

    'base_url' => env('HELO_BASE_URL', 'https://api.helohq.com'),

    // Seconds. Sends are never retried, so keep this generous.
    'timeout' => 30,
];
