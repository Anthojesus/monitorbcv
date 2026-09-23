<?php

namespace Tests\Unit;

use App\Services\Monitoring\SecurityHeaderAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityHeaderAnalyzerTest extends TestCase
{
    #[Test]
    public function it_scores_complete_security_headers(): void
    {
        $analysis = app(SecurityHeaderAnalyzer::class)->analyze([
            'Strict-Transport-Security' => 'max-age=31536000',
            'Content-Security-Policy' => "default-src 'self'",
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'geolocation=()',
        ]);

        $this->assertSame(100, $analysis['score']);
        $this->assertSame('A', $analysis['grade']);
    }

    #[Test]
    public function it_gives_a_failing_grade_without_headers(): void
    {
        $analysis = app(SecurityHeaderAnalyzer::class)->analyze([]);

        $this->assertSame(0, $analysis['score']);
        $this->assertSame('F', $analysis['grade']);
    }
}
