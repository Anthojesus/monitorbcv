<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_targets', function (Blueprint $table) {
            $table->foreignId('proxy_target_id')
                ->nullable()
                ->after('probe_origin')
                ->constrained('monitor_targets')
                ->nullOnDelete();
        });

        $now = now();
        $extras = [
            ['nginx-test', 'Probar config de Nginx', 'nginx -t', 'query', 15, 85],
            ['ss-http', 'Puertos 80 y 443 en escucha', 'ss -lntp', 'query', 10, 86],
            ['haproxy-active', 'HAProxy activo', 'systemctl is-active haproxy', 'query', 10, 87],
            ['haproxy-status', 'Estado de HAProxy', 'systemctl status haproxy', 'query', 15, 88],
            ['haproxy-logs', 'Últimos logs de HAProxy', 'journalctl -u haproxy -n 80 --no-pager', 'query', 20, 89],
            ['haproxy-reload', 'Recargar HAProxy', 'systemctl reload haproxy', 'change', 20, 240],
            ['haproxy-restart', 'Reiniciar HAProxy', 'systemctl restart haproxy', 'change', 25, 241],
        ];

        foreach ($extras as [$slug, $label, $command, $kind, $timeout, $sort]) {
            if (DB::table('monitor_commands')->where('slug', $slug)->exists()) {
                continue;
            }

            DB::table('monitor_commands')->insert([
                'slug' => $slug,
                'label' => $label,
                'command' => $command,
                'kind' => $kind,
                'timeout_seconds' => $timeout,
                'sort_order' => $sort,
                'is_enabled' => true,
                'is_custom' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('monitor_targets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proxy_target_id');
        });
    }
};
