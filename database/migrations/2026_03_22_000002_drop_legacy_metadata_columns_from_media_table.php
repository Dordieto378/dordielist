<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $columns = [];

            foreach (['genres', 'tags', 'publisher', 'languages'] as $column) {
                if (Schema::hasColumn('media', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (!Schema::hasColumn('media', 'genres')) {
                $table->json('genres')->nullable();
            }
            if (!Schema::hasColumn('media', 'tags')) {
                $table->json('tags')->nullable();
            }
            if (!Schema::hasColumn('media', 'publisher')) {
                $table->json('publisher')->nullable();
            }
            if (!Schema::hasColumn('media', 'languages')) {
                $table->longText('languages')->nullable();
            }
        });
    }
};
