<?php

namespace App\Services\RemoteCommand;

use phpseclib3\Exception\UnableToConnectException;
use phpseclib3\Net\SSH2;
use Throwable;

class PhpSecLibSshClient implements SshClient
{
    public function exec(SshConnection $connection, string $command, int $timeoutSeconds): SshExecResult
    {
        $ssh = new SSH2($connection->host, $connection->port, $timeoutSeconds);
        $ssh->setTimeout($timeoutSeconds);

        try {
            $publicKey = $ssh->getServerPublicHostKey();

            if ($publicKey === false) {
                throw new RemoteCommandException('No se pudo leer la huella SSH del servidor.', 'denied');
            }

            $fingerprint = hash('sha256', $publicKey);

            if ($connection->hostFingerprint !== null && ! hash_equals($connection->hostFingerprint, $fingerprint)) {
                throw new RemoteCommandException(
                    'La huella SSH del servidor cambió. Verifique el host y vuelva a guardar el sitio para aceptarla.',
                    'denied',
                );
            }

            if (! $ssh->login($connection->username, $connection->password)) {
                throw new RemoteCommandException('Usuario o clave SSH rechazados.', 'auth_failed');
            }

            $stdout = $this->runRemoteCommand($ssh, $connection, $command, $timeoutSeconds);

            if ($ssh->isTimeout()) {
                throw new RemoteCommandException('El comando superó el tiempo máximo de espera.', 'timeout');
            }

            $stderr = $ssh->getStdError();
            $exitCode = $ssh->getExitStatus();

            return new SshExecResult(
                exitCode: is_int($exitCode) ? $exitCode : 1,
                stdout: $stdout,
                stderr: is_string($stderr) ? SudoCommand::scrubOutput($stderr, $connection->password) : '',
                hostFingerprint: $fingerprint,
            );
        } catch (RemoteCommandException $exception) {
            throw $exception;
        } catch (UnableToConnectException) {
            throw new RemoteCommandException('No se pudo conectar al puerto SSH del servidor.', 'failed');
        } catch (Throwable) {
            throw new RemoteCommandException('Falló la sesión SSH con el servidor.', 'failed');
        } finally {
            $ssh->disconnect();
        }
    }

    private function runRemoteCommand(SSH2 $ssh, SshConnection $connection, string $command, int $timeoutSeconds): string
    {
        $remote = SudoCommand::forExec($command);

        if (! SudoCommand::usesSudo($command)) {
            $stdout = $ssh->exec($remote);

            return is_string($stdout) ? $stdout : '';
        }

        $ssh->enablePTY();
        $ssh->exec($remote);
        $ssh->write($connection->password."\n");

        $output = '';

        while (true) {
            $chunk = $ssh->read('', SSH2::READ_NEXT);

            if ($chunk === true || $chunk === false) {
                break;
            }

            $output .= is_string($chunk) ? $chunk : '';

            if ($ssh->isTimeout()) {
                break;
            }
        }

        $ssh->disablePTY();
        $ssh->setTimeout($timeoutSeconds);

        return SudoCommand::scrubOutput($output, $connection->password);
    }
}
