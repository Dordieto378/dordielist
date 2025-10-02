<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // add languages column if it doesn't exist
            if (!Schema::hasColumn('media', 'languages')) {
                $table->longText('languages')->nullable()->after('source_id');
            }

            // drop updated_at if it exists
            if (Schema::hasColumn('media', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // remove languages column if exists
            if (Schema::hasColumn('media', 'languages')) {
                $table->dropColumn('languages');
            }

            // re-add updated_at if missing
            if (!Schema::hasColumn('media', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }
};
