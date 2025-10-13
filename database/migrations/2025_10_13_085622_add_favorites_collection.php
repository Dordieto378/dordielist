<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('collections')->updateOrInsert(
            ['name' => 'Favorites'],
            ['is_system' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        DB::table('collections')->where('name', 'Favorites')->delete();
    }
};
