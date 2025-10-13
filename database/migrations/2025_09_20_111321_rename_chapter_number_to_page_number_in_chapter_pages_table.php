<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapter_pages', function (Blueprint $table) {
            $table->renameColumn('chapter_number', 'page_number');
        });
    }

    public function down(): void
    {
        Schema::table('chapter_pages', function (Blueprint $table) {
            $table->renameColumn('page_number', 'chapter_number');
        });
    }
};
