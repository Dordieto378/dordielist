<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            if (!Schema::hasColumn('episodes', 'subtitle_path')) {
                $table->string('subtitle_path')->nullable()->after('thumbnail_path');
            }

            if (!Schema::hasColumn('episodes', 'top_subtitle_path')) {
                $table->string('top_subtitle_path')->nullable()->after('subtitle_path');
            }

            if (!Schema::hasColumn('episodes', 'center_subtitle_path')) {
                $table->string('center_subtitle_path')->nullable()->after('top_subtitle_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            if (Schema::hasColumn('episodes', 'center_subtitle_path')) {
                $table->dropColumn('center_subtitle_path');
            }

            if (Schema::hasColumn('episodes', 'top_subtitle_path')) {
                $table->dropColumn('top_subtitle_path');
            }

            if (Schema::hasColumn('episodes', 'subtitle_path')) {
                $table->dropColumn('subtitle_path');
            }
        });
    }
};
