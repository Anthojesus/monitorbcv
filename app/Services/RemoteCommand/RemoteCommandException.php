<?php

namespace App\Services\RemoteCommand;

use RuntimeException;

class RemoteCommandException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $status = 'failed',
    ) {
        parent::__construct($message);
    }
}
