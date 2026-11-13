<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MEAS-2: the storage of per-size measurements, see measurements-research.md. Nothing reads these tables yet (admin = MEAS-3/4, storefront = MEAS-5).
     *
     *  - catalog_measures: the merchant's list of measures (a name + ONE unit from the closed list mm|cm|m|in|ft).
     *  - catalog_measurement_templates + catalog_measurement_template_measures: a named, ordered list of measures. The pivot cascades with its template and RESTRICTS the measure (a used measure is archived, not deleted).
     *  - catalog_measurement_values: ONE number per (product, size value, measure), stored as integer hundredths (95.5 = 9550). The size is the attribute VALUE id, not a variation: the colours of one size share the numbers.
     *    Every key column is non-null, so a plain unique is enough (no generated "row slot" column: a SIMPLE product has no size value and therefore no rows).
     *  - catalog_product_measurement_templates: which template a product uses. A SEPARATE table because Product.php (the domain class of catalog_products) is frozen.
     *
     * Explicit short names (CLAUDE.md rule 5): cm_ / cmt_ / cmtm_ / cmv_ / cpmt_. Idempotent per table (rule 6: MySQL DDL is not transactional).
     */
    public function up(): void
    {
        if (! Schema::hasTable('catalog_measures')) {
            Schema::create('catalog_measures', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('unit', 4);
                $table->unsignedInteger('sort')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique('name', 'cm_name_unique');
            });
        }

        if (! Schema::hasTable('catalog_measurement_templates')) {
            Schema::create('catalog_measurement_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->unsignedInteger('sort')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique('name', 'cmt_name_unique');
            });
        }

        if (! Schema::hasTable('catalog_measurement_template_measures')) {
            Schema::create('catalog_measurement_template_measures', function (Blueprint $table) {
                $table->unsignedBigInteger('template_id');
                $table->unsignedBigInteger('measure_id');
                $table->unsignedInteger('sort')->default(0);

                $table->primary(['template_id', 'measure_id'], 'cmtm_pk');
                $table->index('measure_id', 'cmtm_measure_idx');
                $table->foreign('template_id', 'cmtm_template_fk')->references('id')->on('catalog_measurement_templates')->cascadeOnDelete();
                $table->foreign('measure_id', 'cmtm_measure_fk')->references('id')->on('catalog_measures')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('catalog_measurement_values')) {
            Schema::create('catalog_measurement_values', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('size_value_id');
                $table->unsignedBigInteger('measure_id');
                // 0 < value <= 99 999 999 (999 999.99). Unsigned bounds it below in the database; the upper bound and "> 0" are the repository's (no portable CHECK across SQLite/MySQL/MariaDB here).
                $table->unsignedInteger('value_hundredths');
                $table->timestamps();

                $table->unique(['product_id', 'size_value_id', 'measure_id'], 'cmv_cell_unique');
                $table->index('size_value_id', 'cmv_size_value_idx');
                $table->index('measure_id', 'cmv_measure_idx');
                $table->foreign('product_id', 'cmv_product_fk')->references('id')->on('catalog_products')->cascadeOnDelete();
                $table->foreign('size_value_id', 'cmv_size_value_fk')->references('id')->on('catalog_attribute_values')->restrictOnDelete();
                $table->foreign('measure_id', 'cmv_measure_fk')->references('id')->on('catalog_measures')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('catalog_product_measurement_templates')) {
            Schema::create('catalog_product_measurement_templates', function (Blueprint $table) {
                $table->unsignedBigInteger('product_id')->primary();
                $table->unsignedBigInteger('template_id');
                $table->timestamps();

                $table->index('template_id', 'cpmt_template_idx');
                $table->foreign('product_id', 'cpmt_product_fk')->references('id')->on('catalog_products')->cascadeOnDelete();
                // RESTRICT: a template that products use is never deleted (the repository refuses first, with the count).
                $table->foreign('template_id', 'cpmt_template_fk')->references('id')->on('catalog_measurement_templates')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_measurement_templates');
        Schema::dropIfExists('catalog_measurement_values');
        Schema::dropIfExists('catalog_measurement_template_measures');
        Schema::dropIfExists('catalog_measurement_templates');
        Schema::dropIfExists('catalog_measures');
    }
};
