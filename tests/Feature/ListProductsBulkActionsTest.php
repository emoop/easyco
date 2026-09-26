<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\StaffPanelUser;
use App\Services\ProductDeletionRefusalMessage;
use App\Settings\Contracts\SiteSettingsRepository;
use DateTimeImmutable;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Enums\VariationDeletionRefusal;
use EasyCo\Catalog\Exceptions\ProductNotDeletableException;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Persistence\Eloquent\ClientModel;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The products list's three BULK actions (D3) — Archive, Publish, Delete
 * permanently — their rules (D5/D6/D7) and their server-side gates (D8).
 *
 * DRIVEN THROUGH THE REAL ACTIONS: `callTableBulkAction()` selects the records the
 * way the checkboxes do and submits the real modal's data array, so the
 * confirmation rules, the per-product loop and the notifications are exercised as
 * the merchant's own flow.
 *
 * THE CRAFTED-CALL TESTS PASS RECORDS IN DIRECTLY even where the action's own
 * ->visible() is false / the modal refuses — that is the point: visibility is a UI
 * affordance, and every gate has to hold server-side. Nothing here calls a public
 * Livewire method with ids; the records always come from Filament's own selection
 * state, exactly as they do in the browser.
 */
class ListProductsBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
    }

    private function staffWithRole(string $roleName): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName($roleName);
        }

        $email = strtolower(str_replace(' ', '.', $roleName)).'@example.com';
        $staff = Staff::create($email, app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    private function simpleProduct(ProductStatus $status, string $slug, string $sku): ProductModel
    {
        $product = Product::createSimple('Product '.$slug, $sku, $slug);

        match ($status) {
            ProductStatus::ACTIVE => $product->publish(),
            ProductStatus::ARCHIVED => $product->archive(),
            ProductStatus::DRAFT => $product->markAsDraft(),
        };

        app(ProductRepository::class)->save($product);

        return ProductModel::where('slug', $slug)->firstOrFail();
    }

    /**
     * A persisted VARIABLE product with `$liveVariations` STANDARD variations —
     * 0 is the one state Product::publish() refuses, which is how the bulk publish
     * gets a real per-product refusal to report.
     *
     * @return array{0: ProductModel, 1: list<string>} the model and its variation ids
     */
    private function variableProduct(int $liveVariations, string $slug = 'bulk-variable', string $baseSku = 'SKU-BULK-VAR'): array
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);

        $white = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'White');
        app(AttributeValueRepository::class)->save($white);

        $product = Product::createVariable('Bulk Variable', $baseSku, $slug);
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);

        if ($liveVariations > 0) {
            $product->addStandardVariation([$definition->id() => $black->id()], $baseSku.'-BLACK');
        }

        if ($liveVariations > 1) {
            $product->addStandardVariation([$definition->id() => $white->id()], $baseSku.'-WHITE');
        }

        app(ProductRepository::class)->save($product);

        $variationIds = [];
        foreach ($product->variations() as $variation) {
            $variationIds[] = (string) $variation->id();
        }

        return [ProductModel::where('slug', $slug)->firstOrFail(), $variationIds];
    }

    /** One real image pivot (plus the catalog_media asset behind it) at the given sort_order. */
    private function attachMedia(string $productId, int $sortOrder, string $path): void
    {
        $mediaId = (int) DB::table('catalog_media')->insertGetId([
            'type' => 'image',
            'disk' => 'public',
            'path' => $path,
            'alt_text' => null,
            'processing_status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('catalog_product_media')->insert([
            'product_id' => $productId,
            'media_id' => $mediaId,
            'sort_order' => $sortOrder,
        ]);
    }

    private function addSaleLine(string $variationId): void
    {
        $clientId = (string) ClientModel::create(['name' => 'Bulk Test Client'])->id;

        $transaction = new Transaction(id: null, channel: Channel::POS);
        $transaction->addSaleLine(SaleLine::create(
            transactionId: '',
            clientId: $clientId,
            priceableId: $variationId,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: Money::fromMinorUnits(2500, 'EUR'),
            profit: Money::fromMinorUnits(400, 'EUR'),
            recordedAt: new DateTimeImmutable('2026-08-25 10:00:00'),
            effectiveAt: new DateTimeImmutable('2026-08-20 09:00:00'),
            productName: 'Product with history',
            sku: 'SKU-HISTORY',
            regularUnitPrice: Money::fromMinorUnits(2500, 'EUR'),
            finalUnitPrice: Money::fromMinorUnits(2500, 'EUR'),
            promotionDiscountShare: Money::zero('EUR'),
            discretionaryDiscount: Money::zero('EUR'),
            netPaidAmount: Money::fromMinorUnits(2500, 'EUR'),
            soldAttributes: [],
        ));

        app(TransactionRepository::class)->save($transaction);
    }

    private function addStockLevel(string $variationId, int $quantity): void
    {
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $quantity));
    }

    /** @return array<int, object> the 'status' field-change rows for one product, oldest first */
    private function statusLogRows(string $productId): array
    {
        return DB::table('activity_log')
            ->where('entity_type', 'product')
            ->where('entity_id', $productId)
            ->where('field', 'status')
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function productStillExists(string $productId): bool
    {
        return DB::table('catalog_products')->where('id', $productId)->exists();
    }

    /**
     * The id of a SIMPLE product's single variation (its Universal one) — what a
     * sale line and a stock level attach to.
     */
    private function onlyVariationId(string $productId): string
    {
        return (string) DB::table('catalog_variations')->where('product_id', $productId)->value('id');
    }

    /** The status a product is in right now, straight from its row. */
    private function statusOf(string $productId): string
    {
        return (string) DB::table('catalog_products')->where('id', $productId)->value('status');
    }

    /**
     * The notifications the panel would render, oldest first, as plain
     * ['title' => …, 'body' => …] arrays — read through Filament's own Notifications
     * component, exactly the reader assertNotified() uses.
     *
     * READ ONCE PER TEST: that component's mount() CONSUMES the session queue, so a
     * second call in the same test legitimately finds nothing.
     *
     * @return list<array{title: string, body: string}>
     */
    private function sentNotifications(): array
    {
        $component = new Notifications();
        $component->mount();

        $rendered = [];

        foreach ($component->notifications as $notification) {
            // toArray(), not the properties: v5.8.1's Notification keeps $title and
            // $body protected and exposes them through getTitle()/getBody() — which
            // toArray() is the supported reader of.
            $rendered[] = [
                'title' => $this->notificationText($notification->toArray()['title'] ?? null),
                'body' => $this->notificationText($notification->toArray()['body'] ?? null),
            ];
        }

        return $rendered;
    }

    /** A notification's title/body as the plain text a merchant reads. */
    private function notificationText(mixed $value): string
    {
        if ($value instanceof \Illuminate\Contracts\Support\Htmlable) {
            $value = $value->toHtml();
        }

        return trim(strip_tags((string) $value));
    }

    /**
     * The last notification, as the two strings a merchant reads.
     *
     * @return array{title: string, body: string}
     */
    private function lastNotification(): array
    {
        $notifications = $this->sentNotifications();

        $this->assertNotEmpty($notifications, 'a notification was expected but none were sent.');

        return $notifications[array_key_last($notifications)];
    }

    public function test_bulk_archive_archives_every_selected_product_and_cleans_its_media_and_logs_it(): void
    {
        $this->staffWithRole('Administrator');

        $first = $this->simpleProduct(ProductStatus::ACTIVE, 'bulk-archive-one', 'SKU-ARCH-ONE');
        $second = $this->simpleProduct(ProductStatus::ACTIVE, 'bulk-archive-two', 'SKU-ARCH-TWO');

        foreach ([$first, $second] as $product) {
            $this->attachMedia((string) $product->id, 0, 'products/'.$product->slug.'-main.jpg');
            $this->attachMedia((string) $product->id, 1, 'products/'.$product->slug.'-gallery.jpg');
        }

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('bulk_archive', [$first, $second]);

        foreach ([$first, $second] as $product) {
            $productId = (string) $product->id;

            // The DOMAIN transition and the save, per product…
            $this->assertSame(ProductStatus::ARCHIVED->value, $this->statusOf($productId));

            // …the §3.19.7 cleanup, per product (the gallery pivot is gone, the
            // main photo has no thumbnail variant to demote to and stays)…
            $this->assertSame(
                [0],
                array_map('intval', DB::table('catalog_product_media')->where('product_id', $productId)->pluck('sort_order')->all()),
            );

            // …and the same activity-log row the edit page writes.
            $rows = $this->statusLogRows($productId);
            $this->assertCount(1, $rows);
            $this->assertSame(ProductStatus::ACTIVE->value, $rows[0]->old_value);
            $this->assertSame(ProductStatus::ARCHIVED->value, $rows[0]->new_value);
        }

        $notification = $this->lastNotification();

        $this->assertSame(__('products.bulk.archive_done_title'), $notification['title']);
        $this->assertStringContainsString(
            __('products.bulk.archive_done_body', ['archived' => 2, 'skipped' => 0, 'failed' => 0]),
            $notification['body'],
        );
    }

    public function test_bulk_archive_reports_an_already_archived_product_instead_of_failing_the_run(): void
    {
        $this->staffWithRole('Administrator');

        $active = $this->simpleProduct(ProductStatus::ACTIVE, 'bulk-archive-live', 'SKU-ARCH-LIVE');
        $already = $this->simpleProduct(ProductStatus::ARCHIVED, 'bulk-archive-done', 'SKU-ARCH-DONE');

        // THE 'all' VIEW, deliberately: a merchant can only tick rows their current
        // view actually shows, and this is the one view where an already-archived
        // product can sit next to an active one.
        Livewire::test(ListProducts::class)
            ->set('statusView', 'all')
            ->callTableBulkAction('bulk_archive', [$active, $already]);

        // The already-archived one is REPORTED, not an error: it stays exactly
        // where it was, with no second log entry and no second cleanup.
        $this->assertSame(ProductStatus::ARCHIVED->value, $this->statusOf((string) $already->id));
        $this->assertCount(0, $this->statusLogRows((string) $already->id));

        // ...and the rest of the selection still went through.
        $this->assertSame(ProductStatus::ARCHIVED->value, $this->statusOf((string) $active->id));

        $notification = $this->lastNotification();

        $this->assertStringContainsString(
            __('products.bulk.archive_done_body', ['archived' => 1, 'skipped' => 1, 'failed' => 0]),
            $notification['body'],
        );
        $this->assertStringContainsString(
            __('products.bulk.refusal_line', [
                'name' => $already->name,
                'reason' => __('products.bulk.reason.already_archived'),
            ]),
            $notification['body'],
        );
    }

    public function test_the_archive_action_is_offered_in_every_view_except_archived(): void
    {
        $this->staffWithRole('Administrator');

        $this->simpleProduct(ProductStatus::ACTIVE, 'bulk-archive-visibility', 'SKU-ARCH-VIS');

        foreach (['active', 'draft', 'all'] as $view) {
            Livewire::test(ListProducts::class)
                ->set('statusView', $view)
                ->assertTableBulkActionVisible('bulk_archive');
        }

        Livewire::test(ListProducts::class)
            ->set('statusView', 'archived')
            ->assertTableBulkActionHidden('bulk_archive');
    }

    public function test_bulk_publish_publishes_the_drafts_and_reports_the_product_the_domain_refuses(): void
    {
        $this->staffWithRole('Administrator');

        $first = $this->simpleProduct(ProductStatus::DRAFT, 'bulk-publish-one', 'SKU-PUB-ONE');
        $second = $this->simpleProduct(ProductStatus::DRAFT, 'bulk-publish-two', 'SKU-PUB-TWO');

        // A VARIABLE product with NO live STANDARD variation: the one state
        // Product::publish() itself refuses (CannotPublishEmptyVariableProductException).
        [$refused] = $this->variableProduct(0, 'bulk-publish-refused', 'SKU-PUB-REFUSED');

        Livewire::test(ListProducts::class)
            ->set('statusView', 'draft')
            ->callTableBulkAction('bulk_publish', [$first, $second, $refused]);

        $this->assertSame(ProductStatus::ACTIVE->value, $this->statusOf((string) $first->id));
        $this->assertSame(ProductStatus::ACTIVE->value, $this->statusOf((string) $second->id));

        // Refused — refused by the DOMAIN, not by the action, and left untouched.
        $this->assertSame(ProductStatus::DRAFT->value, $this->statusOf((string) $refused->id));
        $this->assertCount(0, $this->statusLogRows((string) $refused->id));

        $notification = $this->lastNotification();

        $this->assertSame(__('products.bulk.publish_done_title'), $notification['title']);
        $this->assertStringContainsString(
            __('products.bulk.publish_done_body', ['published' => 2, 'failed' => 1]),
            $notification['body'],
        );
        $this->assertStringContainsString(
            __('products.bulk.refusal_line', [
                'name' => $refused->name,
                'reason' => __('products.bulk.reason.cannot_publish_empty_variable'),
            ]),
            $notification['body'],
        );
    }

    public function test_the_publish_action_is_offered_in_the_draft_and_all_views_only(): void
    {
        $this->staffWithRole('Administrator');

        $this->simpleProduct(ProductStatus::DRAFT, 'bulk-publish-visibility', 'SKU-PUB-VIS');

        foreach (['draft', 'all'] as $view) {
            Livewire::test(ListProducts::class)
                ->set('statusView', $view)
                ->assertTableBulkActionVisible('bulk_publish');
        }

        foreach (['active', 'archived'] as $view) {
            Livewire::test(ListProducts::class)
                ->set('statusView', $view)
                ->assertTableBulkActionHidden('bulk_publish');
        }
    }

    public function test_the_bulk_delete_action_is_offered_only_in_the_archived_view(): void
    {
        $this->staffWithRole('Administrator');

        $this->simpleProduct(ProductStatus::ARCHIVED, 'bulk-delete-visibility', 'SKU-DEL-VIS');

        Livewire::test(ListProducts::class)
            ->set('statusView', 'archived')
            ->assertTableBulkActionVisible('bulk_delete');

        foreach (['active', 'draft', 'all'] as $view) {
            Livewire::test(ListProducts::class)
                ->set('statusView', $view)
                ->assertTableBulkActionHidden('bulk_delete');
        }
    }

    public function test_bulk_delete_deletes_the_deletable_products_and_lists_the_refused_ones_with_stage_threes_own_reasons(): void
    {
        $this->staffWithRole('Administrator');

        $deletable = $this->simpleProduct(ProductStatus::ARCHIVED, 'bulk-delete-ok', 'SKU-DEL-OK');

        // A product whose only variation has sale history, and one with stock on
        // hand: both are refusals stage 3's CatalogDeletion owns (§3.19.3/G-D3).
        $withHistory = $this->simpleProduct(ProductStatus::ARCHIVED, 'bulk-delete-history', 'SKU-DEL-HISTORY');
        $this->addSaleLine($this->onlyVariationId((string) $withHistory->id));

        $withStock = $this->simpleProduct(ProductStatus::ARCHIVED, 'bulk-delete-stock', 'SKU-DEL-STOCK');
        $this->addStockLevel($this->onlyVariationId((string) $withStock->id), 4);

        Livewire::test(ListProducts::class)
            ->set('statusView', 'archived')
            ->callTableBulkAction('bulk_delete', [$deletable, $withHistory, $withStock], [
                'understand_permanent' => true,
                // THE DELETABLE COUNT, not the selection size — the number the
                // modal shows and asks the merchant to type (D6).
                'count_confirmation' => 1,
            ]);

        // The deletable one is gone...
        $this->assertFalse($this->productStillExists((string) $deletable->id));

        // ...the refused ones are untouched, rows and all...
        $this->assertTrue($this->productStillExists((string) $withHistory->id));
        $this->assertTrue($this->productStillExists((string) $withStock->id));

        // ...and the notification says which and why, in the SAME sentences the
        // per-row product delete shows.
        $notification = $this->lastNotification();

        $this->assertSame(__('products.bulk.delete_done_title'), $notification['title']);
        $this->assertStringContainsString(
            __('products.bulk.delete_done_body', ['deleted' => 1, 'failed' => 2]),
            $notification['body'],
        );
        $this->assertStringContainsString(
            ProductDeletionRefusalMessage::for(ProductNotDeletableException::becauseVariationsBlockDeletion(
                $withHistory->name,
                [['sku' => 'SKU-DEL-HISTORY', 'count' => 1, 'reason' => VariationDeletionRefusal::HAS_HISTORY]],
            )),
            $notification['body'],
        );
        $this->assertStringContainsString(
            ProductDeletionRefusalMessage::for(ProductNotDeletableException::becauseVariationsBlockDeletion(
                $withStock->name,
                [['sku' => 'SKU-DEL-STOCK', 'count' => 4, 'reason' => VariationDeletionRefusal::HAS_STOCK]],
            )),
            $notification['body'],
        );
    }

    public function test_the_bulk_delete_modal_shows_the_impact_for_the_selection_and_has_no_submit_when_nothing_can_go(): void
    {
        $this->staffWithRole('Administrator');

        $deletable = $this->simpleProduct(ProductStatus::ARCHIVED, 'bulk-modal-ok', 'SKU-MODAL-OK');
        $blocked = $this->simpleProduct(ProductStatus::ARCHIVED, 'bulk-modal-blocked', 'SKU-MODAL-BLOCKED');
        $this->addSaleLine($this->onlyVariationId((string) $blocked->id));

        $component = Livewire::test(ListProducts::class)->set('statusView', 'archived');
        $component->mountTableBulkAction('bulk_delete', [$deletable, $blocked]);

        $mounted = $component->instance()->getMountedAction();
        $this->assertNotNull($mounted);

        $content = (string) $mounted->getModalContent();
        $refusal = ProductDeletionRefusalMessage::for(ProductNotDeletableException::becauseVariationsBlockDeletion(
            $blocked->name,
            [['sku' => 'SKU-MODAL-BLOCKED', 'count' => 1, 'reason' => VariationDeletionRefusal::HAS_HISTORY]],
        ));

        // The counts, the per-product verdict and the reason, before anything
        // happens (D6) — and the number the merchant is asked to type.
        $this->assertStringContainsString(
            e(__('products.bulk.delete_impact_intro', ['selected' => 2, 'deletable' => 1, 'refused' => 1])),
            $content,
        );
        $this->assertStringContainsString(e($refusal), $content);
        $this->assertStringContainsString(
            e(__('products.bulk.delete_impact_type_count', ['count' => 1])),
            $content,
        );

        // One of the two can go, so there IS a submit button.
        $this->assertNotNull($mounted->getModalSubmitAction());

        // Nothing in the selection can go -> no submit button at all, the same
        // "no dead control" posture the per-row delete takes.
        $onlyBlocked = Livewire::test(ListProducts::class)->set('statusView', 'archived');
        $onlyBlocked->mountTableBulkAction('bulk_delete', [$blocked]);

        $blockedMounted = $onlyBlocked->instance()->getMountedAction();
        $this->assertNotNull($blockedMounted);
        $this->assertNull($blockedMounted->getModalSubmitAction());
    }

    public function test_bulk_delete_refuses_a_form_confirmation_that_is_wrong_and_deletes_nothing(): void
    {
        $this->staffWithRole('Administrator');

        $first = $this->simpleProduct(ProductStatus::ARCHIVED, 'bulk-confirm-one', 'SKU-CONFIRM-ONE');
        $second = $this->simpleProduct(ProductStatus::ARCHIVED, 'bulk-confirm-two', 'SKU-CONFIRM-TWO');

        // The count the modal asks for is 2; the merchant typed 5.
        $wrongCount = Livewire::test(ListProducts::class)->set('statusView', 'archived');
        $wrongCount->callTableBulkAction('bulk_delete', [$first, $second], [
            'understand_permanent' => true,
            'count_confirmation' => 5,
        ]);
        $wrongCount->assertHasTableBulkActionErrors(['count_confirmation']);

        // ...and the checkbox was not ticked at all.
        $noCheckbox = Livewire::test(ListProducts::class)->set('statusView', 'archived');
        $noCheckbox->callTableBulkAction('bulk_delete', [$first, $second], [
            'count_confirmation' => 2,
        ]);
        $noCheckbox->assertHasTableBulkActionErrors(['understand_permanent']);

        $this->assertTrue($this->productStillExists((string) $first->id));
        $this->assertTrue($this->productStillExists((string) $second->id));
        $this->assertSame(0, DB::table('activity_log')->where('action', 'deleted')->count());
    }

    /**
     * The SERVER-side half of D6, behind the form: the two confirmations re-checked
     * inside the handler with the FRESH deletable count, so a modal that promised
     * "1" against a product that has since become undeletable cannot go through on
     * a request that skips the form.
     *
     * Invoked through Reflection on purpose: in the UI the form's own rule always
     * runs first (proven above), which is exactly why the handler's re-check has to
     * be provable on its own.
     */
    public function test_the_bulk_delete_confirmation_check_refuses_every_incomplete_combination(): void
    {
        $method = new \ReflectionMethod(ProductResource::class, 'bulkDeletionIsConfirmed');

        $this->assertTrue($method->isPrivate(), 'the confirmation check must not be callable from outside.');

        $confirmed = static fn (array $data, int $deletableCount): bool => (bool) $method->invoke(null, $data, $deletableCount);

        $this->assertTrue($confirmed(['understand_permanent' => true, 'count_confirmation' => 2], 2));
        $this->assertFalse($confirmed(['understand_permanent' => true, 'count_confirmation' => 5], 2));
        $this->assertFalse($confirmed(['count_confirmation' => 2], 2));
        $this->assertFalse($confirmed(['understand_permanent' => false, 'count_confirmation' => 2], 2));
        $this->assertFalse($confirmed(['understand_permanent' => true, 'count_confirmation' => 2], 0));
    }

    public function test_bulk_delete_refuses_more_than_the_fifty_product_limit_and_offers_no_way_through(): void
    {
        $this->staffWithRole('Administrator');

        $records = [];
        for ($index = 1; $index <= 51; $index++) {
            $records[] = $this->simpleProduct(ProductStatus::ARCHIVED, "bulk-limit-{$index}", "SKU-LIMIT-{$index}");
        }

        $component = Livewire::test(ListProducts::class)->set('statusView', 'archived');
        $component->mountTableBulkAction('bulk_delete', $records);

        $mounted = $component->instance()->getMountedAction();
        $this->assertNotNull($mounted);

        // The modal SAYS SO and offers no submit button at all (D7)...
        $this->assertNull($mounted->getModalSubmitAction());
        $this->assertStringContainsString(
            e(__('products.bulk.delete_over_limit', ['selected' => 51, 'limit' => ProductResource::BULK_DELETE_LIMIT])),
            (string) $mounted->getModalContent(),
        );

        // ...and the shared server-side gate refuses the run whatever the form
        // does: at most 50 products per run, refused before a single impact is read.
        $gate = new \ReflectionMethod(ProductResource::class, 'bulkRunIsAllowed');
        $this->assertTrue($gate->isPrivate());
        $this->assertFalse($gate->invoke(
            null,
            new Collection($records),
            Permission::PRODUCT_DELETE,
            'refused',
            ProductResource::BULK_DELETE_LIMIT,
        ));

        // Nothing was deleted.
        $this->assertSame(51, DB::table('catalog_products')->count());
    }

    public function test_the_bulk_limits_are_the_documented_numbers(): void
    {
        // D7's own numbers, asserted rather than implied: they are the whole
        // contract the modal texts and the handlers quote.
        $this->assertSame(500, ProductResource::BULK_STATUS_LIMIT);
        $this->assertSame(50, ProductResource::BULK_DELETE_LIMIT);
    }

    public function test_without_product_manage_the_archive_and_publish_actions_are_hidden_and_the_handler_refuses_when_reached(): void
    {
        $role = Role::create('View Only', [Permission::PRODUCT_VIEW]);
        app(RoleRepository::class)->save($role);
        $staff = Staff::create('view.only@example.com', app(PasswordHasher::class)->hash('password123'), 'View Only', $role);
        app(StaffRepository::class)->save($staff);

        $product = $this->simpleProduct(ProductStatus::ACTIVE, 'bulk-permission-manage', 'SKU-PERM-MANAGE');

        // UI: nothing to click, in any view — the button is not merely disabled.
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');

        foreach (['active', 'draft', 'all'] as $view) {
            Livewire::test(ListProducts::class)
                ->set('statusView', $view)
                ->assertTableBulkActionHidden('bulk_archive');
        }

        Livewire::test(ListProducts::class)
            ->set('statusView', 'draft')
            ->assertTableBulkActionHidden('bulk_publish');

        // SERVER, layer one — the framework's own: a mounted action whose
        // ->visible() is false for the CURRENT staff member does not even resolve
        // any more (InteractsWithActions::resolveTableAction()), so mounting it as
        // an Administrator and calling it after the acting staff loses the
        // permission runs NOTHING at all: no notification, no write, no log.
        $component = Livewire::test(ListProducts::class)->set('statusView', 'all');
        $component->mountTableBulkAction('bulk_archive', [$product]);

        $component->callMountedAction();

        $this->assertSame(ProductStatus::ACTIVE->value, $this->statusOf((string) $product->id));
        $this->assertCount(0, $this->statusLogRows((string) $product->id));

        // SERVER, layer two — the handler's own gate, which is what a call crafted
        // past the framework would reach (invoked through Reflection for exactly
        // that reason: the framework refuses first, so this is the only way to
        // prove the gate underneath it).
        foreach (['archiveSelectedRecords', 'publishSelectedRecords'] as $handler) {
            $method = new \ReflectionMethod(ProductResource::class, $handler);
            $this->assertTrue($method->isPrivate(), "ProductResource::{$handler}() must be private.");

            $method->invoke(null, new Collection([$product]), $component->instance());

            $this->assertSame(ProductStatus::ACTIVE->value, $this->statusOf((string) $product->id));
            $this->assertCount(0, $this->statusLogRows((string) $product->id));
            $this->assertSame(
                __('products.bulk.notification_unauthorized_status'),
                $this->lastNotification()['title'],
            );
        }
    }

    public function test_without_product_delete_the_bulk_delete_is_hidden_and_the_handler_refuses_when_reached(): void
    {
        $product = $this->simpleProduct(ProductStatus::ARCHIVED, 'bulk-permission-delete', 'SKU-PERM-DELETE');

        // A Manager holds PRODUCT_MANAGE but not PRODUCT_DELETE (§3.19.9).
        $this->staffWithRole('Manager');

        Livewire::test(ListProducts::class)
            ->set('statusView', 'archived')
            ->assertTableBulkActionHidden('bulk_delete');

        // SERVER: same two layers — the framework will not resolve the hidden
        // action, and the handler's own gate (its FIRST statement) refuses a call
        // that reaches it anyway.
        $component = Livewire::test(ListProducts::class)->set('statusView', 'archived');
        $component->mountTableBulkAction('bulk_delete', [$product]);
        $component->callMountedAction();

        $this->assertTrue($this->productStillExists((string) $product->id));

        $handler = new \ReflectionMethod(ProductResource::class, 'deleteSelectedRecords');
        $this->assertTrue($handler->isPrivate());

        $handler->invoke(null, new Collection([$product]), [
            'understand_permanent' => true,
            'count_confirmation' => 1,
        ], $component->instance());

        $this->assertTrue($this->productStillExists((string) $product->id));
        $this->assertSame(
            __('products.deletion.product_notification_unauthorized'),
            $this->lastNotification()['title'],
        );
    }

    /**
     * D8's shape, from the outside: every helper the bulk actions call is PRIVATE,
     * no page exposes an id-taking write method, and the public factories the
     * toolbar mounts take no arguments a request could fill with ids.
     */
    public function test_no_public_method_accepts_product_ids_for_the_bulk_operations(): void
    {
        foreach ([
            'archiveSelectedRecords',
            'publishSelectedRecords',
            'deleteSelectedRecords',
            'bulkRunIsAllowed',
            'bulkRefusalLine',
            'notifyBulkResult',
            'bulkDeletionIsConfirmed',
            'bulkDeletionDeletableCount',
            'bulkDeletionImpactView',
            'bulkDeletionConfirmationFields',
            'clearTableSelection',
        ] as $helper) {
            $this->assertTrue(
                (new \ReflectionMethod(ProductResource::class, $helper))->isPrivate(),
                "ProductResource::{$helper}() must be private.",
            );
        }

        foreach (['archiveProducts', 'publishProducts', 'deleteProducts', 'archiveSelected', 'deleteSelected'] as $forbidden) {
            $this->assertFalse(method_exists(ListProducts::class, $forbidden), "ListProducts::{$forbidden}() must not exist.");
        }

        // The public factories the toolbar mounts take no parameters at all: the
        // records they operate on come from Filament's own selection state.
        foreach (['statusViewButtons', 'bulkArchiveAction', 'bulkPublishAction', 'bulkDeleteAction'] as $factory) {
            $this->assertSame(
                [],
                (new \ReflectionMethod(ProductResource::class, $factory))->getParameters(),
                "ProductResource::{$factory}() must take no arguments.",
            );
        }
    }
}
