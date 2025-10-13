<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (Schema::hasColumn('media', 'studios')) {
                $table->renameColumn('studios', 'publisher');
            }

            if (Schema::hasColumn('media', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('media', 'created_at')) {
                $table->dropColumn('created_at');
            }
            if (Schema::hasColumn('media', 'list_created_at')) {
                $table->dropColumn('list_created_at');
            }
            if (Schema::hasColumn('media', 'list_updated_at')) {
                $table->dropColumn('list_updated_at');
            }
        });

        Schema::table('episodes', function (Blueprint $table) {
            if (Schema::hasColumn('episodes', 'media_id')) {
                $table->dropColumn('media_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (Schema::hasColumn('media', 'publisher')) {
                $table->renameColumn('publisher', 'studios');
            }

            if (!Schema::hasColumn('media', 'status')) {
                $table->string('status')->nullable();
            }
            if (!Schema::hasColumn('media', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('media', 'list_created_at')) {
                $table->timestamp('list_created_at')->nullable();
            }
            if (!Schema::hasColumn('media', 'list_updated_at')) {
                $table->timestamp('list_updated_at')->nullable();
            }
        });

        Schema::table('episodes', function (Blueprint $table) {
            if (!Schema::hasColumn('episodes', 'media_id')) {
                $table->unsignedBigInteger('media_id')->nullable();
            }
        });
    }
};
