<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->unique()->constrained('media')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->unsignedBigInteger('file_size');
            $table->timestamps();
        });

        Schema::dropIfExists('episodes');
    }

    public function down(): void
    {
        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_fk')->nullable()->constrained('media')->cascadeOnDelete();
            $table->string('media_type');
            $table->unsignedInteger('episode_number');
            $table->string('file_path');
            $table->string('thumbnail_path')->nullable();
            $table->string('subtitle_path')->nullable();
            $table->string('top_subtitle_path')->nullable();
            $table->string('center_subtitle_path')->nullable();
            $table->timestamps();

            $table->unique(['media_fk', 'episode_number']);
            $table->index(['media_type', 'media_fk']);
        });

        Schema::dropIfExists('media_archives');
    }
};
