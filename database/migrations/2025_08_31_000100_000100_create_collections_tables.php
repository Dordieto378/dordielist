<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('collections', function (Blueprint $t) {
            $t->increments('id');
            $t->boolean('is_system')->default(false);
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('collection_items', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('collection_id');
            // Keep your current polymorphic style for now
            $t->string('item_type', 50);
            $t->string('item_id', 50);
            $t->string('thumbnail_url')->nullable();
            $t->string('title')->nullable();
            $t->timestamps();

            $t->foreign('collection_id')
              ->references('id')->on('collections')
              ->cascadeOnDelete()->cascadeOnUpdate();

            // Add guard rail to stop duplicates (missing in your dump)
            $t->unique(['collection_id', 'item_type', 'item_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('collection_items');
        Schema::dropIfExists('collections');
    }
};
