<?php
declare(strict_types=1);

namespace DroboWebui;

/**
 * Runs programs without a shell: argv is passed straight to execve, so nothing in it is
 * ever interpreted as shell syntax.
 */
final class Shell
{
    /**
     * @param list<string> $argv
     * @return array{0:int,1:string,2:string} exit code, stdout, stderr
     */
    public static function run(array $argv, ?string $stdin = null, int $timeout = 20): array
    {
        $argv = array_merge(['timeout', (string) $timeout], array_map('strval', $argv));
        $pipes = [];
        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return [127, '', 'could not start ' . $argv[2]];
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, $err];
    }

    /** stdout of a command, trimmed, or null if it failed. */
    public static function out(array $argv, int $timeout = 10): ?string
    {
        [$code, $out] = self::run($argv, null, $timeout);
        return $code === 0 ? trim($out) : null;
    }
}
