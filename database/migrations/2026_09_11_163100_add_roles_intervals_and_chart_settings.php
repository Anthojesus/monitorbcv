<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('monitor')->after('email');
            $table->boolean('can_run_commands')->default(false)->after('role');
        });

        DB::table('users')->update([
            'role' => 'admin',
            'can_run_commands' => true,
        ]);

        Schema::create('monitor_interval_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('seconds')->unique();
            $table->timestamps();
        });

        Schema::create('monitor_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->timestamps();
        });

        $now = now();
        $intervals = [];

        foreach ([1, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55, 60] as $seconds) {
            $intervals[] = [
                'seconds' => $seconds,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('monitor_interval_options')->insert($intervals);
        DB::table('monitor_settings')->insert([
            'key' => 'chart_history_max_days',
            'value' => '30',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_settings');
        Schema::dropIfExists('monitor_interval_options');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'can_run_commands']);
        });
    }
};
