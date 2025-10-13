<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('doujins', function (Blueprint $t) {
            $t->increments('id');
            $t->string('author_name');
            $t->string('doujin_name');
            $t->string('folder')->unique();
            $t->string('cover_url');
            $t->timestamps();
        });

        Schema::create('doujin_pages', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('doujin_id');
            $t->unsignedInteger('page_number');
            $t->string('file_path');
            $t->timestamps();

            $t->foreign('doujin_id')
              ->references('id')->on('doujins')
              ->cascadeOnDelete()->cascadeOnUpdate();
        });
    }
    public function down(): void {
        Schema::dropIfExists('doujin_pages');
        Schema::dropIfExists('doujins');
    }
};
