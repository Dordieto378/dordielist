<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('favorites', function (Blueprint $t) {
            $t->increments('id');
            $t->string('favoritable_type', 50);
            $t->string('favoritable_id', 50);
            $t->string('thumbnail_url')->nullable();
            $t->string('title')->nullable();
            $t->timestamps();

            // Prevent dup favorites (missing in your dump)
            $t->unique(['favoritable_type', 'favoritable_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('favorites');
    }
};
