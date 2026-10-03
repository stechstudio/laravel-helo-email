<?php

namespace STS\HeloEmail;

use Illuminate\Support\ServiceProvider;

class HeloEmailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/helo.php', 'helo');

        $this->app->singleton(HeloClient::class, fn ($app) => new HeloClient(
            $app['config']->get('helo.key'),
            $app['config']->get('helo.channel_id'),
            $app['config']->get('helo.base_url', 'https://api.helohq.com'),
            (int) $app['config']->get('helo.timeout', 30),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/helo.php' => config_path('helo.php')], 'helo-config');
        }
    }
}
