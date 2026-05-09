<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (!Schema::hasColumn('media', 'doujin_pixiv_url')) {
                $table->string('doujin_pixiv_url', 2048)->nullable()->after('doujin_fanbox_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (Schema::hasColumn('media', 'doujin_pixiv_url')) {
                $table->dropColumn('doujin_pixiv_url');
            }
        });
    }
};
