<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('media', function (Blueprint $t) {
            // release/start year
            $t->unsignedSmallInteger('year')->nullable()->after('status');
            // (optional but recommended – if you sort by these anywhere)
            // $t->unsignedTinyInteger('avg_score')->nullable()->after('year');
            // $t->unsignedTinyInteger('user_score')->nullable()->after('avg_score');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $t) {
            $t->dropColumn('year');
            // $t->dropColumn(['avg_score','user_score']);
        });
    }
};
