<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\TestCase;

/**
 * The contract of Tests\Concerns\RestoresSchemaWithoutRemigrating:
 * a clean restore matches the fingerprint (and may skip the re-migration);
 * a leftover row or a leftover column does not (and must keep the safety net).
 *
 * The class also wears the trait the way a DDL test class is meant to: fingerprint
 * in setUp(), re-check + re-arm at teardown.
 */
class RestoresSchemaWithoutRemigratingTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureDatabaseFingerprint();
    }

    protected function tearDown(): void
    {
        $this->databaseMatchesFingerprint();

        parent::tearDown();

        $this->skipRemigrationIfClean();
    }

    public function test_a_clean_restore_matches_the_fingerprint(): void
    {
        $this->assertTrue($this->databaseMatchesFingerprint());
        $this->assertSame([], $this->databaseFingerprintDifferences);
    }

    public function test_a_leftover_row_does_not_match_the_fingerprint(): void
    {
        $zone = $this->zone('Leftover', 0);

        try {
            $this->assertFalse($this->databaseMatchesFingerprint());
            $this->assertStringContainsString('shipping_zones', implode('; ', $this->databaseFingerprintDifferences));
        } finally {
            DB::table('shipping_zones')->where('id', $zone->id())->delete();
        }
    }

    public function test_a_leftover_column_does_not_match_the_fingerprint(): void
    {
        Schema::table('shipping_zones', function (Blueprint $table): void {
            $table->boolean('t3_leftover')->default(false);
        });

        try {
            $this->assertFalse($this->databaseMatchesFingerprint());
            $this->assertStringContainsString('shipping_zones', implode('; ', $this->databaseFingerprintDifferences));
        } finally {
            Schema::table('shipping_zones', function (Blueprint $table): void {
                $table->dropColumn('t3_leftover');
            });
        }
    }

    public function test_skip_remigration_rearms_the_flag_only_on_a_clean_verdict(): void
    {
        // A clean verdict re-arms the flag for the next test...
        $this->assertTrue($this->databaseMatchesFingerprint());
        RefreshDatabaseState::$migrated = false;
        $this->skipRemigrationIfClean();
        $this->assertTrue(RefreshDatabaseState::$migrated);

        // ...a dirty verdict leaves it false, so the next test still self-heals.
        $zone = $this->zone('Leftover', 0);

        try {
            $this->assertFalse($this->databaseMatchesFingerprint());
            RefreshDatabaseState::$migrated = false;
            $this->skipRemigrationIfClean();
            $this->assertFalse(RefreshDatabaseState::$migrated);
        } finally {
            DB::table('shipping_zones')->where('id', $zone->id())->delete();
        }
    }
}
