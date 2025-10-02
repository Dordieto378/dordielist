<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('chapter_pages', function (Blueprint $table) {
            // Rename chapter_number → page_number
            $table->renameColumn('chapter_number', 'page_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chapter_pages', function (Blueprint $table) {
            // Revert back if needed
            $table->renameColumn('page_number', 'chapter_number');
        });
    }
};
