<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDoujinsTable extends Migration
{
    public function up()
    {
        Schema::create('doujins', function (Blueprint $table) {
            $table->id();
            
            // e.g. “18master” or whatever the author folder is
            $table->string('author_name');

            // e.g. “Tomodachi no Okaa-san wa Mukuchi”
            $table->string('doujin_name');

            // e.g. “18master/Tomodachi no Okaa-san wa Mukuchi”
            $table->string('folder_path')->unique();
            
            // e.g. “doujins/18master/Tomodachi no Okaa-san wa Mukuchi/1.png”
            $table->string('cover_url');

            // If you want to store any extra metadata right now, add it here:
            // $table->unsignedInteger('page_count')->nullable();
            // $table->dateTime('last_read_at')->nullable();
            // etc.

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('doujins');
    }
}
