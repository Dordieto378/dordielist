<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('media', function (Blueprint $t) {
            if (!Schema::hasColumn('media', 'genres')) $t->json('genres')->nullable()->after('alt_title');
            if (!Schema::hasColumn('media', 'tags'))   $t->json('tags')->nullable()->after('genres');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $t) {
            $t->dropColumn(['genres','tags']);
        });
    }
};
