<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `collection_items` MODIFY `item_id` BIGINT UNSIGNED NOT NULL");

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
        Schema::table('collection_items', function (Blueprint $table) {
            $table->dropForeign('fk_collection_item_media');
        });

        DB::statement("ALTER TABLE `collection_items` MODIFY `item_id` VARCHAR(255) NOT NULL");
    }
};
