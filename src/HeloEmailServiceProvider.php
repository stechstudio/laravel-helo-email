<?php

namespace STS\HeloEmail;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\ServiceProvider;
use STS\HeloEmail\Events\HeloMessageSent;
use STS\HeloEmail\Mail\HeloTransport;

class HeloEmailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/helo.php', 'helo');

        $this->app->singleton(HeloClient::class, fn ($app) => $this->client($app['config']->get('helo')));

        // MAIL_MAILER=helo works without editing config/mail.php. An app's
        // own "helo" entry always wins.
        if ($this->app['config']->get('mail.mailers.helo') === null) {
            $this->app['config']->set('mail.mailers.helo', ['transport' => 'helo']);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/helo.php' => config_path('helo.php')], 'helo-config');
        }

        $this->callAfterResolving('mail.manager', function ($manager) {
            $manager->extend('helo', function (array $config) {
                $defaults = $this->app['config']->get('helo');

                // A key the mailer sets wins, even when it's null: a mailer
                // with channel_id => null sends without the default channel.
                return new HeloTransport(
                    $this->client(array_replace($defaults, array_intersect_key($config, array_flip(['key', 'channel_id'])))),
                    $config['mail_type'] ?? $defaults['mail_type'] ?? 'transactional',
                );
            });
        });

        $this->app['events']->listen(MessageSent::class, function (MessageSent $event) {
            if ($result = HeloResult::from($event->sent)) {
                $this->app['events']->dispatch(new HeloMessageSent($event->sent, $result));
            }
        });
    }

    /** @param array<string, mixed> $config */
    protected function client(array $config): HeloClient
    {
        return new HeloClient(
            $config['key'] ?? null,
            $config['channel_id'] ?? null,
            $config['base_url'] ?? 'https://api.helohq.com',
            (int) ($config['timeout'] ?? 30),
        );
    }
}
