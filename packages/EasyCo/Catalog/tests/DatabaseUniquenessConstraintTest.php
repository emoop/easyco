<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\VariationSignature;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Proves the actual database-level guarantee behind
 * catalog_variations.attribute_signature — not just the in-memory check in
 * Product::addStandardVariation(). Uses a real SQLite connection (same
 * driver as the project's current .env: DB_CONNECTION=sqlite) with a
 * schema that mirrors the 2026_08_23_000006_create_catalog_variations_table
 * migration's relevant columns/constraints exactly.
 *
 * This is the closest thing to a migration test this sandbox can run
 * without packagist access to pull illuminate/database or
 * orchestra/testbench — see the implementation summary for what still
 * needs verifying against the real Laravel app (MySQL/MariaDB in
 * particular: this proves the *approach*, not byte-for-byte MySQL DDL).
 */
final class DatabaseUniquenessConstraintTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE catalog_products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL
            )
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE catalog_variations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER NOT NULL REFERENCES catalog_products(id),
                type TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'draft',
                attribute_signature CHAR(64) NOT NULL,
                sku TEXT,
                barcode TEXT,
                UNIQUE (product_id, attribute_signature),
                UNIQUE (sku),
                UNIQUE (barcode)
            )
        SQL);

        $this->pdo->exec("INSERT INTO catalog_products (id, type) VALUES (1, 'variable')");
        $this->pdo->exec("INSERT INTO catalog_products (id, type) VALUES (2, 'simple')");
    }

    public function test_inserting_the_same_combination_twice_for_the_same_product_is_rejected_by_the_db(): void
    {
        $signature = VariationSignature::forCombination([1 => 5, 2 => 9])->value();

        $this->insertVariation(productId: 1, type: 'standard', signature: $signature);

        $this->expectException(PDOException::class);
        $this->insertVariation(productId: 1, type: 'standard', signature: $signature);
    }

    public function test_same_combination_is_allowed_for_two_different_products(): void
    {
        $signature = VariationSignature::forCombination([1 => 5, 2 => 9])->value();

        $this->insertVariation(productId: 1, type: 'standard', signature: $signature);

        // Product 2 having the exact same Color:Black/Size:M combination
        // must NOT collide with product 1's — the constraint is scoped to
        // (product_id, attribute_signature), not attribute_signature alone.
        $this->pdo->exec("INSERT INTO catalog_products (id, type) VALUES (3, 'variable')");
        $this->insertVariation(productId: 3, type: 'standard', signature: $signature);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM catalog_variations')->fetchColumn();
        $this->assertSame(2, $count);
    }

    public function test_a_simple_product_can_only_ever_have_one_universal_variation_via_the_same_constraint(): void
    {
        $universalSignature = VariationSignature::forUniversalVariation()->value();

        $this->insertVariation(productId: 2, type: 'universal', signature: $universalSignature);

        // Attempting to insert a second "universal" row for the same
        // product hits the exact same UNIQUE(product_id, attribute_signature)
        // index — no separate constraint was needed for this rule.
        $this->expectException(PDOException::class);
        $this->insertVariation(productId: 2, type: 'universal', signature: $universalSignature);
    }

    public function test_concurrent_insert_race_is_caught_by_the_constraint_not_a_check_then_insert(): void
    {
        // Simulates two "requests" racing to create the same combination:
        // neither does a SELECT-then-INSERT check: both just attempt the
        // INSERT and let the database decide. The second one must fail.
        $signature = VariationSignature::forCombination([1 => 5])->value();

        $this->insertVariation(productId: 1, type: 'standard', signature: $signature);

        $secondRequestSucceeded = true;
        try {
            $this->insertVariation(productId: 1, type: 'standard', signature: $signature);
        } catch (PDOException) {
            $secondRequestSucceeded = false;
        }

        $this->assertFalse($secondRequestSucceeded, 'the DB constraint, not application logic, must be what stops the race');
    }

    public function test_sku_uniqueness_is_enforced_globally_across_products(): void
    {
        $sig1 = VariationSignature::forCombination([1 => 5])->value();
        $sig2 = VariationSignature::forCombination([1 => 6])->value();

        $this->insertVariation(productId: 1, type: 'standard', signature: $sig1, sku: 'SHIRT-BLK');

        $this->expectException(PDOException::class);
        $this->insertVariation(productId: 1, type: 'standard', signature: $sig2, sku: 'SHIRT-BLK');
    }

    public function test_multiple_null_skus_are_allowed_null_is_not_treated_as_a_duplicate(): void
    {
        $sig1 = VariationSignature::forCombination([1 => 5])->value();
        $sig2 = VariationSignature::forCombination([1 => 6])->value();

        $this->insertVariation(productId: 1, type: 'standard', signature: $sig1, sku: null);
        $this->insertVariation(productId: 1, type: 'standard', signature: $sig2, sku: null);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM catalog_variations')->fetchColumn();
        $this->assertSame(2, $count);
    }

    private function insertVariation(int $productId, string $type, string $signature, ?string $sku = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO catalog_variations (product_id, type, attribute_signature, sku) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$productId, $type, $signature, $sku]);
    }
}
