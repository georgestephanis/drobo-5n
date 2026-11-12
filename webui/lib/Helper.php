<?php
declare(strict_types=1);

namespace DroboWebui;

use RuntimeException;

/** Web-side client for the root helper: sudo -n HELPER VERB ARGS, JSON back. */
final class Helper
{
    /**
     * @param list<string> $args
     * @throws RuntimeException with the helper's message when it refuses
     */
    public static function call(string $verb, array $args = [], ?array $stdin = null, int $timeout = 60): array
    {
        $argv = array_merge(['sudo', '-n', HELPER, $verb], $args);
        [$code, $out, $err] = Shell::run($argv, $stdin === null ? null : json_encode($stdin), $timeout);
        $j = json_decode($out, true);
        if (!is_array($j)) {
            throw new RuntimeException('The helper did not answer (' . ($code === 124 ? 'timed out' : trim($err ?: "exit $code")) . ')');
        }
        if (empty($j['ok'])) {
            throw new RuntimeException($j['error'] ?? 'The helper refused');
        }
        unset($j['ok']);
        return $j;
    }
}
