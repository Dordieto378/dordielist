<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('media', function (Blueprint $table) {
            $table->string('launch_rel_exe')->nullable();
            $table->text('launch_args')->nullable();
        });
    }
    public function down(): void {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['launch_rel_exe','launch_args']);
        });
    }
};
