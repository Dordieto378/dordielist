<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (!Schema::hasColumn('media', 'list_start_date')) {
                $table->date('list_start_date')->nullable()->after('start_date');
            }

            if (!Schema::hasColumn('media', 'list_end_date')) {
                $table->date('list_end_date')->nullable()->after('list_start_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $columns = [];

            foreach (['list_start_date', 'list_end_date'] as $column) {
                if (Schema::hasColumn('media', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
