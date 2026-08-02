<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dordiewatch_media', function (Blueprint $table) {
            $table->foreignId('media_id')
                ->primary()
                ->constrained('media')
                ->cascadeOnDelete();
            $table->timestamp('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dordiewatch_media');
    }
};
