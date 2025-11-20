<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // If you don't have doctrine/dbal installed, use the raw SQL line instead (kept below).
        Schema::table('chapters', function (Blueprint $table) {
            // Make chapter_number nullable so we can null it out
            // NOTE: requires doctrine/dbal to use ->change()
            $table->decimal('chapter_number', 8, 2)->nullable()->change();
        });

        // If you don't have doctrine/dbal, comment the block above and uncomment this:
        // DB::statement('ALTER TABLE chapters MODIFY chapter_number DECIMAL(8,2) NULL');

        // Null out all numbers so the old unique(media_fk, chapter_number) stops biting
        DB::table('chapters')->update(['chapter_number' => null]);

        // Add the new, correct unique constraint
        Schema::table('chapters', function (Blueprint $table) {
            $table->unique(['media_fk', 'chapter_title'], 'chapters_media_fk_chapter_title_unique');
        });
    }

    public function down(): void
    {
        // Drop the new unique
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropUnique('chapters_media_fk_chapter_title_unique');
        });

        // Optionally revert chapter_number to NOT NULL (type may differ in your original)
        // If you had it as integer before, use integer; otherwise keep decimal.
        // Requires doctrine/dbal for change():
        // Schema::table('chapters', function (Blueprint $table) {
        //     $table->decimal('chapter_number', 8, 2)->nullable(false)->change();
        // });

        // Raw SQL fallback if no dbal:
        // DB::statement('ALTER TABLE chapters MODIFY chapter_number DECIMAL(8,2) NOT NULL');
    }
};
