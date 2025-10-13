<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `favorites` MODIFY `favoritable_id` BIGINT UNSIGNED NOT NULL");

        Schema::table('favorites', function (Blueprint $table) {
            $table->foreign('favoritable_id', 'favorites_item_media')
                ->references('id')
                ->on('media')
                ->onDelete('cascade')
                ->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('favorites', function (Blueprint $table) {
            $table->dropForeign('fk_favorites_item_media');
        });

        DB::statement("ALTER TABLE `favorites` MODIFY `favoritable_id` VARCHAR(255) NOT NULL");
    }
};
