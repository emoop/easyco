<?php

namespace Tests\Feature\Security;

use App\Filament\Pages\Settings\SecuritySettings;
use App\Security\BotChallenge\BotChallenge;
use App\Security\BotChallenge\BotChallengeSwitch;
use App\Security\BotChallenge\NullBotChallengeSwitch;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Cache\CachePurgeCollector;
use App\Storefront\Cache\CachePurger;
use App\Storefront\Cache\RecordingPurger;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/** P8d: the Livewire update throttle (per staff id) and the bot-challenge switch (setting, command, scheduled push, owner-only field, no page-cache purge). */
class LivewireThrottleAndBotChallengeTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    // ---- the Livewire throttle --------------------------------------------------------------------------------------------------------

    /** ★ the 301st update in a minute for one staff user is a 429; another user is unaffected */
    public function test_the_301st_livewire_update_of_one_staff_user_is_throttled_and_another_is_not(): void
    {
        $uri = app(HandleRequests::class)->getUpdateUri();
        $headers = ['X-Livewire' => '1', 'Accept' => 'application/json'];

        $first = $this->actingAsStaff('Administrator');
        $firstId = Auth::guard('staff')->id();

        for ($i = 1; $i <= 300; $i++) {
            $status = $this->withHeaders($headers)->postJson($uri, [])->getStatusCode();
            $this->assertNotSame(429, $status, "update {$i} must pass");
        }

        $this->withHeaders($headers)->postJson($uri, [])->assertStatus(429);

        $second = $this->actingAsStaff('Manager');
        $this->assertNotSame($firstId, Auth::guard('staff')->id());
        $this->assertNotSame(429, $this->withHeaders($headers)->postJson($uri, [])->getStatusCode(), 'another staff user has his own budget');
        $this->assertNotNull($first);
        $this->assertNotNull($second);
    }

    // ---- the bot-challenge switch -----------------------------------------------------------------------------------------------------

    public function test_the_closed_list_reads_anything_unknown_as_off(): void
    {
        $settings = app(SiteSettingsRepository::class);
        $challenge = app(BotChallenge::class);

        $this->assertSame('off', $challenge->current(), 'the default');

        foreach (['filters', 'misses', 'off'] as $mode) {
            $settings->set(BotChallenge::KEY, $mode);
            $this->assertSame($mode, $challenge->current());
        }

        foreach (['everything', 'FILTERS', '', ' filters', '1'] as $junk) {
            $settings->set(BotChallenge::KEY, $junk);
            $this->assertSame('off', $challenge->current(), "«{$junk}» reads as off");
        }

        $this->expectException(\InvalidArgumentException::class);
        $challenge->set('everything');
    }

    public function test_the_command_writes_the_mode_and_refuses_an_unknown_one(): void
    {
        $this->artisan('security:bot-challenge filters')->assertSuccessful();
        $this->assertSame('filters', app(BotChallenge::class)->current());

        $this->artisan('security:bot-challenge nonsense')->assertFailed();
        $this->assertSame('filters', app(BotChallenge::class)->current(), 'a refused mode changes nothing');

        $this->artisan('security:bot-challenge off')->assertSuccessful();
        $this->assertSame('off', app(BotChallenge::class)->current());
    }

    public function test_the_scheduled_push_sends_the_stored_value_to_the_switch(): void
    {
        $pushed = [];
        $this->app->instance(BotChallengeSwitch::class, new class($pushed) implements BotChallengeSwitch
        {
            public function __construct(public array &$pushed)
            {
            }

            public function push(string $mode): void
            {
                $this->pushed[] = $mode;
            }
        });

        app(BotChallenge::class)->set('misses');
        Artisan::call('security:push-bot-challenge');

        $this->assertSame(['misses'], $pushed);

        $events = collect(app(Schedule::class)->events())->filter(fn ($e): bool => str_contains((string) $e->command, 'security:push-bot-challenge'));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression, 'every minute');
    }

    public function test_the_null_switch_does_nothing_and_is_the_default_binding(): void
    {
        $this->assertInstanceOf(NullBotChallengeSwitch::class, app(BotChallengeSwitch::class));
        $this->assertNull(app(BotChallengeSwitch::class)->push('filters'));
    }

    public function test_only_the_owner_sees_and_saves_the_field(): void
    {
        $this->actingAsStaff('Product Entry');
        $this->get(SecuritySettings::getUrl())->assertForbidden();

        $this->actingAsStaff('Manager');
        $this->get(SecuritySettings::getUrl())->assertForbidden();
        $this->assertFalse(SecuritySettings::canAccess());

        $this->actingAsStaff('Administrator');
        $this->get(SecuritySettings::getUrl())->assertOk();

        Livewire::test(SecuritySettings::class)
            ->assertFormSet(['bot_challenge' => 'off'])
            ->fillForm(['bot_challenge' => 'filters'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('filters', app(BotChallenge::class)->current());
    }

    public function test_a_hand_made_save_from_a_lesser_role_is_a_403(): void
    {
        $this->actingAsStaff('Manager');

        try {
            app(SecuritySettings::class)->save(); // what a forged Livewire call would reach
            $this->fail('a role without the permission must not save');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame('off', app(BotChallenge::class)->current());
    }

    /** ★ saving it collects NO page-cache purge (`security.` is invisible to visitors) */
    public function test_saving_the_switch_purges_nothing(): void
    {
        $purger = new RecordingPurger;
        $this->app->instance(CachePurger::class, $purger);
        app(CachePurgeCollector::class)->reset();

        app(BotChallenge::class)->set('misses');
        app(BotChallenge::class)->set('off');
        app(SiteSettingsRepository::class)->set('security.anything_else', '1');

        $this->assertSame([], app(CachePurgeCollector::class)->pendingKeys());
    }
}
