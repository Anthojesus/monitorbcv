<?php

namespace Tests\Unit;

use App\Models\MonitorCheck;
use App\Services\Monitoring\MonitorCopy;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MonitorCopyTest extends TestCase
{
    #[Test]
    public function it_explains_reliability_when_the_site_recovered(): void
    {
        $recent = Collection::make([
            (new MonitorCheck)->forceFill(['ok' => true, 'availability_reason' => 'ok', 'checked_at' => now()]),
            (new MonitorCheck)->forceFill(['ok' => false, 'availability_reason' => 'tls', 'checked_at' => now()->subMinutes(5)]),
            (new MonitorCheck)->forceFill(['ok' => false, 'availability_reason' => 'tls', 'checked_at' => now()->subMinutes(10)]),
            (new MonitorCheck)->forceFill(['ok' => false, 'availability_reason' => 'tls', 'checked_at' => now()->subMinutes(15)]),
        ]);

        $reliability = MonitorCopy::reliability($recent, true);

        $this->assertSame(25, $reliability['percent']);
        $this->assertSame(1, $reliability['ok_count']);
        $this->assertSame(4, $reliability['sample_size']);
        $this->assertSame('Intermitente', $reliability['label']);
        $this->assertStringContainsString('Ahora UP', $reliability['hint']);
        $this->assertStringContainsString('1 de 4 OK', $reliability['hint']);
        $this->assertSame('Fallo de certificado TLS', MonitorCopy::reason('tls'));
    }

    #[Test]
    public function reliability_ignores_probe_unavailable_samples(): void
    {
        $recent = Collection::make([
            (new MonitorCheck)->forceFill([
                'ok' => false,
                'availability_reason' => 'probe_unavailable',
                'payload' => ['probe_unavailable' => true],
                'checked_at' => now(),
            ]),
            (new MonitorCheck)->forceFill([
                'ok' => true,
                'availability_reason' => 'ok',
                'payload' => ['probe_unavailable' => false],
                'checked_at' => now()->subMinutes(2),
            ]),
        ]);

        $reliability = MonitorCopy::reliability($recent, true);

        $this->assertSame(100, $reliability['percent']);
        $this->assertSame(1, $reliability['sample_size']);
    }

    #[Test]
    public function it_explains_up_with_http_500_as_reachable_not_healthy(): void
    {
        $this->assertSame('No se está monitoreando', MonitorCopy::reason('probe_unavailable'));
        $this->assertStringContainsString('servicio Interior', (string) MonitorCopy::reasonHint('probe_unavailable', null, 'internal'));
        $this->assertStringContainsString('no es una falla del portal', mb_strtolower((string) MonitorCopy::reasonHint('probe_unavailable', null, 'external')));
        $this->assertSame('Interior', MonitorCopy::originLabel('internal'));
        $this->assertSame('Exterior', MonitorCopy::originLabel('external'));
        $this->assertSame('El servidor responde', MonitorCopy::reason('http_reachable'));

        $hint = MonitorCopy::reasonHint('http_reachable', 500);

        $this->assertNotNull($hint);
        $this->assertStringContainsString('HTTP 500', $hint);
        $this->assertStringContainsString('no es un corte de red', $hint);
        $this->assertStringContainsString('aplicación', $hint);
        $this->assertStringContainsString('Solo códigos HTTP esperados', $hint);
    }

    #[Test]
    public function it_renders_python_certificate_issuer_arrays_as_text(): void
    {
        $issuer = [
            [['countryName', 'GB']],
            [['organizationName', 'Sectigo Limited']],
            [['commonName', 'Sectigo Public Server Authentication CA OV R36']],
        ];

        $this->assertSame(
            'GB, Sectigo Limited, Sectigo Public Server Authentication CA OV R36',
            MonitorCopy::text($issuer),
        );
        $this->assertSame('Sectigo Limited', MonitorCopy::text('Sectigo Limited'));
    }

    #[Test]
    public function it_adds_dns_and_tcp_as_network_latency(): void
    {
        $network = MonitorCopy::network([
            'timings_ms' => ['dns' => 12, 'tcp' => 188, 'ttfb' => 400, 'total' => 900],
        ]);

        $this->assertSame(200, $network['ms']);
        $this->assertSame('Red aceptable', $network['label']);
        $this->assertStringContainsString('DNS 12 ms', $network['hint']);
        $this->assertStringContainsString('TCP 188 ms', $network['hint']);
    }

    #[Test]
    public function it_classifies_web_servers_from_probe_headers(): void
    {
        $this->assertSame('Nginx', MonitorCopy::webServer(['http' => ['server' => 'nginx/1.24.0']])['family']);
        $this->assertSame('Apache2', MonitorCopy::webServer(['http' => ['server' => 'Apache/2.4.57 (Unix)']])['family']);
        $this->assertSame('IIS', MonitorCopy::webServer(['http' => ['headers' => ['server' => 'Microsoft-IIS/10.0']]])['family']);
        $this->assertSame('Apache Tomcat', MonitorCopy::webServer(['http' => ['server' => 'Apache-Coyote/1.1']])['family']);
        $this->assertSame('Sin dato', MonitorCopy::webServer(['http' => []])['family']);
        $this->assertSame('F5 BIG-IP', MonitorCopy::webServer(['http' => ['server' => 'BigIP']])['family']);
    }
}
