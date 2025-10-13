<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->decimal('chapter_number', 8, 2)->nullable()->change();
        });

        DB::table('chapters')->update(['chapter_number' => null]);

        Schema::table('chapters', function (Blueprint $table) {
            $table->unique(['media_fk', 'chapter_title'], 'chapters_media_fk_chapter_title_unique');
        });
    }

    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropUnique('chapters_media_fk_chapter_title_unique');
        });
    }
};
