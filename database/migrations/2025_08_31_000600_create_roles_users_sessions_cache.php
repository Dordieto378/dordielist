<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('roles', function (Blueprint $t) {
            $t->bigIncrements('role_id');
            $t->string('role');
        });

        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('user_id');
            $t->string('username');
            $t->string('email');
            $t->string('password');
            $t->date('email_verified_at')->nullable();
            $t->enum('status', ['active','not_active','banned'])->default('active');
            $t->unsignedBigInteger('role_id');
            $t->text('two_factor_secret')->nullable();
            $t->text('two_factor_recovery_codes')->nullable();
            $t->timestamp('two_factor_confirmed_at')->useCurrent();
            $t->boolean('two_factor_confirmed')->default(false);

            $t->foreign('role_id')
              ->references('role_id')->on('roles')
              ->cascadeOnDelete();
        });

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        Schema::create('cache', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->integer('expiration');
        });
    }
    public function down(): void {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');
    }
};
