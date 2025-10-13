<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('media', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->enum('type', ['anime','manga','doujin','vn']);
            $t->string('title_english')->nullable();
            $t->string('title_romaji')->nullable();
            $t->string('slug')->unique();
            $t->string('cover_url')->nullable();
            $t->string('banner_url')->nullable();
            $t->mediumText('description')->nullable();
            $t->json('genres')->nullable();
            $t->json('tags')->nullable();
            $t->char('origin', 2)->nullable();
            $t->string('status', 32)->nullable();
            $t->unsignedSmallInteger('episodes_cnt')->nullable();
            $t->unsignedSmallInteger('chapters_cnt')->nullable();
            $t->unsignedSmallInteger('volumes_cnt')->nullable();
            $t->string('source')->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->timestamps();

            $t->unique(['source', 'source_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('media');
    }
};
