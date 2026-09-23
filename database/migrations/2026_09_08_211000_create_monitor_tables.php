<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_targets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url', 2048);
            $table->string('method', 10)->default('GET');
            $table->unsignedSmallInteger('interval_seconds')->default(15);
            $table->unsignedSmallInteger('timeout_seconds')->default(15);
            $table->json('expected_status')->nullable();
            $table->string('expected_keyword')->nullable();
            $table->boolean('verify_ssl')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('last_ok')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->unsignedInteger('last_total_ms')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('monitor_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_target_id')->constrained()->cascadeOnDelete();
            $table->boolean('ok');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('total_ms')->nullable();
            $table->string('availability_reason', 64)->nullable();
            $table->json('payload');
            $table->timestamp('checked_at');
            $table->index(['monitor_target_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_checks');
        Schema::dropIfExists('monitor_targets');
    }
};
