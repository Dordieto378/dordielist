<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->setMediaTypeEnum(['anime', 'hentai', 'manga', 'manhwa', 'light_novel', 'doujin', 'vn']);

        Schema::table('chapters', function (Blueprint $table) {
            if (!Schema::hasColumn('chapters', 'thumbnail_path')) {
                $table->string('thumbnail_path')->nullable()->after('chapter_title');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('media') && Schema::hasColumn('media', 'type')) {
            DB::table('media')->where('type', 'light_novel')->update(['type' => 'manga']);
        }

        if (Schema::hasTable('chapters') && Schema::hasColumn('chapters', 'item_type')) {
            DB::table('chapters')->where('item_type', 'LIGHT_NOVEL')->update(['item_type' => 'MANGA']);
            DB::table('chapters')->where('item_type', 'light_novel')->update(['item_type' => 'manga']);
        }

        Schema::table('chapters', function (Blueprint $table) {
            if (Schema::hasColumn('chapters', 'thumbnail_path')) {
                $table->dropColumn('thumbnail_path');
            }
        });

        $this->setMediaTypeEnum(['anime', 'hentai', 'manga', 'manhwa', 'doujin', 'vn']);
    }

    private function setMediaTypeEnum(array $types): void
    {
        if (!Schema::hasTable('media') || !Schema::hasColumn('media', 'type')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $quotedTypes = implode(',', array_map(
            static fn (string $type): string => "'".str_replace("'", "''", $type)."'",
            $types
        ));

        DB::statement("ALTER TABLE media MODIFY COLUMN type ENUM($quotedTypes) NOT NULL");
    }
};
