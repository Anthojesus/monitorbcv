<?php

namespace Tests\Unit;

use App\Services\RemoteCommand\SudoCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SudoCommandTest extends TestCase
{
    #[Test]
    public function it_wraps_sudo_commands_to_read_the_password_from_stdin(): void
    {
        $this->assertFalse(SudoCommand::usesSudo('tail -n 50 /var/log/nginx/access.log'));
        $this->assertTrue(SudoCommand::usesSudo('sudo tail -n 50 /var/log/nginx/access.log'));
        $this->assertSame(
            'sudo -S -p "" tail -n 50 /var/log/nginx/entregaguardia-access.log',
            SudoCommand::forExec('sudo tail -n 50 /var/log/nginx/entregaguardia-access.log'),
        );
        $this->assertSame(
            'hostname',
            SudoCommand::forExec('hostname'),
        );
        $this->assertSame(
            "lines\n",
            SudoCommand::scrubOutput("[sudo] password for ops: secret\nlines\n", 'secret'),
        );
    }
}
