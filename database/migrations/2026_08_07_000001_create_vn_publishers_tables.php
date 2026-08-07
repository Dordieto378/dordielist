<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vn_publishers')) {
            Schema::create('vn_publishers', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('language', 32)->default('');
                $table->string('slug')->nullable()->index();
                $table->unsignedBigInteger('source_id')->nullable()->index();
                $table->timestamps();

                $table->unique(['name', 'language']);
            });
        }

        if (! Schema::hasTable('vn_item_publisher')) {
            Schema::create('vn_item_publisher', function (Blueprint $table) {
                $table->unsignedBigInteger('media_id');
                $table->unsignedBigInteger('publisher_id');

                $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
                $table->foreign('publisher_id')->references('id')->on('vn_publishers')->cascadeOnDelete();
                $table->primary(['media_id', 'publisher_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vn_item_publisher');
        Schema::dropIfExists('vn_publishers');
    }
};
