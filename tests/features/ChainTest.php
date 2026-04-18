<?php

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use LaravelEnso\CacheChain\Extensions\Chain;
use LaravelEnso\CacheChain\Exceptions\Chain as Exception;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ChainTest extends TestCase
{
    #[Test]
    public function should_cache_on_all_configured_providers()
    {
        $providers = ['array', 'file'];

        Cache::store('chain')->providers($providers);

        $this->assertTrue(Cache::store('chain')->put('foo', 'bar'));

        Collection::wrap($providers)->each(fn ($provider) => $this
            ->assertEquals('bar', Cache::store($provider)->get('foo')));
    }

    #[Test]
    public function should_get_from_first_layer_when_available()
    {
        Cache::store('chain')->providers(['array', new Repository(Mockery::mock(Store::class))]);

        $this->assertTrue(Cache::store('array')->put('foo', 'bar'));

        $this->assertEquals('bar', Cache::store('chain')->get('foo'));
    }

    #[Test]
    public function should_get_from_superior_layer_when_first_not_available()
    {
        Cache::store('chain')->providers([new Repository(Mockery::spy(Store::class)), 'array']);

        $this->assertTrue(Cache::store('array')->put('foo', 'bar'));

        $this->assertEquals('bar', Cache::store('chain')->get('foo'));
    }

    #[Test]
    public function should_cache_inferior_layers_on_get_when_superior_exists()
    {
        Cache::store('chain')->providers(['array', 'file']);

        Cache::store('file')->put('foo', 'bar');

        $this->assertNull(Cache::store('array')->get('foo'));
        $this->assertEquals('bar', Cache::store('chain')->get('foo'));
        $this->assertEquals('bar', Cache::store('array')->get('foo'));
    }

    #[Test]
    public function should_sync_inferior_layers_when_superior_exists()
    {
        Cache::store('chain')->providers(['array', 'file']);

        Cache::store('file')->put('foo', 5);

        $this->assertEquals(6, Cache::store('chain')->increment('foo'));
        $this->assertEquals(6, Cache::store('array')->get('foo'));
    }

    #[Test]
    public function can_flush()
    {
        $providers = ['file', 'array'];
        Cache::store('chain')->providers($providers);

        Cache::store('chain')->put('foo', 'bar');
        Cache::store('chain')->put('bar', 'foo');
        $this->assertTrue(Cache::store('chain')->flush());

        Collection::wrap($providers)->each(fn ($provider) => tap($this)
            ->assertFalse(Cache::store($provider)->has('foo'))
            ->assertFalse(Cache::store($provider)->has('bar')));
    }

    #[Test]
    public function can_forget()
    {
        $providers = ['file', 'array'];
        Cache::store('chain')->providers($providers);

        Cache::store('chain')->put('foo', 'bar');
        Cache::store('chain')->put('bar', 'foo');
        $this->assertTrue(Cache::store('chain')->forget('foo'));

        Collection::wrap($providers)->each(fn ($provider) => tap($this)
            ->assertFalse(Cache::store($provider)->has('foo'))
            ->assertTrue(Cache::store($provider)->has('bar')));
    }

    #[Test]
    public function can_increment()
    {
        $providers = ['file', 'array'];
        Cache::store('chain')->providers($providers);

        Cache::store('chain')->put('number', 1);
        $this->assertEquals(3, Cache::store('chain')->increment('number', 2));

        Collection::wrap($providers)->each(fn ($provider) => $this
            ->assertEquals(3, Cache::store($provider)->get('number')));
    }

    #[Test]
    public function can_decrement()
    {
        $providers = ['file', 'array'];
        Cache::store('chain')->providers($providers);

        Cache::store('chain')->put('number', 3);
        $this->assertEquals(1, Cache::store('chain')->decrement('number', 2));

        Collection::wrap($providers)->each(fn ($provider) => $this
            ->assertEquals(1, Cache::store($provider)->get('number')));
    }

    #[Test]
    public function can_touch()
    {
        $providers = ['file', 'array'];
        Cache::store('chain')->providers($providers);

        Cache::store('chain')->put('foo', 'bar', 60);

        $this->assertTrue(Cache::store('chain')->touch('foo', 120));

        Collection::wrap($providers)->each(fn ($provider) => $this
            ->assertEquals('bar', Cache::store($provider)->get('foo')));
    }

    public function can_cache_forever_on_all_configured_providers()
    {
        $providers = ['file', 'array'];
        Cache::store('chain')->providers($providers);

        $this->assertTrue(Cache::store('chain')->forever('forever', 'value'));

        Collection::wrap($providers)->each(fn ($provider) => $this
            ->assertEquals('value', Cache::store($provider)->get('forever')));
    }

    #[Test]
    public function backfills_missing_layers_forever_when_default_ttl_is_zero()
    {
        $missingLayer = new Repository(Mockery::mock(Store::class));
        $superior = new Repository(Mockery::mock(Store::class));

        $missingLayer->getStore()
            ->shouldReceive('get')->once()->with('foo')->andReturn(null);
        $missingLayer->getStore()
            ->shouldReceive('forever')->once()->with('foo', 'bar')->andReturn(true);

        $superior->getStore()
            ->shouldReceive('get')->once()->with('foo')->andReturn('bar');

        $chain = new Chain([$missingLayer, $superior], 0);

        $this->assertEquals('bar', $chain->get('foo'));
    }

    #[Test]
    public function uses_last_lock_capable_provider_for_locks()
    {
        Cache::store('chain')->providers(['array', 'file']);

        $lock = Cache::store('chain')->lock('chain-lock', 10);

        $this->assertTrue($lock->get());

        $owner = $lock->owner();

        $this->assertNotNull($owner);

        $lock->release();
    }

    #[Test]
    public function can_restore_lock_from_last_lock_capable_provider()
    {
        Cache::store('chain')->providers(['array', 'file']);

        $lock = Cache::store('chain')->lock('chain-restore-lock', 10);

        $this->assertTrue($lock->get());

        $restored = Cache::store('chain')->restoreLock('chain-restore-lock', $lock->owner());

        $this->assertTrue($restored->release());
    }

    #[Test]
    public function should_throw_exception_with_empty_providers()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage(Exception::providers()->getMessage());
        Cache::store('chain')->providers([]);
    }

    #[Test]
    public function should_throw_exception_with_empty_lock_providers()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage(Exception::lockProvider()->getMessage());
        Cache::store('chain')->providers([new Repository(Mockery::mock(Store::class))]);
        Cache::store('chain')->lock('test');
    }

    protected function tearDown(): void
    {
        if (is_dir(storage_path('framework/cache/data'))) {
            Cache::store('file')->flush();
        }

        parent::tearDown();
    }
}
