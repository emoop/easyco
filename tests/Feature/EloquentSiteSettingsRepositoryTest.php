<?php

namespace Tests\Feature;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\Persistence\Eloquent\SiteSettingModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class EloquentSiteSettingsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    public function test_set_then_get_round_trips_the_value(): void
    {
        $this->repository()->set('media.hero_slider_enabled', 'true');

        $this->assertSame('true', $this->repository()->get('media.hero_slider_enabled'));
    }

    public function test_get_returns_null_for_a_nonexistent_key(): void
    {
        $this->assertNull($this->repository()->get('media.does_not_exist'));
    }

    public function test_set_on_an_existing_key_updates_the_value_instead_of_creating_a_duplicate_row(): void
    {
        $this->repository()->set('media.admin_grid_aspect_ratio', '4:3');
        $this->repository()->set('media.admin_grid_aspect_ratio', '16:9');

        $this->assertSame('16:9', $this->repository()->get('media.admin_grid_aspect_ratio'));
        $this->assertSame(1, SiteSettingModel::where('key', 'media.admin_grid_aspect_ratio')->count());
    }

    public function test_forget_removes_the_key(): void
    {
        $this->repository()->set('media.hero_slider_enabled', 'true');

        $this->repository()->forget('media.hero_slider_enabled');

        $this->assertNull($this->repository()->get('media.hero_slider_enabled'));
    }

    public function test_forget_on_a_nonexistent_key_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->repository()->forget('media.never_set');
    }

    public function test_set_with_an_empty_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository()->set('', 'some value');
    }

    public function test_two_different_keys_are_independent(): void
    {
        $this->repository()->set('media.hero_slider_enabled', 'true');
        $this->repository()->set('media.admin_grid_aspect_ratio', '4:3');

        $this->assertSame('true', $this->repository()->get('media.hero_slider_enabled'));
        $this->assertSame('4:3', $this->repository()->get('media.admin_grid_aspect_ratio'));
    }
}
