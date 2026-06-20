<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('media')
            ->where('type', 'doujin')
            ->update([
                'title_romaji' => null,
                'title_native' => null,
            ]);

        Schema::table('media', function ($table) {
            if (Schema::hasColumn('media', 'doujin_source')) {
                $table->dropColumn('doujin_source');
            }

            if (Schema::hasColumn('media', 'doujin_language')) {
                $table->dropColumn('doujin_language');
            }
        });
    }

    public function down(): void
    {
        Schema::table('media', function ($table) {
            if (!Schema::hasColumn('media', 'doujin_source')) {
                $table->string('doujin_source', 32)->nullable()->after('doujin_fanbox_url');
            }

            if (!Schema::hasColumn('media', 'doujin_language')) {
                $table->string('doujin_language', 32)->nullable()->after('doujin_source');
            }
        });
    }
};
