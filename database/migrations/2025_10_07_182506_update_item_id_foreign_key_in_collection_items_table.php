<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Change column type to BIGINT UNSIGNED (keeps existing column; no DBAL)
        DB::statement("ALTER TABLE `collection_items` MODIFY `item_id` BIGINT UNSIGNED NOT NULL");

        // 2) Add the foreign key to media(id)
        Schema::table('collection_items', function (Blueprint $table) {
            $table->foreign('item_id', 'fk_collection_item_media')
                ->references('id')
                ->on('media')
                ->onDelete('cascade')
                ->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        // Drop FK, revert type back to VARCHAR(255)
        Schema::table('collection_items', function (Blueprint $table) {
            $table->dropForeign('fk_collection_item_media');
        });

        DB::statement("ALTER TABLE `collection_items` MODIFY `item_id` VARCHAR(255) NOT NULL");
    }
};
