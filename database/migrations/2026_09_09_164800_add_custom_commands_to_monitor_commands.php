<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_commands', function (Blueprint $table) {
            $table->boolean('is_custom')->default(false)->after('is_enabled');
            $table->foreignId('owner_target_id')
                ->nullable()
                ->after('is_custom')
                ->constrained('monitor_targets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('monitor_commands', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_target_id');
            $table->dropColumn('is_custom');
        });
    }
};
