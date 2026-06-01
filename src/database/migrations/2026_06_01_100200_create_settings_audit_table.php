<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings_audit', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 128);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(['setting_key', 'changed_at'], 'idx_key_time');
            $table->index('changed_by_user_id', 'idx_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings_audit');
    }
};
