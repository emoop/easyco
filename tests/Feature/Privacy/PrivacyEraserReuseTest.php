<?php

namespace Tests\Feature\Privacy;

use App\Mail\SendAccountMailJob;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyEraser;
use App\Privacy\PrivacyHash;
use App\Privacy\PrivacySource;
use App\Privacy\StaffActor;
use EasyCo\Extensibility\Hook;
use EasyCo\Extensibility\HookRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InsertsCustomerOrders;
use Tests\TestCase;

/**
 * ACC-4e-2 (acc-4-gdpr-design.md section 10): «Забрави» takes the CONFIRMED erase request as its log row (one conditional update that sets the status AND the reason), never an export, waiting or expired
 * row, falls through to a new row when it loses the race, and a double submit within DOUBLE_SUBMIT_SECONDS writes nothing the second time.
 */
class PrivacyEraserReuseTest extends TestCase
{
    use InsertsCustomerOrders;
    use RefreshDatabase;

    private int $hookCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        app(HookRegistry::class)->clear('customer.erased');
        $this->hookCalls = 0;
        Hook::action('customer.erased', function (): void {
            $this->hookCalls++;
        });
    }

    protected function tearDown(): void
    {
        app(HookRegistry::class)->clear('customer.erased');
        parent::tearDown();
    }

    private function hash(string $email = 'me@example.com'): string
    {
        return (string) app(PrivacyHash::class)->email($email);
    }

    /** @param array<string, mixed> $o */
    private function row(array $o = []): PrivacyRequestModel
    {
        return PrivacyRequestModel::query()->create($o + [
            'kind' => 'erase', 'source' => 'admin', 'status' => 'confirmed', 'email' => 'me@example.com', 'email_hash' => $this->hash(),
            'confirmed_at' => now()->subHours(2), 'staff_id' => 3, 'staff_name' => 'Asker',
        ]);
    }

    private function erase(string $reason = 'The customer asked by email'): PrivacyRequestModel
    {
        return app(PrivacyEraser::class)->erase('me@example.com', PrivacySource::ADMIN, new StaffActor(9, 'Admin Nine'), $reason);
    }

    private function person(): int
    {
        $account = $this->insertAccount('me@example.com');
        DB::table('addresses')->insert(['account_id' => $account, 'delivery_type' => 'street_address', 'recipient_first_name' => 'a', 'recipient_last_name' => 'b', 'phone' => '+359888111111', 'country' => 'BG', 'city' => 'X', 'postal_code' => '1', 'address_line_1' => 'Y', 'created_at' => now(), 'updated_at' => now()]);

        return $account;
    }

    // ---- the confirmed row is the log row ------------------------------------------------------------------------------------------

    /** ★ a confirmed erase row is taken: one row, the reason written with the status change, confirmed_at and the asker kept */
    public function test_a_confirmed_erase_row_is_the_log_row(): void
    {
        $this->person();
        $confirmed = $this->row();
        $confirmedAt = $confirmed->confirmed_at->toDateTimeString();

        $returned = $this->erase('Customer wrote to privacy@shop');

        $this->assertSame(1, PrivacyRequestModel::query()->count(), 'no second row');
        $this->assertSame($confirmed->getKey(), $returned->getKey());

        $row = $confirmed->fresh();
        $this->assertSame('done', $row->status);
        $this->assertSame('Customer wrote to privacy@shop', $row->reason);
        $this->assertSame(9, (int) $row->staff_id, 'the staff member who presses «Забрави»');
        $this->assertSame('Admin Nine', $row->staff_name);
        $this->assertSame('admin', $row->source);
        $this->assertSame($confirmedAt, $row->confirmed_at->toDateTimeString(), 'the person\'s own confirmation stays');
        $this->assertNotNull($row->completed_at);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $row->clear_until->getTimestamp(), 5);
        $this->assertArrayHasKey('account_deleted', $row->actions);
        $this->assertSame(1, $this->hookCalls);
        Queue::assertPushed(SendAccountMailJob::class, fn (SendAccountMailJob $job): bool => $job->templateKey === 'privacy.erased');
    }

    /** ★ the status and the reason move in ONE statement: prq_reason_check would refuse `processing` without a reason */
    public function test_the_transition_is_one_statement_with_the_reason(): void
    {
        $this->person();
        $this->row();
        $updates = [];
        DB::listen(function ($query) use (&$updates): void {
            str_starts_with($query->sql, 'update `privacy_requests`') && $updates[] = $query->sql;
        });

        $this->erase();

        $first = $updates[0];
        $this->assertStringContainsString('`status`', $first);
        $this->assertStringContainsString('`reason`', $first, 'the reason is in the same statement as the status change');
        $this->assertStringContainsString('`status` = ?', (string) preg_replace('/.*where/s', '', $first).' `status` = ?', 'conditional on the status');
        $this->assertMatchesRegularExpression('/where .*`status` = \?/s', $first, 'WHERE id = ? AND status = confirmed');
    }

    /** an export row, a waiting row and an expired row are NEVER taken for an erase */
    public function test_other_rows_are_never_taken(): void
    {
        $this->person();
        $export = $this->row(['kind' => 'export', 'status' => 'confirmed']);
        $waiting = $this->row(['status' => 'awaiting_confirmation', 'confirmed_at' => null]);
        $expired = $this->row(['status' => 'expired', 'confirmed_at' => null, 'email' => null]);

        $returned = $this->erase();

        $this->assertNotContains($returned->getKey(), [$export->getKey(), $waiting->getKey(), $expired->getKey()]);
        $this->assertSame(4, PrivacyRequestModel::query()->count(), 'a NEW row');
        $this->assertSame('confirmed', $export->fresh()->status);
        $this->assertSame('awaiting_confirmation', $waiting->fresh()->status);
        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertNull($waiting->fresh()->reason);
        $this->assertSame('done', $returned->status);
    }

    /** a confirmed erase row of ANOTHER email is not this person's */
    public function test_the_confirmed_row_of_another_email_is_not_taken(): void
    {
        $this->person();
        $other = $this->row(['email' => 'other@example.com', 'email_hash' => $this->hash('other@example.com')]);

        $this->erase();

        $this->assertSame('confirmed', $other->fresh()->status);
        $this->assertSame(2, PrivacyRequestModel::query()->count());
    }

    /** a lost race (another click took the row between the read and the update) falls through to a new row */
    public function test_a_lost_race_falls_through_to_a_new_row(): void
    {
        $this->person();
        $confirmed = $this->row();
        $raced = false;

        // right after the eraser READ the confirmed row, another click moves it on
        DB::listen(function ($query) use ($confirmed, &$raced): void {
            if (! $raced && str_starts_with($query->sql, 'select `id` from `privacy_requests`') && str_contains($query->sql, '`status` = ?')) {
                $raced = true;
                DB::table('privacy_requests')->where('id', $confirmed->getKey())->update(['status' => 'processing', 'reason' => 'the other click']);
            }
        });

        $returned = $this->erase();

        $this->assertTrue($raced);
        $this->assertNotSame($confirmed->getKey(), $returned->getKey(), 'a new row');
        $this->assertSame(2, PrivacyRequestModel::query()->count());
        $this->assertSame('the other click', $confirmed->fresh()->reason, 'the row the other click took is left alone');
        $this->assertSame('done', $returned->status);
    }

    /** a failure after the row was taken rolls it back to `confirmed` (the whole erase is one transaction) */
    public function test_a_failure_puts_the_confirmed_row_back(): void
    {
        $this->person();
        $confirmed = $this->row();
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'delete from `customer_notes`')) {
                throw new \RuntimeException('boom');
            }
        });

        try {
            $this->erase();
            $this->fail('the erase must fail');
        } catch (\Throwable) {
            // rolled back
        }

        $this->assertSame('confirmed', $confirmed->fresh()->status);
        $this->assertNull($confirmed->fresh()->reason);
    }

    // ---- no double run -------------------------------------------------------------------------------------------------------------

    /** ★ a double submit inside DOUBLE_SUBMIT_SECONDS: the second returns the first row and writes nothing (no row, no hook, no mail) */
    public function test_a_double_submit_within_60_seconds_writes_nothing_the_second_time(): void
    {
        $this->person();
        $this->row();

        $first = $this->erase();
        $second = $this->erase();

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, PrivacyRequestModel::query()->count());
        $this->assertSame(1, $this->hookCalls, 'the hook fired once');
        Queue::assertPushed(SendAccountMailJob::class, 1);
        $this->assertSame(1, DB::table('privacy_suppressions')->where('kind', 'email')->count());
    }

    /** a later DELIBERATE «Забрави» writes a new row and is idempotent (no duplicate-key error) */
    public function test_a_later_erase_writes_a_new_row_without_a_duplicate_key_error(): void
    {
        $this->person();
        $first = $this->erase();

        $this->travel(PrivacyEraser::DOUBLE_SUBMIT_SECONDS + 1)->seconds();

        try {
            $second = $this->erase('Asked again');
        } catch (QueryException $e) {
            $this->fail('the erase must be idempotent: '.$e->getMessage());
        }

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(2, PrivacyRequestModel::query()->where('kind', 'erase')->count());
        $this->assertSame(1, DB::table('privacy_suppressions')->where('kind', 'email')->count(), 'one suppression per hash');
        $this->assertSame(2, $this->hookCalls);
    }

    /** inside the window, a person who CAME BACK (a new account, a note, a new order) is erased again */
    public function test_something_new_since_the_last_erase_is_erased_even_inside_the_window(): void
    {
        $this->person();
        $first = $this->erase();

        $this->insertAccount('me@example.com');
        $second = $this->erase('Came back');
        $this->assertNotSame($first->getKey(), $second->getKey(), 'a new account');

        DB::table('customer_notes')->insert(['email_key' => 'me@example.com', 'body' => 'new note', 'created_at' => now(), 'updated_at' => now()]);
        $third = $this->erase('Again');
        $this->assertNotSame($second->getKey(), $third->getKey(), 'a new note');

        $this->travel(2)->seconds();
        $this->insertOrder(['email' => 'me@example.com', 'placed_at' => now()->toDateTimeString()]);
        $fourth = $this->erase('Ordered again');
        $this->assertNotSame($third->getKey(), $fourth->getKey(), 'a new order since');
    }
}
