<?php

namespace App\Services\RemoteCommand;

class SshConnection
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
        public readonly ?string $hostFingerprint = null,
    ) {}
}
