<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('chapters', function (Blueprint $t) {
            $t->increments('id');
            // Keep legacy shape for now but we’ll add a FK to media below
            $t->string('item_type', 50);
            $t->unsignedInteger('item_id');
            $t->unsignedInteger('chapter_number');
            $t->timestamps();
        });

        Schema::create('chapter_pages', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('chapter_id');
            $t->unsignedInteger('chapter_number'); // you have this in dump
            $t->string('file_path');
            $t->timestamps();

            $t->foreign('chapter_id')
              ->references('id')->on('chapters')
              ->cascadeOnDelete()->cascadeOnUpdate();

            // Optional guard rail if you want: one page per (chapter, page_number)
            // $t->unique(['chapter_id', 'page_number']);
        });

        // Optional: if your dump also had a legacy `pages` table, we skip creating it.
        // (In your export, both `pages` and `chapter_pages` existed—prefer `chapter_pages`.)
        // :contentReference[oaicite:4]{index=4}
    }
    public function down(): void {
        Schema::dropIfExists('chapter_pages');
        Schema::dropIfExists('chapters');
    }
};
