<?php

namespace LaravelEnso\CacheChain;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use LaravelEnso\CacheChain\Extensions\Chain;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        $chain = fn ($app, $config) => Cache::repository(new Chain(
            $config['providers'],
            $config['defaultTTL'] ?? 0
        ));

        $this->app->booting(fn () => Cache::extend('chain', $chain));
    }
}
