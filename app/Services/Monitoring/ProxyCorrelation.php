<?php

namespace App\Services\Monitoring;

use App\Models\MonitorTarget;

class ProxyCorrelation
{
    /**
     * @param  array<string, mixed>  $siteCondition
     * @param  array<string, mixed>|null  $proxyCondition
     * @return array<string, mixed>|null
     */
    public function diagnose(MonitorTarget $site, ?MonitorTarget $proxy, array $siteCondition, ?array $proxyCondition): ?array
    {
        if ($site->isProxy() || $proxy === null || $proxyCondition === null) {
            return null;
        }

        $siteKey = (string) ($siteCondition['key'] ?? '');
        $proxyKey = (string) ($proxyCondition['key'] ?? '');

        if (in_array($siteKey, ['pending', ''], true) || in_array($proxyKey, ['pending', ''], true)) {
            return null;
        }

        $row = match (true) {
            $proxyKey === 'unmonitored' && in_array($siteKey, ['down', 'degraded'], true) => [
                'key' => 'proxy_unmonitored',
                'tone' => 'amber',
                'title' => 'No se puede culpar al proxy',
                'detail' => 'La app tiene incidencia, pero el proxy no se está sondeando. Encienda su sonda o revise el destino del proxy.',
            ],
            in_array($proxyKey, ['down', 'unmonitored'], true) && in_array($siteKey, ['down', 'degraded'], true) => [
                'key' => 'proxy_down',
                'tone' => 'rose',
                'title' => 'Falla del proxy',
                'detail' => 'El proxy Linux no responde. La aplicación detrás puede estar bien; valide Nginx/HAProxy y los puertos 80/443.',
            ],
            $proxyKey === 'ok' && $siteKey === 'down' => [
                'key' => 'app_behind_proxy',
                'tone' => 'rose',
                'title' => 'Falla de la aplicación',
                'detail' => 'El proxy está UP. El corte está en la app, el backend o el upstream, no en el proxy que la contiene.',
            ],
            $proxyKey === 'degraded' && $siteKey === 'down' => [
                'key' => 'app_behind_proxy',
                'tone' => 'rose',
                'title' => 'Falla de la aplicación',
                'detail' => 'El proxy responde (degradado). Priorice el upstream / la aplicación, no un corte total del proxy.',
            ],
            $proxyKey === 'down' && $siteKey === 'ok' => [
                'key' => 'proxy_path_down',
                'tone' => 'amber',
                'title' => 'Proxy caído, app aún UP',
                'detail' => 'La URL de la app responde, pero el destino del proxy está DOWN. Revise la URL de comprobación del proxy o un listener distinto.',
            ],
            default => null,
        };

        if ($row === null) {
            return null;
        }

        return $row + [
            'proxy_id' => $proxy->id,
            'proxy_name' => $proxy->name,
            'proxy_ok' => $proxy->last_ok,
        ];
    }
}
