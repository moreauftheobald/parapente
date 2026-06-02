<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_apis', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->string('base_url', 500)->nullable();
            $table->string('auth_type', 20)->default('none');
            $table->text('api_key')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->unsignedInteger('daily_quota')->nullable();
            $table->unsignedInteger('requests_today')->default(0);
            $table->date('requests_counter_date')->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('last_error_at')->nullable();
            $table->dateTime('last_success_at')->nullable();
            $table->boolean('active')->default(false);
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_apis');
    }
};
