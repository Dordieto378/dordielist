<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('chapters', function (Blueprint $t) {
            $t->increments('id');
            $t->string('item_type', 50);
            $t->unsignedInteger('item_id');
            $t->unsignedInteger('chapter_number');
            $t->timestamps();
        });

        Schema::create('chapter_pages', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('chapter_id');
            $t->unsignedInteger('chapter_number');
            $t->string('file_path');
            $t->timestamps();

            $t->foreign('chapter_id')
              ->references('id')->on('chapters')
              ->cascadeOnDelete()->cascadeOnUpdate();
        });
    }
    public function down(): void {
        Schema::dropIfExists('chapter_pages');
        Schema::dropIfExists('chapters');
    }
};
