<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('episodes', function (Blueprint $t) {
            $t->bigIncrements('id');
            // Keep legacy fields so your code doesn’t break immediately:
            $t->unsignedBigInteger('media_id'); // legacy id you used
            $t->string('media_type');           // 'animes' etc.
            $t->unsignedInteger('episode_number');
            $t->string('file_path');
            $t->timestamps();

            // Index similar to your dump (media_type, media_id)
            $t->index(['media_type', 'media_id'], 'media_episodes_media_type_media_id_index');
        });
    }
    public function down(): void {
        Schema::dropIfExists('episodes');
    }
};
