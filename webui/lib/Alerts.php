<?php
declare(strict_types=1);

namespace DroboWebui;

/**
 * Health checks, shared by the dashboard (to show problems) and the alert timer (to mail them).
 * Each problem has a stable key, so the timer mails a problem once when it starts and once
 * when it clears instead of every five minutes.
 */
final class Alerts
{
    public const LEVELS = ['info' => 0, 'warning' => 1, 'critical' => 2];

    /** 12 V input less 5%: a supply that sags below this under load is too small. */
    private const INPUT_VOLTS_MIN = 11.4;
    /** One Li-ion cell: under 3.6 V it's below about 15% charge, under 3.4 V nearly empty. */
    private const BATTERY_VOLTS_LOW = 3.6;
    private const BATTERY_VOLTS_CRITICAL = 3.4;

    /**
     * @return list<array{key:string,level:string,message:string}>
     */
    public static function evaluate(array $cfg, array $bays, array $smart, ?array $pmu = null): array
    {
        $a = $cfg['alerts'];
        $out = [];
        $add = static function (string $key, string $level, string $message) use (&$out): void {
            $out[] = ['key' => $key, 'level' => $level, 'message' => $message];
        };

        $disks = Hardware::disks();
        $bySlot = [];
        foreach ($disks as $d) {
            $bySlot[$d['slot']] = $d;
        }
        foreach ($bays as $b) {
            $n = $b['bay'];
            if ($b['failed']) {
                $add("bay$n-failed", 'critical', "Bay $n is marked as failed.");
            }
            if ($b['present'] && $b['powered'] && !isset($bySlot["bay$n"])) {
                $add("bay$n-nolink", 'warning', "Bay $n has a powered disk, but Linux doesn't see it.");
            }
            if ($b['present'] && !$b['powered']) {
                $add("bay$n-off", 'info', "Bay $n has a disk that is powered off.");
            }
        }

        $temps = Hardware::temperatures();
        foreach ($disks as $d) {
            $s = $smart[$d['name']] ?? null;
            $where = self::where($d);
            if ($s && ($s['available'] ?? false) && !($s['asleep'] ?? false)) {
                if (($s['passed'] ?? true) === false) {
                    $add("smart-{$d['slot']}", 'critical', "$where failed its SMART health check. Back it up and replace it.");
                }
                foreach (['reallocated' => 'reallocated', 'pending' => 'pending', 'uncorrectable' => 'uncorrectable'] as $k => $word) {
                    if (($s[$k] ?? 0) > 0) {
                        $add("$k-{$d['slot']}", 'warning', "$where has {$s[$k]} $word sectors.");
                    }
                }
            }
            $t = $temps['disks'][$d['name']] ?? ($s['temperature'] ?? null);
            if ($t !== null && $t >= $a['diskTempMax']) {
                $add("temp-{$d['slot']}", 'warning', "$where is at {$t} °C (limit {$a['diskTempMax']} °C).");
            }
        }
        if ($temps['soc'] !== null && $temps['soc'] >= $a['socTempMax']) {
            $add('temp-soc', 'warning', "The processor is at {$temps['soc']} °C (limit {$a['socTempMax']} °C).");
        }

        // Power input and backup battery, from the PMU (see Privileged::pmu()).
        if ($pmu !== null) {
            if ($pmu['onBattery'] ?? false) {
                $add('power-onbattery', 'critical', 'The power input has failed: running on the backup battery.');
            }
            $in = $pmu['inputVolts'] ?? null;
            if ($in !== null && $in < self::INPUT_VOLTS_MIN && !($pmu['onBattery'] ?? false)) {
                $add('power-input-low', 'warning', sprintf('The power input is at %.2f V (expected about 12 V). '
                    . 'The power supply may be too small for the disks.', $in));
            }
            $bv = $pmu['batteryVolts'] ?? null;
            if ($bv !== null && $bv < self::BATTERY_VOLTS_LOW) {
                $add('battery-low', $bv < self::BATTERY_VOLTS_CRITICAL ? 'critical' : 'warning', sprintf(
                    'The backup battery is at %.2f V. It may not last through a clean shutdown after a power cut. '
                    . 'If it stays this low after a few hours on power, replace the 18650 cell.', $bv));
            }
        }

        foreach (Hardware::filesystems() as $fs) {
            if ($fs['total'] <= 0 || $fs['readOnly']) {
                continue;
            }
            $pct = (int) round($fs['used'] * 100 / $fs['total']);
            if ($pct >= $a['capacityPct']) {
                $level = $pct >= 95 ? 'critical' : 'warning';
                $add('full-' . $fs['mount'], $level, "{$fs['mount']} is $pct% full.");
            }
        }

        foreach (Hardware::arrays() as $md) {
            if ($md['degraded']) {
                $add("md-{$md['name']}", 'critical', "RAID array {$md['name']} is degraded [{$md['health']}].");
            } elseif ($md['sync']) {
                $add("md-{$md['name']}-sync", 'info', "RAID array {$md['name']}: {$md['sync']}.");
            }
        }

        $state = trim(Shell::run(['systemctl', 'is-system-running'])[1]);
        if ($state === 'degraded') {
            [, $failed] = Shell::run(['systemctl', '--failed', '--plain', '--no-legend', '--no-pager']);
            $units = array_filter(array_map(static fn($l) => strtok(trim($l), ' '), explode("\n", $failed)));
            $add('units-failed', 'warning', 'Failed services: ' . implode(', ', $units) . '.');
        }

        usort($out, static fn($x, $y) => self::LEVELS[$y['level']] <=> self::LEVELS[$x['level']]);
        return $out;
    }

    public static function where(array $d): string
    {
        return match (true) {
            str_starts_with($d['slot'], 'bay') => 'The disk in bay ' . substr($d['slot'], 3),
            $d['slot'] === 'msata' => 'The mSATA SSD',
            $d['slot'] === 'usb' => 'The USB stick',
            default => "Disk {$d['name']}",
        };
    }

    /**
     * Run from the timer (as root): compare with the last run and mail what changed.
     * @return string summary for the journal
     */
    public static function check(): string
    {
        $cfg = Config::load();
        $now = self::evaluate($cfg, Privileged::bays(), Privileged::smart(), Privileged::pmu());
        $stateFile = VAR_DIR . '/alert-state.json';
        $prev = json_decode((string) @file_get_contents($stateFile), true) ?: [];
        $min = self::LEVELS[$cfg['alerts']['minLevel']] ?? 1;

        $nowKeys = [];
        $new = [];
        foreach ($now as $p) {
            $nowKeys[$p['key']] = $p;
            if (!isset($prev[$p['key']]) && self::LEVELS[$p['level']] >= $min) {
                $new[] = $p;
            }
        }
        $cleared = array_values(array_filter($prev, static fn($p, $k) => !isset($nowKeys[$k])
            && self::LEVELS[$p['level']] >= $min, ARRAY_FILTER_USE_BOTH));

        $sent = 'no mail';
        if (($new || $cleared) && $cfg['alerts']['enabled']) {
            $host = gethostname() ?: 'drobo';
            $worst = $new ? $new[0]['level'] : 'info';
            $subject = $new ? ucfirst($worst) . ": $host: " . $new[0]['message'] : "$host: problem cleared";
            $body = '';
            if ($new) {
                $body .= "New:\n" . implode('', array_map(static fn($p) => "  [{$p['level']}] {$p['message']}\n", $new)) . "\n";
            }
            if ($cleared) {
                $body .= "Cleared:\n" . implode('', array_map(static fn($p) => "  {$p['message']}\n", $cleared)) . "\n";
            }
            if ($now) {
                $body .= "Current problems:\n" . implode('', array_map(static fn($p) => "  [{$p['level']}] {$p['message']}\n", $now));
            } else {
                $body .= "Everything looks fine now.\n";
            }
            $body .= "\n-- \nSent by drobo-webui on $host.\n";
            Smtp::send($cfg['alerts'], $subject, $body);
            $sent = 'mailed ' . count($new) . ' new, ' . count($cleared) . ' cleared';
        }
        @mkdir(VAR_DIR, 0755, true);
        file_put_contents($stateFile, json_encode($nowKeys));
        return count($now) . " problem(s), $sent";
    }
}
