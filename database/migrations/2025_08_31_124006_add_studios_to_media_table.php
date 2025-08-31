<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('media', function (Blueprint $t) {
            if (!Schema::hasColumn('media', 'studios')) {
                $t->json('studios')->nullable()->after('tags');
            }
        });
    }
    public function down(): void {
        Schema::table('media', function (Blueprint $t) {
            if (Schema::hasColumn('media','studios')) {
                $t->dropColumn('studios');
            }
        });
    }
};
