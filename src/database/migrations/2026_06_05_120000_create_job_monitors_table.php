<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_monitors', function (Blueprint $table) {
            $table->id();
            $table->string('job_class');
            $table->string('job_group', 20)->index();
            $table->enum('status', ['running', 'success', 'failed'])->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->text('error_trace')->nullable();

            $table->index(['job_group', 'started_at']);
            $table->index(['job_class', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_monitors');
    }
};
