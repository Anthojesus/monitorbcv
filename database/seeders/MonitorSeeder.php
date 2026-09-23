<?php

namespace Database\Seeders;

use App\Models\MonitorTarget;
use Illuminate\Database\Seeder;

class MonitorSeeder extends Seeder
{
    public function run(): void
    {
        $sites = [
            ['name' => 'Laravel', 'url' => 'https://laravel.com', 'probe_origin' => MonitorTarget::ORIGIN_EXTERNAL],
            ['name' => 'GitHub', 'url' => 'https://github.com', 'probe_origin' => MonitorTarget::ORIGIN_EXTERNAL],
            ['name' => 'Example', 'url' => 'https://example.com', 'probe_origin' => MonitorTarget::ORIGIN_EXTERNAL],
            ['name' => 'Transfer-Cert', 'url' => 'https://transfer.cert.extra.bcv.org.ve/accounts/login/?next=/'],
            [
                'name' => 'Biblioteca Extra',
                'url' => 'https://biblioteca.extra.bcv.org.ve/cgi-win/be_alex.cgi?nombrebd=bep5',
                'expected_status' => [MonitorTarget::ANY_HTTP_STATUS],
            ],
            ['name' => 'RRHH Extra', 'url' => 'https://enlineaconrh.intra.bcv.org.ve/web/intranet/start'],
            ['name' => 'BCV Oficial', 'url' => 'https://www.bcv.org.ve/', 'probe_origin' => MonitorTarget::ORIGIN_EXTERNAL],
            [
                'name' => 'Biblioteca Extra 2',
                'url' => 'https://biblioteca.extra.bcv.org.ve/',
                'expected_status' => [MonitorTarget::ANY_HTTP_STATUS],
            ],
            ['name' => 'Control Framo', 'url' => 'https://controlframo.extra.bcv.org.ve/'],
            ['name' => 'Control Framo Intra', 'url' => 'https://controlframo.intra.bcv.org.ve/'],
            ['name' => 'Beneficios Escolares Extra', 'url' => 'https://beneficiosescolares.extra.bcv.org.ve/'],
            ['name' => 'GroupWise BCV', 'url' => 'https://webmail.bcv.org.ve/gw/webacc/'],
            ['name' => 'OCCC EXTRA', 'url' => 'https://occc.extra.bcv.org.ve/occc-pw/vista/sistema/login.html'],
            [
                'name' => 'OCPP Health',
                'url' => 'https://ocppws.extra.bcv.org.ve/OCPP/health',
                'kind' => 'health',
                'expected_status' => [200],
            ],
        ];

        foreach ($sites as $site) {
            $kind = $site['kind'] ?? 'http';

            MonitorTarget::query()->updateOrCreate(
                ['url' => $site['url']],
                [
                    'name' => $site['name'],
                    'kind' => $kind,
                    'probe_origin' => $site['probe_origin'] ?? MonitorTarget::guessOrigin($site['url']),
                    'method' => 'GET',
                    'interval_seconds' => 5,
                    'timeout_seconds' => 15,
                    'expected_status' => $site['expected_status'] ?? MonitorTarget::defaultExpectedStatus(),
                    'is_enabled' => true,
                    'verify_ssl' => true,
                ],
            );
        }
    }
}
