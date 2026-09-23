<?php

namespace App\Services\Monitoring;

class SecurityHeaderAnalyzer
{
    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function analyze(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        $present = [
            'strict_transport_security' => $normalized['strict-transport-security'] ?? null,
            'content_security_policy' => $normalized['content-security-policy'] ?? null,
            'x_frame_options' => $normalized['x-frame-options'] ?? null,
            'x_content_type_options' => $normalized['x-content-type-options'] ?? null,
            'referrer_policy' => $normalized['referrer-policy'] ?? null,
            'permissions_policy' => $normalized['permissions-policy'] ?? ($normalized['feature-policy'] ?? null),
            'x_xss_protection' => $normalized['x-xss-protection'] ?? null,
        ];

        $weights = [
            'strict_transport_security' => 25,
            'content_security_policy' => 25,
            'x_frame_options' => 15,
            'x_content_type_options' => 15,
            'referrer_policy' => 10,
            'permissions_policy' => 10,
        ];

        $score = 0;

        foreach ($weights as $key => $points) {
            if (filled($present[$key] ?? null)) {
                $score += $points;
            }
        }

        return [
            'headers' => $present,
            'score' => $score,
            'grade' => match (true) {
                $score >= 90 => 'A',
                $score >= 70 => 'B',
                $score >= 50 => 'C',
                $score >= 30 => 'D',
                default => 'F',
            },
        ];
    }
}
