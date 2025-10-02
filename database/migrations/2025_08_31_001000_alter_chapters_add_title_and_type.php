<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('chapters', function (Blueprint $t) {
            $t->string('chapter_title')->nullable()->after('chapter_number');
            $t->enum('content_type', ['manga','doujin'])->nullable()->after('chapter_title');
        });
    }
    public function down(): void {
        Schema::table('chapters', function (Blueprint $t) {
            $t->dropColumn(['chapter_title','content_type']);
        });
    }
};
