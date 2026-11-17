<?php

namespace Tests\Feature\Account;

use EasyCo\Account\Persistence\Eloquent\AccountModel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Account\Concerns\DrivesAccountApi;
use Tests\TestCase;

/** ACC-1 decision 1 (the daily prune) and Part Z (the category label, .gitignore, the old 404 CSS). */
class PruneUnverifiedAccountsTest extends TestCase
{
    use DrivesAccountApi;
    use RefreshDatabase;

    private function aged(AccountModel $account, int $days): AccountModel
    {
        AccountModel::whereKey($account->getKey())->update(['created_at' => now()->subDays($days)]);

        return $account->fresh();
    }

    /** a minimal orders row for an account (only what the foreign key and NOT NULL columns need is read from the schema) */
    private function orderOf(AccountModel $account): void
    {
        $row = ['account_id' => $account->getKey()];

        foreach (DB::select('SHOW COLUMNS FROM orders') as $column) {
            if ($column->Field === 'account_id' || $column->Extra === 'auto_increment' || $column->Null === 'YES' || $column->Default !== null) {
                continue;
            }

            $row[$column->Field] = str_contains($column->Type, 'int') || str_contains($column->Type, 'decimal') ? 1 : (str_contains($column->Type, 'timestamp') || str_contains($column->Type, 'datetime') ? now() : (str_contains($column->Type, 'json') ? '{}' : 'x'));
        }

        // the totals satisfy the table's own check constraint (total = subtotal - discount); the client / transaction rows it points to are not what this test is about
        $row = ['subtotal_minor' => 1, 'discount_minor' => 0, 'total_minor' => 1] + $row;

        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        DB::table('orders')->insert($row);
        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
    }

    /** ★ an unverified account older than 7 days with no order goes; with an order, younger, or verified it stays */
    public function test_unverified_accounts_older_than_seven_days_without_orders_are_deleted(): void
    {
        $old = $this->aged($this->unverifiedAccount('old@example.com'), 8);
        $withOrder = $this->aged($this->unverifiedAccount('ordered@example.com'), 30);
        $this->orderOf($withOrder);
        $young = $this->aged($this->unverifiedAccount('young@example.com'), 6);
        $verified = $this->aged($this->verifiedAccount('verified@example.com'), 400);

        $this->artisan('accounts:prune-unverified')->expectsOutputToContain('Pruned 1 unverified')->assertSuccessful();

        $this->assertNull(AccountModel::withTrashed()->find($old->getKey()), 'deleted for good (its email is free again)');
        $this->assertNotNull(AccountModel::find($withOrder->getKey()), 'an order keeps it');
        $this->assertNotNull(AccountModel::find($young->getKey()));
        $this->assertNotNull(AccountModel::find($verified->getKey()), 'a verified account is never pruned');
    }

    public function test_the_prune_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'accounts:prune-unverified'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }

    // ---- Part Z ---------------------------------------------------------------------------------------------------------------

    public function test_the_category_form_label_is_translated_in_both_languages(): void
    {
        foreach (['bg', 'en'] as $locale) {
            $this->assertNotSame('categories.fields.header_block', __('categories.fields.header_block', [], $locale), $locale);
        }
        $this->assertSame('Блок отгоре', __('categories.fields.header_block', [], 'bg'));
        $this->assertSame('Block on top', __('categories.fields.header_block', [], 'en'));
    }

    public function test_the_shard_config_is_ignored_by_git_and_the_old_404_rules_are_gone(): void
    {
        $this->assertStringContainsString("\nphpunit.shard.xml", str_replace('/phpunit.shard.xml', "\nphpunit.shard.xml", "\n".file_get_contents(base_path('.gitignore'))));
        $this->assertMatchesRegularExpression('~^/?phpunit\.shard\.xml\s*$~m', file_get_contents(base_path('.gitignore')));
        $this->assertStringNotContainsString('.sf-404', file_get_contents(resource_path('css/storefront.css')));
    }
}
