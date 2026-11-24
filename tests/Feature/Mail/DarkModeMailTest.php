<?php

namespace Tests\Feature\Mail;

use App\Mail\OrderConfirmationContent;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** DM-1: a mail never reads the storefront's mode: the same order builds the same bytes whatever `appearance.mode` is (mails stay light). */
class DarkModeMailTest extends TestCase
{
    use PlacesOrdersForMail;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlacesOrders();
        FlakyTransport::reset();
        Storage::fake('public');
    }

    public function test_the_order_confirmation_is_byte_identical_in_light_and_dark_mode(): void
    {
        Queue::fake();
        $orderId = $this->placeMailOrder()['order_id'];
        $row = (object) ['related_id' => $orderId, 'locale' => 'en'];

        config(['storefront.dark_mode' => true]);
        $light = app(OrderConfirmationContent::class)->build($row);

        AppearanceWriter::save(array_replace(AppearanceWriter::current(), ['dark_mode' => true, 'dark_button' => '#ffffff']));
        $this->assertSame('dark', app(SiteSettingsRepository::class)->get(AppearanceSettings::MODE));
        $this->app->forgetScopedInstances();
        $dark = app(OrderConfirmationContent::class)->build($row);

        $this->assertNotNull($light);
        $this->assertNotNull($dark);
        $this->assertSame($light->html, $dark->html, 'the HTML');
        $this->assertSame($light->text, $dark->text, 'the plain text');
        $this->assertSame($light->subject, $dark->subject);
        $this->assertStringNotContainsString('data-theme', $dark->html);
        $this->assertStringNotContainsString('f97316', strtolower($dark->html), 'the dark button colour never reaches a mail');
    }
}
