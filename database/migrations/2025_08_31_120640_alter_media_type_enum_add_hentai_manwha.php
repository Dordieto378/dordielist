<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            ALTER TABLE media
            MODIFY COLUMN type
            ENUM('anime','hentai','manga','manwha','doujin','vn')
            NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE media
            MODIFY COLUMN type
            ENUM('anime','manga','doujin','vn')
            NOT NULL
        ");
    }
};
