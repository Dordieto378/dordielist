<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // media table changes
        Schema::table('media', function (Blueprint $table) {
            // rename studios -> publisher
            if (Schema::hasColumn('media', 'studios')) {
                $table->renameColumn('studios', 'publisher');
            }

            // drop columns if they exist
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

        // episodes table changes
        Schema::table('episodes', function (Blueprint $table) {
            if (Schema::hasColumn('episodes', 'media_id')) {
                $table->dropColumn('media_id');
            }
        });
    }

    public function down(): void
    {
        // revert media table changes
        Schema::table('media', function (Blueprint $table) {
            // rename publisher back to studios
            if (Schema::hasColumn('media', 'publisher')) {
                $table->renameColumn('publisher', 'studios');
            }

            // re-add the dropped columns
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

        // revert episodes table changes
        Schema::table('episodes', function (Blueprint $table) {
            if (!Schema::hasColumn('episodes', 'media_id')) {
                $table->unsignedBigInteger('media_id')->nullable();
            }
        });
    }
};
