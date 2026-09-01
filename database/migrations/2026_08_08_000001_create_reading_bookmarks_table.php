<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reading_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('media_id');
            $table->unsignedInteger('chapter_id');
            $table->unsignedInteger('page_id');
            $table->unsignedInteger('page_number');
            $table->timestamps();

            $table->unique(['user_id', 'media_id']);
            $table->index(['media_id', 'chapter_id']);

            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->foreign('chapter_id')->references('id')->on('chapters')->cascadeOnDelete();
            $table->foreign('page_id')->references('id')->on('chapter_pages')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_bookmarks');
    }
};
