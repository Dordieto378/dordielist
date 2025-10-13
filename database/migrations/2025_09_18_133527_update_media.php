<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (!Schema::hasColumn('media', 'languages')) {
                $table->longText('languages')->nullable()->after('source_id');
            }

            if (Schema::hasColumn('media', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (Schema::hasColumn('media', 'languages')) {
                $table->dropColumn('languages');
            }

            if (!Schema::hasColumn('media', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }
};
