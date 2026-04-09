<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anilist_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('anilist_id')->unique();
            $table->string('type')->nullable();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->string('url')->nullable();
            $table->string('image_url')->nullable();
            $table->unsignedBigInteger('anilist_media_id')->nullable()->index();
            $table->boolean('is_read')->default(false);
            $table->json('payload')->nullable();
            $table->timestamp('notified_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anilist_notifications');
    }
};
