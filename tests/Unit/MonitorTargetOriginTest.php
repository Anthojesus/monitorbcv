<?php

namespace Tests\Unit;

use App\Models\MonitorTarget;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MonitorTargetOriginTest extends TestCase
{
    #[Test]
    public function guess_origin_marks_intranet_and_private_ips_as_internal(): void
    {
        $this->assertSame(MonitorTarget::ORIGIN_INTERNAL, MonitorTarget::guessOrigin('https://rrhh.intra.bcv.org.ve/'));
        $this->assertSame(MonitorTarget::ORIGIN_INTERNAL, MonitorTarget::guessOrigin('https://biblioteca.extra.bcv.org.ve/'));
        $this->assertSame(MonitorTarget::ORIGIN_INTERNAL, MonitorTarget::guessOrigin('https://172.16.10.4/'));
        $this->assertSame(MonitorTarget::ORIGIN_INTERNAL, MonitorTarget::guessOrigin('https://www.bcv.org.ve/'));
    }

    #[Test]
    public function guess_origin_marks_public_ips_as_external(): void
    {
        $this->assertSame(MonitorTarget::ORIGIN_EXTERNAL, MonitorTarget::guessOrigin('https://8.8.8.8/'));
    }
}
