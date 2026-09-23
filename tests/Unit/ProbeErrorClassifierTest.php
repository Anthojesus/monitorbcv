<?php

namespace Tests\Unit;

use App\Services\Monitoring\ProbeErrorClassifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProbeErrorClassifierTest extends TestCase
{
    #[Test]
    public function it_classifies_corporate_ca_failures_as_tls(): void
    {
        $error = [
            'type' => 'SSLCertVerificationError',
            'message' => '[SSL: CERTIFICATE_VERIFY_FAILED] certificate verify failed: unable to get local issuer certificate (_ssl.c:1010)',
        ];

        $this->assertSame('tls', ProbeErrorClassifier::reason($error));
        $this->assertTrue(ProbeErrorClassifier::isUntrustedIssuer($error));
    }

    #[Test]
    public function it_does_not_treat_expired_or_hostname_errors_as_issuer_fallback(): void
    {
        $this->assertFalse(ProbeErrorClassifier::isUntrustedIssuer([
            'message' => 'certificate verify failed: certificate has expired',
        ]));

        $this->assertFalse(ProbeErrorClassifier::isUntrustedIssuer([
            'message' => 'SSL: certificate subject name does not match hostname',
        ]));
    }

    #[Test]
    public function it_does_not_treat_guzzle_curl_allow_list_as_tls(): void
    {
        $this->assertSame('transport', ProbeErrorClassifier::reason([
            'message' => 'Passing CURLOPT_SSL_OPTIONS (216) in the "curl" request option is not supported because it is outside the built-in cURL handlers\' allow-list.',
        ]));
    }
}
