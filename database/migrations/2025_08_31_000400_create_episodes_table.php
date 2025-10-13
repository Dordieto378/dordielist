<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('episodes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('media_id');
            $t->string('media_type');
            $t->unsignedInteger('episode_number');
            $t->string('file_path');
            $t->timestamps();

            $t->index(['media_type', 'media_id'], 'media_episodes_media_type_media_id_index');
        });
    }
    public function down(): void {
        Schema::dropIfExists('episodes');
    }
};
