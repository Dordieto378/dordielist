<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('doujin_item_tag');
        Schema::dropIfExists('doujin_tags');
    }

    public function down(): void
    {
        Schema::create('doujin_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('doujin_item_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('media_id');
            $table->unsignedBigInteger('tag_id');

            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('doujin_tags')->cascadeOnDelete();
            $table->primary(['media_id', 'tag_id']);
        });
    }
};
