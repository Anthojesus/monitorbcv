<?php

namespace App\Services\RemoteCommand;

interface SshClient
{
    public function exec(SshConnection $connection, string $command, int $timeoutSeconds): SshExecResult;
}
