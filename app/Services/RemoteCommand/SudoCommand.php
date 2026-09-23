<?php

namespace App\Services\RemoteCommand;

class SudoCommand
{
    public static function usesSudo(string $command): bool
    {
        return preg_match('/^sudo(\s|$)/', trim($command)) === 1;
    }

    public static function forExec(string $command): string
    {
        $command = trim($command);

        if (! self::usesSudo($command)) {
            return $command;
        }

        $inner = preg_replace('/^sudo(?:\s+-S)?(?:\s+-p\s+\S+)?\s+/', '', $command);

        return 'sudo -S -p "" '.(is_string($inner) && $inner !== '' ? $inner : $command);
    }

    public static function scrubOutput(string $output, string $password): string
    {
        if ($password !== '') {
            $output = str_replace([$password, $password."\n"], '', $output);
        }

        $cleaned = preg_replace('/^\[sudo\] password for .+:\s*/m', '', $output);

        return is_string($cleaned) ? $cleaned : $output;
    }
}
