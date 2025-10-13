<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('chapters', function (Blueprint $t) {
            $t->unsignedBigInteger('media_fk')->nullable()->after('id');
            $t->foreign('media_fk')->references('id')->on('media')->cascadeOnDelete();
            $t->unique(['media_fk', 'chapter_number']);
        });

        Schema::table('episodes', function (Blueprint $t) {
            $t->unsignedBigInteger('media_fk')->nullable()->after('id');
            $t->foreign('media_fk')->references('id')->on('media')->cascadeOnDelete();
            $t->unique(['media_fk', 'episode_number']);
        });
    }

    public function down(): void {
        Schema::table('episodes', function (Blueprint $t) {
            $t->dropUnique(['media_fk', 'episode_number']);
            $t->dropConstrainedForeignId('media_fk');
            $t->dropColumn('media_fk');
        });

        Schema::table('chapters', function (Blueprint $t) {
            $t->dropUnique(['media_fk', 'chapter_number']);
            $t->dropConstrainedForeignId('media_fk');
            $t->dropColumn('media_fk');
        });
    }
};
