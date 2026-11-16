<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reading_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('media_id');
            $table->unsignedInteger('chapter_id');
            $table->unsignedInteger('page_id');
            $table->unsignedInteger('page_number');
            $table->string('view', 10)->default('one');
            $table->double('scroll_offset')->default(0);
            $table->unsignedInteger('spread_start')->nullable();
            $table->unsignedBigInteger('recorded_at')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'chapter_id']);
            $table->index(['user_id', 'media_id', 'updated_at']);
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->foreign('chapter_id')->references('id')->on('chapters')->cascadeOnDelete();
            $table->foreign('page_id')->references('id')->on('chapter_pages')->cascadeOnDelete();
        });

        // Keep the locations saved with the previous manual bookmark system.
        DB::table('reading_progress')->insertUsing(
            ['user_id', 'media_id', 'chapter_id', 'page_id', 'page_number', 'created_at', 'updated_at'],
            DB::table('reading_bookmarks')->select('user_id', 'media_id', 'chapter_id', 'page_id', 'page_number', 'created_at', 'updated_at')
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_progress');
    }
};
