<?php

namespace Tests\Feature;

use App\Filament\Pages\Help;
use App\Filament\Pages\ShippingOverview;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Filament\Support\HelpLink;
use App\Filament\Support\HelpRenderer;
use App\Filament\Support\HelpTopics;
use App\Services\PaymentReceiptRecorder;
use App\Settings\Contracts\SiteSettingsRepository;
use DateTimeImmutable;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsBankTransferOrders;
use Tests\TestCase;

/**
 * Help 1: the in-app help page, its skeleton (the anchors are a stable contract), the top-bar icon, the
 * "Help: how this works →" line of the order page's dialogs, and the completeness rule — an order action
 * without a help section fails here.
 */
class HelpPageTest extends TestCase
{
    use BuildsBankTransferOrders;
    use RefreshDatabase;

    /** The skeleton's anchors, in order — a CONTRACT: later content edits must never rename them. */
    private const ANCHORS = [
        'overview', 'payment-methods', 'actions',
        'action-confirm', 'action-ship', 'action-deliver', 'action-mark-as-received', 'action-accept-mismatch',
        'action-correct-receipt', 'action-record-return', 'action-refund-money-only', 'action-cancel', 'action-add-note',
        'action-edit-order', 'action-remove-line', 'action-restore-line', 'action-mark-refund-paid-out', 'action-cancel-refund',
        'bank-transfer', 'bank-exact', 'bank-partial', 'bank-over', 'bank-accept', 'bank-correct',
        'cash-on-delivery', 'cancel-order',
        'returns-and-refunds', 'returns-goods', 'returns-money-only', 'refund-owed-paid-out',
        'scenarios', 'glossary',
    ];

    /** The five order actions that are not in the Actions menu. */
    private const OUTSIDE_THE_MENU = ['edit_order', 'removeLine', 'restoreLine', 'mark_refund_paid_out', 'cancel_refund'];

    private function anchorsOf(string $locale): array
    {
        $markdown = (string) file_get_contents(resource_path("help/{$locale}/orders.md"));
        preg_match_all('/^#{2,3} .+ \{#([a-z0-9-]+)\}\s*$/m', $markdown, $matches);

        return $matches[1];
    }

    private function actingAsPanelStaff(string $role = 'Administrator'): void
    {
        $roles = app(RoleRepository::class);
        $found = $roles->findSystemRoleByName($role);

        if ($found === null) {
            app(StaffSystemRolesSeeder::class)->run($roles);
            $found = $roles->findSystemRoleByName($role);
        }

        $staff = Staff::create('help-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), 'Help Tester', $found);
        app(StaffRepository::class)->save($staff);

        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
        session()->forget('password_hash_staff');
    }

    // =====================================================================================================
    // The skeleton
    // =====================================================================================================

    public function test_both_language_files_exist_and_there_is_no_fallback_for_orders(): void
    {
        foreach (['en', 'bg'] as $locale) {
            $this->assertFileExists(resource_path("help/{$locale}/orders.md"));
            $this->assertNotNull(HelpTopics::path('orders', $locale));
        }
    }

    public function test_both_files_contain_exactly_the_same_anchors_in_the_same_order_each_once(): void
    {
        $en = $this->anchorsOf('en');
        $bg = $this->anchorsOf('bg');

        $this->assertSame(self::ANCHORS, $en);
        $this->assertSame(self::ANCHORS, $bg);
        $this->assertSame($en, array_values(array_unique($en)), 'each anchor once');

        // Every heading line carries an anchor: no heading without one.
        foreach (['en', 'bg'] as $locale) {
            $this->assertSame(count(self::ANCHORS), preg_match_all('/^#{2,3} /m', (string) file_get_contents(resource_path("help/{$locale}/orders.md"))));
        }
    }

    public function test_the_action_headings_are_the_real_button_labels(): void
    {
        // The help now has its real content (it was a placeholder skeleton in Help 1): what stays pinned is that every
        // action's heading is the label the button really shows, in both languages.
        foreach (['en', 'bg'] as $locale) {
            $markdown = (string) file_get_contents(resource_path("help/{$locale}/orders.md"));

            App::setLocale($locale);

            foreach ([
                'action-confirm' => 'orders.actions.confirm', 'action-ship' => 'orders.actions.ship', 'action-deliver' => 'orders.actions.deliver',
                'action-mark-as-received' => 'orders.actions.mark_as_received', 'action-accept-mismatch' => 'orders.receipt.accept.label',
                'action-correct-receipt' => 'orders.receipt.correct.label', 'action-record-return' => 'orders.actions.record_return',
                'action-refund-money-only' => 'orders.money_only.label', 'action-cancel' => 'orders.actions.cancel',
                'action-add-note' => 'orders.actions.add_note', 'action-edit-order' => 'orders.actions.edit',
                'action-remove-line' => 'orders.actions.edit_remove_line', 'action-restore-line' => 'orders.actions.edit_restore_line',
                'action-mark-refund-paid-out' => 'orders.refunds.mark_paid_out.label', 'action-cancel-refund' => 'orders.refunds.cancel.label',
            ] as $anchor => $key) {
                $this->assertStringContainsString('### '.__($key).' {#'.$anchor.'}', $markdown, "{$locale} {$anchor}");
            }
        }
    }

    // =====================================================================================================
    // Completeness
    // =====================================================================================================

    public function test_every_order_action_has_a_help_section_in_both_files(): void
    {
        $names = array_map(static fn ($action): string => $action->getName(), OrderResource::orderActionList());
        $names = array_unique([...$names, ...self::OUTSIDE_THE_MENU]);

        $this->assertNotEmpty($names);

        foreach ($names as $name) {
            foreach (['en', 'bg'] as $locale) {
                $this->assertContains(HelpLink::anchor($name), $this->anchorsOf($locale), "the action \"{$name}\" has no help section in {$locale}: add one (and keep the anchors stable)");
            }
        }
    }

    public function test_an_actions_anchor_is_its_name_with_hyphens(): void
    {
        $this->assertSame('action-mark-as-received', HelpLink::anchor('mark_as_received'));
        $this->assertSame('action-remove-line', HelpLink::anchor('removeLine'));
        $this->assertSame('action-restore-line', HelpLink::anchor('restoreLine'));
        $this->assertSame('action-cancel', HelpLink::anchor('cancel'));
    }

    public function test_every_dialog_action_of_the_order_page_appends_the_help_link_to_its_schema(): void
    {
        // The dialogs that cannot be opened in one fixture (a refund payout, the line-edit form) are checked at their source:
        // each of these actions' ->schema(...) goes through HelpLink::append() with its own name.
        $source = file_get_contents(app_path('Filament/Resources/OrderResource.php')).file_get_contents(app_path('Filament/Resources/OrderResource/PaymentReceiptDialog.php'));

        foreach (['confirm', 'ship', 'deliver', 'mark_as_received', 'cancel', 'record_return', 'refund_money_only', 'edit_order', 'add_note', 'mark_refund_paid_out', 'cancel_refund', 'accept_mismatch', 'correct_receipt'] as $name) {
            $start = strpos($source, "Action::make('{$name}')");
            $this->assertNotFalse($start, $name);
            $schema = strpos($source, '->schema(', $start);
            $this->assertNotFalse($schema, $name);
            $this->assertStringContainsString('HelpLink::append(', substr($source, $schema, 400), "{$name}'s schema carries the help line");
            $this->assertStringContainsString("'{$name}')", substr($source, $schema, 4000), "{$name}'s help line points at its own anchor");
        }
    }

    public function test_every_help_link_in_a_rendered_dialog_points_to_an_existing_anchor_and_opens_a_new_tab(): void
    {
        $this->actingAsAdministrator();
        $found = [];
        $check = function (string $label, string $modal) use (&$found): void {
            preg_match_all('/<a\s[^>]*href="([^"]*help\/orders#([a-z0-9-]+))"[^>]*>/', $modal, $links, PREG_SET_ORDER);
            $this->assertNotEmpty($links, "{$label}: the dialog has its help line");

            foreach ($links as $link) {
                $this->assertStringContainsString('target="_blank"', $link[0], $label);
                $this->assertStringContainsString('rel="noopener noreferrer"', $link[0], $label);
                $this->assertContains($link[2], $this->anchorsOf('en'), "{$label}: anchor in en");
                $this->assertContains($link[2], $this->anchorsOf('bg'), "{$label}: anchor in bg");
                $found[] = $link[2];
            }
        };

        $open = function (array $order, string $action) {
            $this->app->forgetScopedInstances();

            return Livewire::test(ViewOrder::class, ['record' => $order['orderId']])->mountAction($action)->getMountedActionModalHtml();
        };

        // A pending bank order with a short receipt: confirm, the receipt dialog, accept, correct, cancel, add a note.
        $bank = $this->bankOrder();
        app(PaymentReceiptRecorder::class)->record((string) $bank['payment']->id(), $this->eur(9000), '2026-09-28', 'REF', new DateTimeImmutable());

        foreach (['confirm', 'mark_as_received', 'accept_mismatch', 'correct_receipt', 'cancel', 'add_note'] as $action) {
            $check($action, $open($bank, $action));
        }

        // A cash-on-delivery order: the plain one-click confirmation.
        $cod = $this->bankOrder(method: 'cash_on_delivery');
        $check('mark_as_received (cash on delivery)', $open($cod, 'mark_as_received'));

        // A confirmed order (ship), and a shipped settled one (deliver, return, money-only refund).
        $check('ship', $open($this->refundableOrder([['quantity' => 2, 'unit' => 1000]], status: OrderStatus::CONFIRMED, method: 'cash_on_delivery', settle: false), 'ship'));
        $shipped = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]], method: 'cash_on_delivery');

        foreach (['deliver', 'record_return', 'refund_money_only'] as $action) {
            $check($action, $open($shipped, $action));
        }

        $this->assertEqualsCanonicalizing(
            ['action-confirm', 'action-mark-as-received', 'action-accept-mismatch', 'action-correct-receipt', 'action-cancel', 'action-add-note', 'action-ship', 'action-deliver', 'action-record-return', 'action-refund-money-only'],
            array_values(array_unique($found)),
        );
    }

    public function test_the_help_line_is_a_muted_text_link_and_adds_no_field(): void
    {
        $component = HelpLink::component('cancel');

        $this->assertInstanceOf(\Filament\Schemas\Components\Text::class, $component);
        $this->assertCount(2, HelpLink::append([HelpLink::component('cancel')], 'cancel'));
    }

    // =====================================================================================================
    // The page
    // =====================================================================================================

    public function test_the_help_page_renders_for_a_staff_member_in_bg_and_en_with_the_right_file_and_a_contents_list(): void
    {
        foreach (['bg' => ['Как върви една поръчка', 'Съдържание', 'Пътят напред е един'], 'en' => ['How an order moves', 'Contents', 'There is one way forward']] as $locale => [$heading, $contents, $body]) {
            app(SiteSettingsRepository::class)->set('site.locale', $locale);
            $this->actingAsPanelStaff();

            $response = $this->get(Help::getUrl(['topic' => 'orders']));
            $response->assertOk();
            $html = $response->getContent();

            $this->assertStringContainsString($heading, $html, "{$locale}: the right file");
            $this->assertStringContainsString($body, $html);
            $this->assertStringContainsString('class="help-toc help-toc-side"', $html);
            $this->assertStringContainsString('<a href="#overview">'.$heading.'</a>', $html, 'the contents list links the headings');
            $this->assertStringContainsString('↑ '.$contents, $html, 'the back-to-contents link');
            $this->assertStringContainsString('id="overview"', $html);
            $this->assertStringContainsString('id="action-mark-as-received"', $html);
            $this->assertStringContainsString('id="bank-over"', $html);
        }
    }

    public function test_the_default_topic_is_orders_and_any_staff_member_can_read_it(): void
    {
        $this->actingAsPanelStaff('Product Entry');

        $this->get(url('/admin/help'))->assertOk()->assertSee(__('help.title'));
    }

    public function test_a_guest_cannot_read_the_help(): void
    {
        $response = $this->get(url('/admin/help/orders'));

        $this->assertContains($response->getStatusCode(), [302, 403]);
        $response->getStatusCode() === 302 && $this->assertStringContainsString('login', (string) $response->headers->get('Location'));
    }

    public function test_an_unknown_topic_is_a_404(): void
    {
        $this->actingAsPanelStaff();

        $this->get(url('/admin/help/does-not-exist'))->assertNotFound();
        $this->get(url('/admin/help/..%2F..%2F.env'))->assertNotFound();
    }

    public function test_the_page_runs_no_query_of_its_own(): void
    {
        $this->actingAsPanelStaff();
        $this->get(Help::getUrl(['topic' => 'orders']))->assertOk(); // warm the file cache

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(Help::getUrl(['topic' => 'orders']))->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/\b(staff|staff_roles)\b/', $query, "only the panel's own authentication reads the database: {$query}");
        }
    }

    // =====================================================================================================
    // The renderer
    // =====================================================================================================

    public function test_raw_html_and_unsafe_links_in_the_markdown_never_reach_the_page(): void
    {
        $dir = resource_path('help/xx');
        File::ensureDirectoryExists($dir);
        file_put_contents($dir.'/orders.md', "## Safe {#safe}\n\nText <script>alert(1)</script> <img src=x onerror=alert(2)>\n\n[bad](javascript:alert(3)) and [ok](https://example.com)\n\n<div onclick=\"x()\">raw</div>\n");

        try {
            $result = app(HelpRenderer::class)->render('orders', 'xx');
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertStringNotContainsString('onerror', $result['html']);
        $this->assertStringNotContainsString('onclick', $result['html']);
        $this->assertStringNotContainsString('javascript:', $result['html']);
        $this->assertStringContainsString('https://example.com', $result['html']);
        $this->assertStringContainsString('<h2 id="safe">Safe</h2>', $result['html']);
        $this->assertSame([['id' => 'safe', 'title' => 'Safe', 'level' => 2]], $result['toc']);
        $this->assertSame('xx', $result['locale']);
    }

    public function test_a_missing_language_file_falls_back_to_english_for_a_topic_that_has_one(): void
    {
        // The fallback exists for FUTURE topics; for orders both files are required (see the first test).
        $result = app(HelpRenderer::class)->render('orders', 'de');

        $this->assertSame('en', $result['locale']);
        $this->assertStringContainsString('How an order moves', $result['html']);
    }

    public function test_the_rendering_is_cached_per_locale_and_file_version(): void
    {
        $first = app(HelpRenderer::class)->render('orders', 'bg');
        $second = app(HelpRenderer::class)->render('orders', 'bg');

        $this->assertSame($first, $second);
        $this->assertSame('bg', $first['locale']);
        $this->assertNotSame($first['html'], app(HelpRenderer::class)->render('orders', 'en')['html']);
    }

    // =====================================================================================================
    // The links
    // =====================================================================================================

    public function test_the_top_bar_help_icon_opens_the_help_in_a_new_tab(): void
    {
        $this->actingAsPanelStaff();

        $html = $this->get(OrderResource::getUrl('index'))->assertOk()->getContent();
        $region = substr($html, strpos($html, 'class="fi-topbar-end"'));
        $region = substr($region, 0, strpos($region, '</nav>'));

        $this->assertMatchesRegularExpression('/<a\s[^>]*href="'.preg_quote(Help::getUrl(), '/').'"[^>]*>/', $region);
        preg_match('/<a\s[^>]*href="'.preg_quote(Help::getUrl(), '/').'"[^>]*>/', $region, $link);
        $this->assertStringContainsString('target="_blank"', $link[0]);
        $this->assertStringContainsString('rel="noopener noreferrer"', $link[0]);
        $this->assertStringContainsString('aria-label="'.__('help.topbar').'"', $link[0]);
    }

    public function test_the_registry_knows_one_topic_and_labels_it_in_both_languages(): void
    {
        $this->assertSame(['orders', 'shipping'], HelpTopics::all());
        $this->assertTrue(HelpTopics::has('orders'));
        $this->assertTrue(HelpTopics::has('shipping'));
        $this->assertFalse(HelpTopics::has('nope'));
        $this->assertFalse(HelpTopics::has(null));
        $this->assertSame('Orders and payments', trans('help.topics.orders', [], 'en'));
        $this->assertSame('Поръчки и плащания', trans('help.topics.orders', [], 'bg'));
        $this->assertSame('Shipping', trans('help.topics.shipping', [], 'en'));
        $this->assertSame('Доставка', trans('help.topics.shipping', [], 'bg'));
        $this->assertNull(HelpTopics::path('orders', '../../x'));
        $this->assertNull(HelpTopics::path('../secret', 'en'));
    }

    // =====================================================================================================
    // The shipping topic (shipping-domain-design.md §12.8, stage 5a)
    // =====================================================================================================

    /** The shipping skeleton's anchors, in order — a CONTRACT: later content edits must never rename them. */
    private const SHIPPING_ANCHORS = [
        'action-shipping-overview', 'action-zone-editor', 'action-zone-order', 'action-zone-settlement-matching',
        'action-method-editor', 'action-method-class-mode', 'action-method-replace', 'action-method-adjust',
        'action-method-free-above', 'action-class-editor', 'action-class-delete-blocked', 'action-product-class-field',
        'action-try-it', 'action-method-copy',
    ];

    private function anchorsOfTopic(string $topic, string $locale): array
    {
        $markdown = (string) file_get_contents(resource_path("help/{$locale}/{$topic}.md"));
        preg_match_all('/^#{2,3} .+ \{#([a-z0-9-]+)\}\s*$/m', $markdown, $matches);

        return $matches[1];
    }

    public function test_the_shipping_topic_loads_in_both_languages(): void
    {
        foreach (['en', 'bg'] as $locale) {
            $this->assertFileExists(resource_path("help/{$locale}/shipping.md"));
            $this->assertNotNull(HelpTopics::path('shipping', $locale));
        }
    }

    public function test_the_shipping_skeleton_has_the_same_anchors_in_the_same_order_each_once_in_both_files(): void
    {
        $en = $this->anchorsOfTopic('shipping', 'en');
        $bg = $this->anchorsOfTopic('shipping', 'bg');

        $this->assertSame(self::SHIPPING_ANCHORS, $en);
        $this->assertSame(self::SHIPPING_ANCHORS, $bg);
        $this->assertSame($en, array_values(array_unique($en)), 'each anchor once');

        // Every heading line carries an anchor: no heading without one.
        foreach (['en', 'bg'] as $locale) {
            $this->assertSame(count(self::SHIPPING_ANCHORS), preg_match_all('/^#{2,3} /m', (string) file_get_contents(resource_path("help/{$locale}/shipping.md"))));
        }
    }

    public function test_every_help_link_on_the_shipping_page_points_to_an_anchor_that_exists_in_both_files(): void
    {
        $this->actingAsPanelStaff();

        $html = (string) $this->get(ShippingOverview::getUrl())->assertOk()->getContent();

        preg_match_all('~help/shipping#([a-z0-9-]+)~', $html, $matches);
        $found = array_values(array_unique($matches[1]));

        $this->assertSame(['action-shipping-overview', 'action-try-it'], $found, 'the page links to the two anchors it documents');

        foreach ($found as $anchor) {
            foreach (['en', 'bg'] as $locale) {
                $this->assertContains($anchor, $this->anchorsOfTopic('shipping', $locale), "{$anchor} is missing from the {$locale} help file");
            }
        }
    }
}
