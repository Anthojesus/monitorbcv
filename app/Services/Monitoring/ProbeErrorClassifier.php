<?php

namespace App\Services\Monitoring;

class ProbeErrorClassifier
{
    /**
     * @var list<string>
     */
    private const ISSUER_MARKERS = [
        'unable to get local issuer certificate',
        'self-signed certificate',
        'self signed certificate',
        'unable to verify the first certificate',
        'certificate signed by unknown authority',
    ];

    /**
     * @param  array<string, mixed>|null  $error
     */
    public static function reason(?array $error): ?string
    {
        if ($error === null) {
            return null;
        }

        $message = strtolower((string) ($error['message'] ?? ''));
        $type = strtolower((string) ($error['type'] ?? ''));

        return match (true) {
            str_contains($message, 'resolv') || str_contains($message, 'dns') || str_contains($message, 'getaddrinfo') => 'dns',
            str_contains($message, 'allow-list') || str_contains($message, 'curl handlers') => 'transport',
            str_contains($message, 'ssl') || str_contains($message, 'certificate') || str_contains($type, 'ssl') => 'tls',
            str_contains($message, 'timed out') || str_contains($message, 'timeout') => 'timeout',
            str_contains($message, 'connect') => 'tcp',
            default => 'transport',
        };
    }

    /**
     * @param  array<string, mixed>|null  $error
     */
    public static function isUntrustedIssuer(?array $error): bool
    {
        if ($error === null) {
            return false;
        }

        $message = strtolower((string) ($error['message'] ?? ''));

        if (str_contains($message, 'hostname') || str_contains($message, 'altname') || str_contains($message, 'expired')) {
            return false;
        }

        foreach (self::ISSUER_MARKERS as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }
}
