<?php
declare(strict_types=1);

namespace DroboWebui;

/**
 * Readers and writers that need root. Only the helper (run through sudo) uses this class.
 */
final class Privileged
{
    private const PMU = '/usr/local/sbin/drobo-pmu';
    private const FAILED_BAYS = '/run/drobo-leds/failed-bays';
    private const PMU_TTL = 30;      // drobo-pmu takes about 1.4 s per command, so cache it
    private const SMART_TTL = 120;

    /** Disk present / power for bays 0-4, from the backplane expander lines. */
    public static function bays(): array
    {
        $failed = array_map('trim', @file(self::FAILED_BAYS) ?: []);
        $bays = [];
        for ($n = 0; $n < 5; $n++) {
            $detect = Shell::out(['gpioget', '--numeric', "bay$n-detect"], 3);
            $power = Shell::out(['gpioget', '--numeric', '--as-is', "bay$n-power"], 3);
            $bays[] = [
                'bay' => $n,
                'present' => $detect === '0',      // detect is active-low
                'powered' => $power === '1',
                'failed' => in_array((string) $n, $failed, true),
                'known' => $detect !== null,
            ];
        }
        return $bays;
    }

    public static function setBayPower(int $bay, bool $on): void
    {
        [$code, , $err] = Shell::run(['gpioset', '-t0', "bay$bay-power=" . ($on ? '1' : '0')], null, 5);
        if ($code !== 0) {
            throw new \RuntimeException("gpioset failed: $err");
        }
    }

    /** Fan target/actual (01-31) and PMU version, cached for PMU_TTL seconds. */
    public static function pmu(bool $fresh = false): array
    {
        return self::cached('pmu', $fresh ? 0 : self::PMU_TTL, static function (): array {
            [, $out] = Shell::run([self::PMU, 'V', 'FT.', 'FA.'], null, 15);
            $r = ['version' => null, 'fanTarget' => null, 'fanActual' => null];
            foreach (explode("\n", $out) as $l) {
                if (preg_match('/^V\s+->\s+(\S+)/', $l, $m)) {
                    $r['version'] = $m[1];
                } elseif (preg_match('/^FT\.\s+->\s+FT(\d\d)!/', $l, $m)) {
                    $r['fanTarget'] = (int) $m[1];
                } elseif (preg_match('/^FA\.\s+->\s+FA(\d\d)!/', $l, $m)) {
                    $r['fanActual'] = (int) $m[1];
                }
            }
            return $r;
        });
    }

    /**
     * SMART summary per disk on a SATA slot, cached. Uses "-n standby" so a sleeping disk
     * is reported as asleep instead of being spun up.
     */
    public static function smart(bool $fresh = false): array
    {
        return self::cached('smart', $fresh ? 0 : self::SMART_TTL, static function (): array {
            $out = [];
            foreach (Hardware::disks() as $d) {
                if ($d['slot'] === 'usb') {
                    continue;
                }
                [$code, $json] = Shell::run(['smartctl', '-j', '-n', 'standby', '-H', '-i', '-A', '/dev/' . $d['name']], null, 30);
                $j = json_decode($json, true) ?: [];
                $msgs = strtoupper(implode(' ', array_column($j['smartctl']['messages'] ?? [], 'string')));
                if (str_contains($msgs, 'STANDBY') || str_contains($msgs, 'SLEEP')) {
                    $out[$d['name']] = ['available' => true, 'asleep' => true];
                    continue;
                }
                if (!isset($j['smart_status']) && !isset($j['model_name'])) {
                    $out[$d['name']] = ['available' => false];
                    continue;
                }
                $attrs = [];
                foreach ($j['ata_smart_attributes']['table'] ?? [] as $a) {
                    $attrs[$a['id']] = $a['raw']['value'] ?? null;
                }
                $out[$d['name']] = [
                    'available' => true,
                    'asleep' => false,
                    'passed' => $j['smart_status']['passed'] ?? null,
                    'model' => $j['model_name'] ?? null,
                    'firmware' => $j['firmware_version'] ?? null,
                    'capacity' => $j['user_capacity']['bytes'] ?? null,
                    'rpm' => $j['rotation_rate'] ?? null,
                    'link' => $j['interface_speed']['current']['string'] ?? null,
                    'temperature' => $j['temperature']['current'] ?? null,
                    'powerOnHours' => $j['power_on_time']['hours'] ?? null,
                    'reallocated' => $attrs[5] ?? null,
                    'pending' => $attrs[197] ?? null,
                    'uncorrectable' => $attrs[198] ?? null,
                ];
            }
            return $out;
        });
    }

    /**
     * Update KEY=value lines in a shell defaults file (/etc/default/...), keeping everything
     * else, and a .bak of the old file. Values here are always integers or validated paths.
     */
    public static function setDefaults(string $file, array $vars): void
    {
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
        $done = [];
        foreach ($lines as &$l) {
            if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)=/', $l, $m) && array_key_exists($m[1], $vars)) {
                $l = $m[1] . '=' . self::shellQuote((string) $vars[$m[1]]);
                $done[$m[1]] = true;
            }
        }
        unset($l);
        foreach ($vars as $k => $v) {
            if (!isset($done[$k])) {
                $lines[] = $k . '=' . self::shellQuote((string) $v);
            }
        }
        if (is_file($file)) {
            copy($file, "$file.bak");
        }
        file_put_contents("$file.new", "# Edited by drobo-webui on " . date('Y-m-d H:i') . "; the previous copy is $file.bak\n"
            . implode("\n", array_filter($lines, static fn($l) => !str_starts_with($l, '# Edited by drobo-webui'))) . "\n");
        chmod("$file.new", 0644);
        rename("$file.new", $file);
    }

    /** KEY=value pairs from a shell defaults file (simple assignments only). */
    public static function readDefaults(string $file): array
    {
        $out = [];
        foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)=(.*)$/', $l, $m)) {
                $out[$m[1]] = trim(preg_replace('/\s+#.*$/', '', $m[2]), "\"' ");
            }
        }
        return $out;
    }

    public static function shellQuote(string $v): string
    {
        return preg_match('/^[A-Za-z0-9_.\/:-]+$/', $v) ? $v : "'" . str_replace("'", "'\\''", $v) . "'";
    }

    private static function cached(string $name, int $ttl, callable $fn): array
    {
        @mkdir(RUN_DIR, 0755, true);
        $file = RUN_DIR . "/$name.json";
        $lock = fopen("$file.lock", 'c');
        flock($lock, LOCK_EX);  // two page loads at once wait for one reading instead of both talking to the PMU
        try {
            if ($ttl > 0 && is_file($file) && time() - filemtime($file) < $ttl) {
                $data = json_decode((string) file_get_contents($file), true);
                if (is_array($data)) {
                    return $data;
                }
            }
            $data = $fn();
            file_put_contents("$file.new", json_encode($data));
            rename("$file.new", $file);
            return $data;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
