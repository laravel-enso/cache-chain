<?php

namespace LaravelEnso\CacheChain\Extensions;

use Illuminate\Cache\RetrievesMultipleKeys;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\InteractsWithTime;
use LaravelEnso\CacheChain\Exceptions\Chain as Exception;

class Chain extends TaggableStore implements LockProvider
{
    use InteractsWithTime, RetrievesMultipleKeys;

    private Collection $providers;
    private int $ttl;

    public function __construct(array $providers, int $ttl)
    {
        $this->providers($providers);

        $this->ttl = $ttl;
    }

    public function get($key)
    {
        return $this->cacheGet($key);
    }

    public function put($key, $value, $seconds)
    {
        return $this->handle('put', ...func_get_args());
    }

    public function touch($key, $seconds)
    {
        return $this->handle('touch', $key, $seconds);
    }

    public function increment($key, $value = 1)
    {
        $current = $this->cacheGet($key);
        $new = $current + $value;

        return $this->handleWithSync('increment', $key, $value, $new);
    }

    public function decrement($key, $value = 1)
    {
        $current = $this->cacheGet($key);
        $new = $current - $value;

        return $this->handleWithSync('decrement', $key, $value, $new);
    }

    public function forever($key, $value)
    {
        return $this->handle('forever', ...func_get_args());
    }

    public function forget($key)
    {
        return $this->handle('forget', ...func_get_args());
    }

    public function flush()
    {
        return $this->handle('flush', ...func_get_args());
    }

    public function getPrefix()
    {
        return '';
    }

    public function providers(array $providers)
    {
        throw_if(empty($providers), Exception::providers());

        $this->providers = Collection::wrap($providers)
            ->map(fn ($provider) => $this->store($provider));
    }

    public function restoreLock($name, $owner)
    {
        return $this->lockProvider()->restoreLock($name, $owner);
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        return $this->lockProvider()->lock($name, $seconds, $owner);
    }

    private function handleWithSync($method, $key, $value, $new)
    {
        return $this->providers
            ->map(fn ($provider) => $provider->has($key)
                ? $provider->{$method}($key, $value)
                : $provider->{$method}($key, $new))
            ->last();
    }

    private function handle($method, ...$args)
    {
        return $this->providers
            ->map(fn ($provider) => $provider->{$method}(...$args))
            ->last();
    }

    private function cacheGet($key, int $layer = 0)
    {
        if ($layer >= $this->providers->count()) {
            return;
        }

        $cachedValue = $this->providers->get($layer)->get($key);

        if ($cachedValue !== null) {
            return $cachedValue;
        }

        $cachedValue = $this->cacheGet($key, $layer + 1);

        if ($cachedValue !== null) {
            if ($this->ttl > 0) {
                $this->providers->get($layer)->put($key, $cachedValue, $this->ttl);
            } else {
                $this->providers->get($layer)->forever($key, $cachedValue);
            }
        }

        return $cachedValue;
    }

    private function store($provider)
    {
        return $provider instanceof Repository
            ? $provider
            : Cache::store($provider);
    }

    private function lockProvider(): Repository
    {
        return $this->providers
            ->reverse()
            ->filter(fn ($provider) => $provider->getStore() instanceof LockProvider)
            ->whenEmpty(fn () => throw Exception::lockProvider())
            ->first();
    }
}
