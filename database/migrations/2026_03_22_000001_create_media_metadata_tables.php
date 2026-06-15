<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createIfMissing('anilist_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->nullable()->index();
            $table->unsignedBigInteger('source_id')->nullable()->unique();
            $table->timestamps();
        });

        $this->createIfMissing('anilist_item_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('media_id');
            $table->unsignedBigInteger('tag_id');

            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('anilist_tags')->cascadeOnDelete();
            $table->primary(['media_id', 'tag_id']);
        });

        $this->createIfMissing('anilist_genres', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->nullable()->index();
            $table->timestamps();
        });

        $this->createIfMissing('anilist_item_genre', function (Blueprint $table) {
            $table->unsignedBigInteger('media_id');
            $table->unsignedBigInteger('genre_id');

            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->foreign('genre_id')->references('id')->on('anilist_genres')->cascadeOnDelete();
            $table->primary(['media_id', 'genre_id']);
        });

        $this->createIfMissing('anilist_studios', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->nullable()->index();
            $table->unsignedBigInteger('source_id')->nullable()->unique();
            $table->timestamps();
        });

        $this->createIfMissing('anilist_item_studio', function (Blueprint $table) {
            $table->unsignedBigInteger('media_id');
            $table->unsignedBigInteger('studio_id');

            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->foreign('studio_id')->references('id')->on('anilist_studios')->cascadeOnDelete();
            $table->primary(['media_id', 'studio_id']);
        });

        $this->createIfMissing('anilist_authors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->nullable()->index();
            $table->unsignedBigInteger('source_id')->nullable()->unique();
            $table->timestamps();
        });

        $this->createIfMissing('anilist_item_author', function (Blueprint $table) {
            $table->unsignedBigInteger('media_id');
            $table->unsignedBigInteger('author_id');

            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on('anilist_authors')->cascadeOnDelete();
            $table->primary(['media_id', 'author_id']);
        });

        $this->createIfMissing('doujin_authors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->nullable()->index();
            $table->timestamps();
        });

        $this->createIfMissing('doujin_item_author', function (Blueprint $table) {
            $table->unsignedBigInteger('media_id');
            $table->unsignedBigInteger('author_id');

            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on('doujin_authors')->cascadeOnDelete();
            $table->primary(['media_id', 'author_id']);
        });

        $this->createIfMissing('vn_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->nullable()->index();
            $table->timestamps();
        });

        $this->createIfMissing('vn_item_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('media_id');
            $table->unsignedBigInteger('tag_id');

            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('vn_tags')->cascadeOnDelete();
            $table->primary(['media_id', 'tag_id']);
        });

        $this->createIfMissing('vn_languages', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        $this->createIfMissing('vn_item_language', function (Blueprint $table) {
            $table->unsignedBigInteger('media_id');
            $table->unsignedBigInteger('language_id');

            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->foreign('language_id')->references('id')->on('vn_languages')->cascadeOnDelete();
            $table->primary(['media_id', 'language_id']);
        });

        $this->createIfMissing('vn_developers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->nullable()->index();
            $table->timestamps();
        });

        $this->createIfMissing('vn_item_developer', function (Blueprint $table) {
            $table->unsignedBigInteger('media_id');
            $table->unsignedBigInteger('developer_id');

            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->foreign('developer_id')->references('id')->on('vn_developers')->cascadeOnDelete();
            $table->primary(['media_id', 'developer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vn_item_developer');
        Schema::dropIfExists('vn_developers');
        Schema::dropIfExists('vn_item_language');
        Schema::dropIfExists('vn_languages');
        Schema::dropIfExists('vn_item_tag');
        Schema::dropIfExists('vn_tags');
        Schema::dropIfExists('doujin_item_author');
        Schema::dropIfExists('doujin_authors');
        Schema::dropIfExists('anilist_item_author');
        Schema::dropIfExists('anilist_authors');
        Schema::dropIfExists('anilist_item_studio');
        Schema::dropIfExists('anilist_studios');
        Schema::dropIfExists('anilist_item_genre');
        Schema::dropIfExists('anilist_genres');
        Schema::dropIfExists('anilist_item_tag');
        Schema::dropIfExists('anilist_tags');
    }

    private function createIfMissing(string $table, Closure $callback): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $callback);
        }
    }
};
