<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HB-5: `html_blocks.kind` (`text` = the block as it always was, `card` = «Снимка с текст») and `html_blocks.card`, a nullable JSON object (the card's validated settings). Every existing row gets
     * `kind = 'text'` from the column default and `card = null`: nothing is changed or deleted. Idempotent (CLAUDE.md rule 6): a column that is already there is left alone.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('html_blocks', 'kind')) {
            Schema::table('html_blocks', function (Blueprint $table) {
                $table->string('kind', 20)->default('text')->after('title');
            });
        }

        if (! Schema::hasColumn('html_blocks', 'card')) {
            Schema::table('html_blocks', function (Blueprint $table) {
                $table->json('card')->nullable()->after('conditions');
            });
        }
    }

    public function down(): void
    {
        foreach (['card', 'kind'] as $column) {
            if (Schema::hasColumn('html_blocks', $column)) {
                Schema::table('html_blocks', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
