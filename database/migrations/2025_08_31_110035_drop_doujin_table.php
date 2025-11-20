<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::dropIfExists('doujin_pages');
        Schema::dropIfExists('doujins');
    }

    public function down(): void {
        // optional: recreate if needed
        Schema::create('doujins', function ($t) {
            $t->increments('id');
            $t->string('author_name');
            $t->string('doujin_name');
            $t->string('folder')->unique();
            $t->string('cover_url');
            $t->timestamps();
        });

        Schema::create('doujin_pages', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('doujin_id');
            $t->unsignedInteger('page_number');
            $t->string('file_path');
            $t->timestamps();
        });
    }
};
