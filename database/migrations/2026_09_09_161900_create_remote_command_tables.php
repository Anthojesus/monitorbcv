<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_commands', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('label');
            $table->string('command', 255);
            $table->string('kind', 16);
            $table->unsignedSmallInteger('timeout_seconds')->default(15);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('monitor_target_servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_target_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('username');
            $table->text('password');
            $table->string('host_fingerprint', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('monitor_target_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_target_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_command_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['monitor_target_id', 'monitor_command_id'], 'mtc_target_command_unique');
        });

        Schema::create('monitor_command_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_target_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_command_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);
            $table->string('command_label');
            $table->string('command_snapshot', 255);
            $table->string('host');
            $table->string('username');
            $table->string('status', 24);
            $table->smallInteger('exit_code')->nullable();
            $table->text('stdout')->nullable();
            $table->text('stderr')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
        });

        $now = now();

        $commands = [
            ['hostname', 'Nombre del host', 'hostname', 'query', 10, 10],
            ['uname', 'Sistema operativo', 'uname -a', 'query', 10, 20],
            ['uptime', 'Uptime del servidor', 'uptime', 'query', 10, 30],
            ['whoami', 'Usuario SSH actual', 'whoami', 'query', 10, 40],
            ['df', 'Espacio en disco', 'df -h', 'query', 15, 50],
            ['free', 'Memoria', 'free -m', 'query', 10, 60],
            ['nginx-active', 'Nginx activo', 'systemctl is-active nginx', 'query', 10, 70],
            ['nginx-status', 'Estado de Nginx', 'systemctl status nginx', 'query', 15, 80],
            ['tomee-status', 'Estado de TomEE', 'systemctl status tomee', 'query', 15, 90],
            ['php-fpm-status', 'Estado de PHP-FPM', 'systemctl status php-fpm', 'query', 15, 100],
            ['nginx-logs', 'Últimos logs de Nginx', 'journalctl -u nginx -n 80 --no-pager', 'query', 20, 110],
            ['tomee-logs', 'Últimos logs de TomEE', 'journalctl -u tomee -n 80 --no-pager', 'query', 20, 120],
            ['nginx-reload', 'Recargar Nginx', 'systemctl reload nginx', 'change', 20, 200],
            ['nginx-restart', 'Reiniciar Nginx', 'systemctl restart nginx', 'change', 25, 210],
            ['tomee-restart', 'Reiniciar TomEE', 'systemctl restart tomee', 'change', 30, 220],
            ['php-fpm-restart', 'Reiniciar PHP-FPM', 'systemctl restart php-fpm', 'change', 25, 230],
        ];

        foreach ($commands as [$slug, $label, $command, $kind, $timeout, $sort]) {
            DB::table('monitor_commands')->insert([
                'slug' => $slug,
                'label' => $label,
                'command' => $command,
                'kind' => $kind,
                'timeout_seconds' => $timeout,
                'sort_order' => $sort,
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $queryIds = DB::table('monitor_commands')->where('kind', 'query')->pluck('id');
        $targetIds = DB::table('monitor_targets')->pluck('id');

        foreach ($targetIds as $targetId) {
            foreach ($queryIds as $commandId) {
                DB::table('monitor_target_commands')->insert([
                    'monitor_target_id' => $targetId,
                    'monitor_command_id' => $commandId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_command_runs');
        Schema::dropIfExists('monitor_target_commands');
        Schema::dropIfExists('monitor_target_servers');
        Schema::dropIfExists('monitor_commands');
    }
};
