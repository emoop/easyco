<?php

namespace Tests\Feature;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\Persistence\Eloquent\EloquentSiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Checkout RN-1b: SiteSettingsRepository::getMany() reads the keys it does not already know with ONE query, through the SAME
 * per-request memo as get() — including a key that is not set (a memoized "nothing"). Counted with DB::listen, like the checkout pin.
 */
class SiteSettingsGetManyTest extends TestCase
{
    use RefreshDatabase;

    /** A fresh repository with an empty memo (the real one is request-scoped), and the rows written straight to the table. */
    private function repository(array $rows = []): EloquentSiteSettingsRepository
    {
        foreach ($rows as $key => $value) {
            DB::table('site_settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }

        return new EloquentSiteSettingsRepository();
    }

    private function queries(callable $work): int
    {
        $count = 0;
        DB::listen(function ($query) use (&$count): void {
            if (str_contains($query->sql, 'site_settings')) {
                $count++;
            }
        });

        $work();

        return $count;
    }

    public function test_two_keys_cost_exactly_one_query_and_a_following_get_costs_none(): void
    {
        $repository = $this->repository(['a.one' => 'first', 'a.two' => 'second']);
        $result = [];

        $this->assertSame(1, $this->queries(function () use ($repository, &$result): void {
            $result = $repository->getMany(['a.one', 'a.two']);
        }));
        $this->assertSame(['a.one' => 'first', 'a.two' => 'second'], $result);

        $this->assertSame(0, $this->queries(function () use ($repository): void {
            $this->assertSame('first', $repository->get('a.one'));
            $this->assertSame('second', $repository->get('a.two'));
            $repository->getMany(['a.one', 'a.two']);
        }), 'served by the memo');
    }

    public function test_an_absent_key_is_null_and_memoised_too(): void
    {
        $repository = $this->repository(['a.one' => 'first']);

        $result = [];
        $this->assertSame(1, $this->queries(function () use ($repository, &$result): void {
            $result = $repository->getMany(['a.one', 'a.nothing']);
        }));
        $this->assertSame(['a.one' => 'first', 'a.nothing' => null], $result);

        $this->assertSame(0, $this->queries(function () use ($repository): void {
            $this->assertNull($repository->get('a.nothing'));
            $this->assertSame(['a.nothing' => null], $repository->getMany(['a.nothing']));
        }), '"not set" is an answer worth remembering');
    }

    public function test_a_mix_of_known_and_unknown_keys_queries_only_the_unknown_ones(): void
    {
        $repository = $this->repository(['a.one' => 'first', 'a.two' => 'second', 'a.three' => 'third']);
        $repository->get('a.one');

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = [$query->sql, $query->bindings];
        });

        $result = $repository->getMany(['a.one', 'a.two', 'a.three']);

        $this->assertSame(['a.one' => 'first', 'a.two' => 'second', 'a.three' => 'third'], $result);
        $this->assertCount(1, $sql);
        $this->assertSame(['a.two', 'a.three'], $sql[0][1], 'the memoised key is not asked again');
    }

    public function test_set_then_getmany_returns_the_written_value_without_a_query(): void
    {
        $repository = $this->repository();

        $repository->set('a.one', 'written');

        $this->assertSame(0, $this->queries(function () use ($repository): void {
            $this->assertSame(['a.one' => 'written'], $repository->getMany(['a.one']));
        }));
    }

    public function test_forget_then_getmany_reads_again(): void
    {
        $repository = $this->repository(['a.one' => 'first']);
        $repository->getMany(['a.one']);

        $repository->forget('a.one');

        $result = [];
        $this->assertSame(1, $this->queries(function () use ($repository, &$result): void {
            $result = $repository->getMany(['a.one']);
        }));
        $this->assertSame(['a.one' => null], $result);
    }

    public function test_an_empty_list_is_an_empty_result_and_no_query(): void
    {
        $repository = $this->repository(['a.one' => 'first']);
        $result = ['x' => 'y'];

        $this->assertSame(0, $this->queries(function () use ($repository, &$result): void {
            $result = $repository->getMany([]);
        }));
        $this->assertSame([], $result);
    }

    public function test_every_requested_key_is_in_the_result_in_the_requested_order(): void
    {
        $repository = $this->repository(['a.two' => 'second']);

        $this->assertSame(['a.three' => null, 'a.two' => 'second', 'a.one' => null], $repository->getMany(['a.three', 'a.two', 'a.one']));
    }

    public function test_a_repeated_key_is_asked_once_and_the_container_binding_is_this_repository(): void
    {
        $repository = $this->repository(['a.one' => 'first']);

        $this->assertSame(1, $this->queries(fn () => $repository->getMany(['a.one', 'a.one'])));
        $this->assertInstanceOf(EloquentSiteSettingsRepository::class, app(SiteSettingsRepository::class));
    }
}
