<?php

/*
 * Optional check against a real Helo account; CI never runs it.
 *
 *   HELO_API_KEY=... HELO_CHANNEL_ID=... HELO_FROM=you@your-domain HELO_TO=you@example.com \
 *     php scripts/live-check.php
 *
 * It sends one email and lists the first page of suppressions.
 */

use Illuminate\Support\Facades\Mail;
use Orchestra\Testbench\Foundation\Application;
use STS\HeloEmail\Facades\Helo;
use STS\HeloEmail\HeloEmailServiceProvider;
use STS\HeloEmail\HeloResult;

require __DIR__.'/../vendor/autoload.php';

foreach (['HELO_API_KEY', 'HELO_CHANNEL_ID', 'HELO_FROM', 'HELO_TO'] as $name) {
    if (! getenv($name)) {
        fwrite(STDERR, "Set {$name}.\n");
        exit(1);
    }
}

$app = Application::create(options: ['extra' => ['providers' => [HeloEmailServiceProvider::class]]]);
$app['config']->set('helo.key', getenv('HELO_API_KEY'));
$app['config']->set('helo.channel_id', getenv('HELO_CHANNEL_ID'));
$app['config']->set('mail.default', 'helo');

$sent = Mail::raw('laravel-helo-email live check', fn ($message) => $message
    ->from(getenv('HELO_FROM'))->to(getenv('HELO_TO'))->subject('laravel-helo-email live check'));

echo 'Sent: '.HeloResult::from($sent)?->messageId.PHP_EOL;
echo 'Suppressions on the first page: '.Helo::suppressions()->list()->count().PHP_EOL;
