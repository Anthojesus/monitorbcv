<?php

namespace App\Services\RemoteCommand;

class SshExecResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly string $hostFingerprint,
    ) {}
}
