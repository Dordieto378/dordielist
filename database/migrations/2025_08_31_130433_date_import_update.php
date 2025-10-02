<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('media', function (Blueprint $t) {
            if (!Schema::hasColumn('media', 'year'))            $t->unsignedSmallInteger('year')->nullable()->after('status');
            if (!Schema::hasColumn('media', 'start_date'))      $t->date('start_date')->nullable()->after('year'); // release date
            if (!Schema::hasColumn('media', 'list_status'))     $t->string('list_status', 32)->nullable()->after('status'); // CURRENT, COMPLETED...
            if (!Schema::hasColumn('media', 'media_status'))    $t->string('media_status', 32)->nullable()->after('list_status'); // FINISHED, RELEASING...
            if (!Schema::hasColumn('media', 'user_score'))      $t->unsignedSmallInteger('user_score')->nullable()->after('media_status');
            if (!Schema::hasColumn('media', 'avg_score'))       $t->unsignedSmallInteger('avg_score')->nullable()->after('user_score');
            if (!Schema::hasColumn('media', 'studios'))         $t->json('studios')->nullable()->after('tags');
            if (!Schema::hasColumn('media', 'list_created_at')) $t->timestamp('list_created_at')->nullable()->after('updated_at'); // when you added to list
            if (!Schema::hasColumn('media', 'list_updated_at')) $t->timestamp('list_updated_at')->nullable()->after('list_created_at'); // last updated on list
        });
    }

    public function down(): void {
        Schema::table('media', function (Blueprint $t) {
            foreach ([
                'year','start_date','list_status','media_status',
                'user_score','avg_score','studios','list_created_at','list_updated_at'
            ] as $col) {
                if (Schema::hasColumn('media', $col)) $t->dropColumn($col);
            }
        });
    }
};
