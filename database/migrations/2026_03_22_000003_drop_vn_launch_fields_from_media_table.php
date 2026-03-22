<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $columns = [];

            foreach (['launch_rel_exe', 'launch_args'] as $column) {
                if (Schema::hasColumn('media', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (!Schema::hasColumn('media', 'launch_rel_exe')) {
                $table->string('launch_rel_exe')->nullable();
            }

            if (!Schema::hasColumn('media', 'launch_args')) {
                $table->text('launch_args')->nullable();
            }
        });
    }
};
